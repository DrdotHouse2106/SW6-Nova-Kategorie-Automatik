<?php declare(strict_types=1);

namespace Nova\KategorieAutomatik\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nova\KategorieAutomatik\Message\RebuildMessage;
use Nova\KategorieAutomatik\NovaKategorieAutomatik;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Shopware\Core\Content\Product\DataAbstractionLayer\ProductIndexer;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexingMessage;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Ordnet Artikel fest den Kategorien zu, deren Teileart-Code (Zusatzfeld an der Kategorie) zum Code
 * in den gewählten Eigenschaften passt („621 Bremsbacken“ → Code 621).
 *
 * Verwaltet werden ausschließlich Kategorien MIT Code: Zuordnungen zu allen anderen Kategorien
 * (alte Bäume, von Hand gesetzte) bleiben unangetastet. Varianten werden über ihren Hauptartikel
 * ausgewertet und erben dessen Kategorien.
 */
class CategoryAssigner
{
    public const CONFIG = 'NovaKategorieAutomatik.config.';

    /** Nur diese Teile des Produkt-Indexers betreffen Kategorien; Preise, Bestand, Suche usw. bleiben unberührt */
    private const INDEXER_SKIP = [
        'product.stock',
        'product.variant-listing',
        'product.child-count',
        'product.cheapest-price',
        'product.rating-average',
        'product.search-keyword',
        'product.states',
    ];

    /** Höchstzahl der im Protokoll namentlich genannten Artikel */
    private const LOG_LIMIT = 20;

    /** @var array<string, list<string>>|null Code → Kategorie-IDs, pro Anfrage zwischengespeichert */
    private ?array $codeMap = null;

    private LoggerInterface $logger;

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly Connection $connection,
        private readonly EntityRepository $productCategoryRepository,
        private readonly EntityRepository $propertyGroupRepository,
        private readonly ProductIndexer $productIndexer
    ) {
        $this->logger = new NullLogger();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Ins Shopware-Protokoll schreiben und im Ereignisprotokoll der Administration
     * (Einstellungen → System → Ereignisprotokoll) ablegen – dort auch im Produktivmodus sichtbar.
     *
     * @param array<string, mixed> $context
     */
    public function protocol(string $level, string $message, array $context = []): void
    {
        $message = 'Nova Kategorie-Automatik: ' . $message;
        $this->logger->log($level, $message, $context);

        try {
            $this->connection->insert('log_entry', [
                'id' => Uuid::randomBytes(),
                'message' => mb_substr($message, 0, 255),
                'level' => $level === LogLevel::WARNING ? 300 : 200,
                'channel' => 'nova_kategorie_automatik',
                'context' => json_encode($context, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
                'extra' => '{}',
                'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        } catch (\Throwable) {
            // Protokoll darf die Zuordnung nie verhindern
        }
    }

    public function rebuildLock(): RebuildLock
    {
        return new RebuildLock($this->connection);
    }

    /**
     * Neuzuordnung aller Artikel starten. Läuft bereits eine mit denselben Codes, passiert nichts;
     * sonst löst die neue Kette eine laufende ab.
     *
     * @return bool ob eine neue Kette gestartet wurde
     */
    public function startRebuild(MessageBusInterface $bus, bool $force = false): bool
    {
        $this->resetCache();
        $lock = $this->rebuildLock();
        $token = $lock->start($lock->fingerprint($this->getGroupIds()), $force);
        if ($token === null) {
            $this->protocol(LogLevel::INFO, 'Neuzuordnung läuft bereits mit denselben Codes, keine zweite gestartet');

            return false;
        }

        $bus->dispatch(new RebuildMessage(0, $token));
        $this->protocol(LogLevel::INFO, $force ? 'Neuzuordnung nach Plugin-Update gestartet' : 'Neuzuordnung gestartet');

        return true;
    }

    public function isActive(): bool
    {
        // Codes kommen aus den Zusatzfeldern am Produkt (immer) und optional aus Eigenschaften
        return $this->config->getBool(self::CONFIG . 'active');
    }

    /**
     * @return list<string>
     */
    public function getGroupIds(): array
    {
        $ids = $this->config->get(self::CONFIG . 'propertyGroupIds');

        return \is_array($ids) ? array_values(array_filter($ids, static fn ($id): bool => \is_string($id) && Uuid::isValid($id))) : [];
    }

    /**
     * Zuordnung für die angegebenen Artikel neu berechnen.
     *
     * @param list<string> $productIds
     *
     * @return int Anzahl geänderter Artikel
     */
    public function assign(array $productIds, Context $context): int
    {
        $productIds = array_values(array_unique(array_filter($productIds, static fn ($id): bool => \is_string($id) && Uuid::isValid($id))));
        if ($productIds === [] || !$this->isActive()) {
            return 0;
        }

        $codeMap = $this->getCodeMap();
        if ($codeMap === []) {
            return 0;
        }
        $managed = array_values(array_unique(array_merge(...array_values($codeMap))));

        // Varianten auf den Hauptartikel abbilden; ihre Codes zählen für den Hauptartikel
        $productIds = $this->mainProductIds($productIds);
        if ($productIds === []) {
            return 0;
        }
        $children = $this->loadChildren($productIds);

        $codes = $this->loadProductCodes($productIds, $children);
        $current = $this->loadCurrentAssignments([...$productIds, ...array_keys($children)], $managed);

        $childrenOf = [];
        foreach ($children as $childId => $parentId) {
            $childrenOf[$parentId][] = $childId;
        }

        $create = [];
        $delete = [];
        $changed = [];
        $removedWithoutCode = [];
        foreach ($productIds as $productId) {
            $wanted = [];
            foreach ($codes[$productId] ?? [] as $productCode) {
                foreach ($codeMap as $categoryCode => $categoryIds) {
                    if (str_starts_with($productCode, (string) $categoryCode)) {
                        foreach ($categoryIds as $categoryId) {
                            $wanted[$categoryId] = true;
                        }
                    }
                }
            }

            $have = $current[$productId] ?? [];
            foreach (array_keys($wanted) as $categoryId) {
                if (!isset($have[$categoryId])) {
                    $create[] = ['productId' => $productId, 'categoryId' => $categoryId];
                    $changed[$productId] = true;
                }
            }
            foreach (array_keys($have) as $categoryId) {
                if (!isset($wanted[$categoryId])) {
                    $delete[] = ['productId' => $productId, 'categoryId' => $categoryId];
                    $changed[$productId] = true;
                    if (($codes[$productId] ?? []) === []) {
                        $removedWithoutCode[$productId] = true;
                    }
                }
            }

            // Eigene Zuordnungen von Varianten (aus Versionen vor 1.2.0) nur abbauen, nie neu anlegen
            foreach ($childrenOf[$productId] ?? [] as $childId) {
                foreach (array_keys($current[$childId] ?? []) as $categoryId) {
                    if (!isset($wanted[$categoryId])) {
                        $delete[] = ['productId' => $childId, 'categoryId' => $categoryId];
                        $changed[$productId] = true;
                    }
                }
            }
        }

        if ($create === [] && $delete === []) {
            return 0;
        }

        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($create, $delete, $changed, $childrenOf): void {
            if ($create !== []) {
                $this->productCategoryRepository->create($create, $context);
            }
            if ($delete !== []) {
                $this->productCategoryRepository->delete($delete, $context);
            }

            // Nur den Kategoriebaum (Listen, übergeordnete Kategorien) der betroffenen Artikel samt Varianten neu aufbauen
            $ids = array_keys($changed);
            foreach ($ids as $productId) {
                array_push($ids, ...($childrenOf[$productId] ?? []));
            }
            $message = new EntityIndexingMessage(array_values(array_unique($ids)), null, $context);
            $message->setSkip(self::INDEXER_SKIP);
            $this->productIndexer->handle($message);
        });

        $this->logChanges($create, $delete, array_keys($removedWithoutCode));

        return \count($changed);
    }

    /**
     * Gewählte Eigenschaften im Shop verbergen (kein Filter, nicht auf der Produktseite).
     */
    public function hideProperties(Context $context): void
    {
        if (!$this->config->getBool(self::CONFIG . 'hideProperties')) {
            return;
        }

        $payload = array_map(static fn (string $id): array => [
            'id' => $id,
            'filterable' => false,
            'visibleOnProductDetailPage' => false,
        ], $this->getGroupIds());

        if ($payload !== []) {
            $this->propertyGroupRepository->update($payload, $context);
        }
    }

    /**
     * Haupt-Artikel (keine Varianten) für die Neuzuordnung in Paketen.
     *
     * @return list<string>
     */
    public function fetchProductIds(int $offset, int $limit): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(id)) FROM product WHERE version_id = :live AND parent_id IS NULL ORDER BY auto_increment LIMIT :limit OFFSET :offset',
            ['live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]
        );
    }

    /**
     * Haupt-Artikel nach auto_increment (Keyset statt Offset, damit Löschungen nichts überspringen).
     *
     * @return array{ids: list<string>, last: int}
     */
    public function fetchProductBatch(int $afterAutoIncrement, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, auto_increment FROM product
             WHERE version_id = :live AND parent_id IS NULL AND auto_increment > :after
             ORDER BY auto_increment LIMIT :limit',
            ['live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'after' => $afterAutoIncrement, 'limit' => $limit],
            ['after' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER]
        );

        $last = $afterAutoIncrement;
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (string) $row['id'];
            $last = max($last, (int) $row['auto_increment']);
        }

        return ['ids' => $ids, 'last' => $last];
    }

    public function resetCache(): void
    {
        $this->codeMap = null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function getCodeMap(): array
    {
        if ($this->codeMap !== null) {
            return $this->codeMap;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(category_id)) AS id, JSON_UNQUOTE(JSON_EXTRACT(custom_fields, :path)) AS code
             FROM category_translation
             WHERE category_version_id = :live AND JSON_EXTRACT(custom_fields, :path) IS NOT NULL',
            ['path' => '$.' . NovaKategorieAutomatik::CODE_FIELD, 'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );

        $map = [];
        foreach ($rows as $row) {
            foreach (preg_split('/[\s,;]+/', (string) $row['code']) ?: [] as $code) {
                if (preg_match('/^\d{1,4}$/', $code)) {
                    $map[$code][$row['id']] = $row['id'];
                }
            }
        }

        return $this->codeMap = array_map(static fn (array $ids): array => array_values($ids), $map);
    }

    /**
     * Codes je Hauptartikel aus Zusatzfeldern und gewählten Eigenschaften (führende Ziffern, alle Sprachen),
     * einschließlich der Werte seiner Varianten.
     *
     * @param list<string> $productIds
     * @param array<string, string> $children Variante → Hauptartikel
     *
     * @return array<string, list<string>>
     */
    private function loadProductCodes(array $productIds, array $children): array
    {
        $codes = [];
        $allIds = [...$productIds, ...array_keys($children)];

        // 1. Zusatzfelder am Produkt (z. B. von weclapp als Artikelattribut übertragen) – im Shop unsichtbar
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(product_id)) AS product_id, custom_fields
             FROM product_translation
             WHERE product_id IN (:ids) AND product_version_id = :live AND custom_fields IS NOT NULL',
            ['ids' => Uuid::fromHexToBytesList($allIds), 'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            ['ids' => ArrayParameterType::BINARY]
        );
        foreach ($rows as $row) {
            $owner = $children[$row['product_id']] ?? $row['product_id'];
            $fields = json_decode((string) $row['custom_fields'], true);
            if (!\is_array($fields)) {
                continue;
            }
            foreach (NovaKategorieAutomatik::PRODUCT_FIELDS as $field) {
                foreach (self::extractCodes($fields[$field] ?? null) as $code) {
                    $codes[$owner][$code] = $code;
                }
            }
        }

        // 2. Eigenschaften (optional)
        if ($this->getGroupIds() === []) {
            return array_map(static fn (array $c): array => array_values($c), $codes);
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(pp.product_id)) AS product_id, t.name
             FROM product_property pp
             INNER JOIN property_group_option o ON o.id = pp.property_group_option_id
             INNER JOIN property_group_option_translation t ON t.property_group_option_id = o.id
             WHERE pp.product_id IN (:ids) AND pp.product_version_id = :live AND o.property_group_id IN (:groups)',
            [
                'ids' => Uuid::fromHexToBytesList($allIds),
                'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'groups' => Uuid::fromHexToBytesList($this->getGroupIds()),
            ],
            ['ids' => ArrayParameterType::BINARY, 'groups' => ArrayParameterType::BINARY]
        );

        foreach ($rows as $row) {
            $owner = $children[$row['product_id']] ?? $row['product_id'];
            foreach (self::extractCodes($row['name']) as $code) {
                $codes[$owner][$code] = $code;
            }
        }

        return array_map(static fn (array $c): array => array_values($c), $codes);
    }

    /**
     * Codes aus einem Feldwert: „621 Bremsbacken“, „621, 632“, „621 Bremsbacken|632 Radbremszylinder“ oder Liste.
     *
     * @return list<string>
     */
    public static function extractCodes(mixed $value): array
    {
        if (\is_array($value)) {
            $items = $value;
        } elseif (\is_string($value) || \is_int($value)) {
            $value = (string) $value;
            $decoded = str_starts_with(ltrim($value), '[') ? json_decode($value, true) : null;
            $items = \is_array($decoded) ? $decoded : (preg_split('/[,;|\n]+/', $value) ?: []);
        } else {
            return [];
        }

        $codes = [];
        foreach ($items as $item) {
            if ((\is_string($item) || \is_int($item)) && preg_match('/^\s*(\d{1,4})(?!\d)/', (string) $item, $match)) {
                $codes[$match[1]] = $match[1];
            }
        }

        return array_values($codes);
    }

    /**
     * @param list<string> $productIds
     *
     * @return list<string>
     */
    private function mainProductIds(array $productIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, LOWER(HEX(parent_id)) AS parent_id FROM product WHERE id IN (:ids) AND version_id = :live',
            ['ids' => Uuid::fromHexToBytesList($productIds), 'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            ['ids' => ArrayParameterType::BINARY]
        );

        $main = [];
        foreach ($rows as $row) {
            $id = (string) ($row['parent_id'] ?: $row['id']);
            $main[$id] = $id;
        }

        return array_values($main);
    }

    /**
     * @param list<string> $productIds
     *
     * @return array<string, string> Variante → Hauptartikel
     */
    private function loadChildren(array $productIds): array
    {
        return $this->connection->fetchAllKeyValue(
            'SELECT LOWER(HEX(id)), LOWER(HEX(parent_id)) FROM product WHERE parent_id IN (:ids) AND version_id = :live',
            ['ids' => Uuid::fromHexToBytesList($productIds), 'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            ['ids' => ArrayParameterType::BINARY]
        );
    }

    /**
     * Zuordnungen protokollieren; Entfernungen bei Artikeln ganz ohne Code als Warnung
     * (z. B. vom ERP vorübergehend geleerte Zusatzfelder).
     *
     * @param list<array{productId: string, categoryId: string}> $create
     * @param list<array{productId: string, categoryId: string}> $delete
     * @param list<string> $withoutCode
     */
    private function logChanges(array $create, array $delete, array $withoutCode): void
    {
        if ($withoutCode !== []) {
            $this->protocol(LogLevel::WARNING, 'Artikel ohne Teileart-Code aus Kategorien entfernt', [
                'anzahl' => \count($withoutCode),
                'artikel' => array_values($this->productNumbers(\array_slice($withoutCode, 0, self::LOG_LIMIT))),
            ]);
        }

        if ($delete !== []) {
            $this->protocol(LogLevel::INFO, 'Zuordnungen entfernt', [
                'anzahl' => \count($delete),
                'beispiele' => $this->describe(\array_slice($delete, 0, self::LOG_LIMIT)),
            ]);
        }

        if ($create !== []) {
            $this->logger->info('Nova Kategorie-Automatik: Zuordnungen angelegt', ['anzahl' => \count($create)]);
        }
    }

    /**
     * @param list<array{productId: string, categoryId: string}> $rows
     *
     * @return list<string>
     */
    private function describe(array $rows): array
    {
        $numbers = $this->productNumbers(array_values(array_unique(array_column($rows, 'productId'))));

        return array_map(static fn (array $row): string => ($numbers[$row['productId']] ?? $row['productId']) . ' → Kategorie ' . $row['categoryId'], $rows);
    }

    /**
     * @param list<string> $productIds
     *
     * @return array<string, string>
     */
    private function productNumbers(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return $this->connection->fetchAllKeyValue(
            'SELECT LOWER(HEX(id)), product_number FROM product WHERE id IN (:ids) AND version_id = :live',
            ['ids' => Uuid::fromHexToBytesList($productIds), 'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            ['ids' => ArrayParameterType::BINARY]
        );
    }

    /**
     * @param list<string> $productIds
     * @param list<string> $categoryIds
     *
     * @return array<string, array<string, true>>
     */
    private function loadCurrentAssignments(array $productIds, array $categoryIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(product_id)) AS product_id, LOWER(HEX(category_id)) AS category_id
             FROM product_category
             WHERE product_id IN (:ids) AND product_version_id = :live AND category_id IN (:cats)',
            [
                'ids' => Uuid::fromHexToBytesList($productIds),
                'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'cats' => Uuid::fromHexToBytesList($categoryIds),
            ],
            ['ids' => ArrayParameterType::BINARY, 'cats' => ArrayParameterType::BINARY]
        );

        $current = [];
        foreach ($rows as $row) {
            $current[$row['product_id']][$row['category_id']] = true;
        }

        return $current;
    }
}
