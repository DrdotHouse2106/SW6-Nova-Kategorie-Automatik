<?php declare(strict_types=1);

namespace Nova\KategorieAutomatik\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nova\KategorieAutomatik\NovaKategorieAutomatik;
use Shopware\Core\Content\Product\DataAbstractionLayer\ProductIndexer;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexingMessage;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Ordnet Artikel fest den Kategorien zu, deren Teileart-Code (Zusatzfeld an der Kategorie) zum Code
 * in den gewählten Eigenschaften passt („621 Bremsbacken“ → Code 621).
 *
 * Verwaltet werden ausschließlich Kategorien MIT Code: Zuordnungen zu allen anderen Kategorien
 * (alte Bäume, Federkugel, von Hand gesetzte) bleiben unangetastet.
 */
class CategoryAssigner
{
    public const CONFIG = 'NovaKategorieAutomatik.config.';

    /** @var array<string, list<string>>|null Code → Kategorie-IDs, pro Anfrage zwischengespeichert */
    private ?array $codeMap = null;

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly Connection $connection,
        private readonly EntityRepository $productCategoryRepository,
        private readonly EntityRepository $propertyGroupRepository,
        private readonly ProductIndexer $productIndexer
    ) {
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

        $codes = $this->loadProductCodes($productIds);
        $current = $this->loadCurrentAssignments($productIds, $managed);

        $create = [];
        $delete = [];
        $changed = [];
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
                }
            }
        }

        if ($create !== []) {
            $this->productCategoryRepository->create($create, $context);
        }
        if ($delete !== []) {
            $this->productCategoryRepository->delete($delete, $context);
        }

        if ($changed !== []) {
            // Kategoriebaum des Artikels (für Listen und übergeordnete Kategorien) neu aufbauen
            $this->productIndexer->handle(new EntityIndexingMessage(array_keys($changed), null, $context));
        }

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
            'SELECT LOWER(HEX(id)) FROM product WHERE version_id = :live AND parent_id IS NULL ORDER BY auto_increment LIMIT ' . $limit . ' OFFSET ' . $offset,
            ['live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );
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
     * Codes je Artikel aus den gewählten Eigenschaften (führende Ziffern des Werts, alle Sprachen).
     *
     * @param list<string> $productIds
     *
     * @return array<string, list<string>>
     */
    private function loadProductCodes(array $productIds): array
    {
        $codes = [];

        // 1. Zusatzfelder am Produkt (z. B. von weclapp als Artikelattribut übertragen) – im Shop unsichtbar
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(product_id)) AS product_id, custom_fields
             FROM product_translation
             WHERE product_id IN (:ids) AND product_version_id = :live AND custom_fields IS NOT NULL',
            ['ids' => Uuid::fromHexToBytesList($productIds), 'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            ['ids' => ArrayParameterType::BINARY]
        );
        foreach ($rows as $row) {
            $fields = json_decode((string) $row['custom_fields'], true);
            if (!\is_array($fields)) {
                continue;
            }
            foreach (NovaKategorieAutomatik::PRODUCT_FIELDS as $field) {
                foreach (self::extractCodes($fields[$field] ?? null) as $code) {
                    $codes[$row['product_id']][$code] = $code;
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
                'ids' => Uuid::fromHexToBytesList($productIds),
                'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'groups' => Uuid::fromHexToBytesList($this->getGroupIds()),
            ],
            ['ids' => ArrayParameterType::BINARY, 'groups' => ArrayParameterType::BINARY]
        );

        foreach ($rows as $row) {
            foreach (self::extractCodes($row['name']) as $code) {
                $codes[$row['product_id']][$code] = $code;
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
