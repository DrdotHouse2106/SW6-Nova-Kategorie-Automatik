<?php declare(strict_types=1);

namespace Nova\KategorieAutomatik;

use Doctrine\DBAL\Connection;
use Nova\KategorieAutomatik\Message\RebuildMessage;
use Nova\KategorieAutomatik\Service\RebuildLock;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Nova Kategorie-Automatik – feste Kategoriezuordnung anhand eines Teileart-Codes.
 */
class NovaKategorieAutomatik extends Plugin
{
    public const CODE_FIELD = 'nova_kategorie_code';
    /** Zusatzfelder am Produkt, die die Codes tragen (z. B. von weclapp befüllt) */
    public const PRODUCT_FIELDS = ['nova_teileart', 'nova_weitere_teilearten'];

    /** Feste IDs, damit Installation und Update dasselbe Zusatzfeld pflegen */
    private const SET_ID = '4f2b8c1d6e3a4b7c9d0e1f2a3b4c5d60';
    private const SET_RELATION_ID = '4f2b8c1d6e3a4b7c9d0e1f2a3b4c5d61';
    private const FIELD_ID = '4f2b8c1d6e3a4b7c9d0e1f2a3b4c5d62';
    private const PRODUCT_SET_ID = '4f2b8c1d6e3a4b7c9d0e1f2a3b4c5d70';
    private const PRODUCT_SET_RELATION_ID = '4f2b8c1d6e3a4b7c9d0e1f2a3b4c5d71';
    private const PRODUCT_FIELD_IDS = ['nova_teileart' => '4f2b8c1d6e3a4b7c9d0e1f2a3b4c5d72', 'nova_weitere_teilearten' => '4f2b8c1d6e3a4b7c9d0e1f2a3b4c5d73'];

    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->upsertCustomFields($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        $this->upsertCustomFields($updateContext->getContext());
    }

    /**
     * Nach jedem Update einmal alle Artikel mit den aktuellen Codes neu zuordnen. Eine neue Kette löst
     * eine laufende (auch aus der Vorversion eingereihte) ab, es läuft also nie mehr als eine.
     */
    public function postUpdate(UpdateContext $updateContext): void
    {
        parent::postUpdate($updateContext);

        try {
            $config = $this->container?->get(SystemConfigService::class);
            $connection = $this->container?->get(Connection::class);
            $bus = $this->container?->has('messenger.default_bus') ? $this->container->get('messenger.default_bus') : $this->container?->get(MessageBusInterface::class);
            if (!$config instanceof SystemConfigService || !$connection instanceof Connection || !$bus instanceof MessageBusInterface) {
                return;
            }
            if (!$updateContext->getPlugin()->isActive() || !$config->getBool('NovaKategorieAutomatik.config.active')) {
                return;
            }

            $lock = new RebuildLock($connection);
            $groupIds = $config->get('NovaKategorieAutomatik.config.propertyGroupIds');
            $groupIds = \is_array($groupIds) ? array_values(array_filter($groupIds, static fn ($id): bool => \is_string($id) && Uuid::isValid($id))) : [];
            $token = $lock->start($lock->fingerprint($groupIds), true);
            if ($token !== null) {
                $bus->dispatch(new RebuildMessage(0, $token));
            }
        } catch (\Throwable) {
            // Das Update selbst darf daran nie scheitern; die Neuzuordnung lässt sich jederzeit per Schalter starten
        }
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Feste Zuordnungen bleiben bestehen (normale Shopware-Daten), nur das Zusatzfeld verschwindet
        $repository = $this->container?->get('custom_field_set.repository');
        if ($repository instanceof EntityRepository) {
            $repository->delete([['id' => self::SET_ID], ['id' => self::PRODUCT_SET_ID]], $uninstallContext->getContext());
        }
    }

    private function upsertCustomFields(Context $context): void
    {
        $repository = $this->container?->get('custom_field_set.repository');
        if (!$repository instanceof EntityRepository) {
            return;
        }

        $repository->upsert([[
            'id' => self::SET_ID,
            'name' => 'nova_kategorie',
            'config' => ['label' => ['de-DE' => 'Kategorie-Automatik (Nova)', 'en-GB' => 'Category automation (Nova)']],
            'relations' => [['id' => self::SET_RELATION_ID, 'entityName' => 'category']],
            'customFields' => [[
                'id' => self::FIELD_ID,
                'name' => self::CODE_FIELD,
                'type' => CustomFieldTypes::TEXT,
                'config' => [
                    'label' => ['de-DE' => 'Teileart-Code', 'en-GB' => 'Part type code'],
                    'helpText' => [
                        'de-DE' => 'Z. B. 621. Artikel, deren Eigenschaft „Teileart“ oder „Weitere Teilearten“ mit diesem Code beginnt, werden dieser Kategorie fest zugeordnet. Mehrere Codes mit Komma trennen. Leer = Kategorie wird nicht automatisch befüllt. Der Code ist im Shop nicht sichtbar.',
                        'en-GB' => 'E.g. 621. Products whose part type property starts with this code are assigned to this category. Separate several codes with commas. Empty = not managed. Not visible in the storefront.',
                    ],
                    'componentName' => 'sw-field',
                    'customFieldType' => 'text',
                    'type' => 'text',
                    'customFieldPosition' => 1,
                ],
            ]],
        ], [
            'id' => self::PRODUCT_SET_ID,
            'name' => 'nova_teileart',
            'config' => ['label' => ['de-DE' => 'Teileart (Nova Kategorie-Automatik)', 'en-GB' => 'Part type (Nova)']],
            'relations' => [['id' => self::PRODUCT_SET_RELATION_ID, 'entityName' => 'product']],
            'customFields' => [[
                'id' => self::PRODUCT_FIELD_IDS['nova_teileart'],
                'name' => 'nova_teileart',
                'type' => CustomFieldTypes::TEXT,
                'config' => [
                    'label' => ['de-DE' => 'Teileart', 'en-GB' => 'Part type'],
                    'helpText' => ['de-DE' => 'Z. B. „621 Bremsbacken“ – maßgeblich sind die führenden Ziffern. Wird von weclapp übertragen. Im Shop nicht sichtbar.', 'en-GB' => 'E.g. "621 Brake shoes" – leading digits count.'],
                    'componentName' => 'sw-field', 'customFieldType' => 'text', 'type' => 'text', 'customFieldPosition' => 1,
                ],
            ], [
                'id' => self::PRODUCT_FIELD_IDS['nova_weitere_teilearten'],
                'name' => 'nova_weitere_teilearten',
                'type' => CustomFieldTypes::TEXT,
                'config' => [
                    'label' => ['de-DE' => 'Weitere Teilearten', 'en-GB' => 'Further part types'],
                    'helpText' => ['de-DE' => 'Mehrere Werte mit Komma, z. B. „632 Radbremszylinder, 654 Dichtungen & Leitungen“. Im Shop nicht sichtbar.', 'en-GB' => 'Several values separated by commas.'],
                    'componentName' => 'sw-field', 'customFieldType' => 'text', 'type' => 'text', 'customFieldPosition' => 2,
                ],
            ]],
        ]], $context);
    }
}
