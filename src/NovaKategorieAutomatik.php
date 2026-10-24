<?php declare(strict_types=1);

namespace Nova\KategorieAutomatik;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\CustomField\CustomFieldTypes;

/**
 * Nova Kategorie-Automatik – feste Kategoriezuordnung anhand eines Teileart-Codes.
 */
class NovaKategorieAutomatik extends Plugin
{
    public const CODE_FIELD = 'nova_kategorie_code';

    /** Feste IDs, damit Installation und Update dasselbe Zusatzfeld pflegen */
    private const SET_ID = '4f2b8c1d6e3a4b7c9d0e1f2a3b4c5d60';
    private const SET_RELATION_ID = '4f2b8c1d6e3a4b7c9d0e1f2a3b4c5d61';
    private const FIELD_ID = '4f2b8c1d6e3a4b7c9d0e1f2a3b4c5d62';

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

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Feste Zuordnungen bleiben bestehen (normale Shopware-Daten), nur das Zusatzfeld verschwindet
        $repository = $this->container?->get('custom_field_set.repository');
        if ($repository instanceof EntityRepository) {
            $repository->delete([['id' => self::SET_ID]], $uninstallContext->getContext());
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
        ]], $context);
    }
}
