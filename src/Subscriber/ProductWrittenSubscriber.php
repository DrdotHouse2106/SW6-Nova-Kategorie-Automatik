<?php declare(strict_types=1);

namespace Nova\KategorieAutomatik\Subscriber;

use Nova\KategorieAutomatik\Message\RebuildMessage;
use Nova\KategorieAutomatik\Service\CategoryAssigner;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Löst die Zuordnung aus, wenn Artikel oder ihre Eigenschaften gespeichert werden
 * (weclapp-Abgleich, Administration, Import), und verarbeitet die Schalter der Konfiguration.
 */
class ProductWrittenSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CategoryAssigner $assigner,
        private readonly SystemConfigService $config,
        private readonly MessageBusInterface $bus
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_WRITTEN_EVENT => 'onProductWritten',
            'product_property.written' => 'onProductPropertyWritten',
            'product_property.deleted' => 'onProductPropertyWritten',
            'category.written' => 'onCategoryWritten',
            SystemConfigChangedEvent::class => 'onConfigChanged',
        ];
    }

    public function onProductWritten(EntityWrittenEvent $event): void
    {
        if (!$this->isLive($event->getContext())) {
            return;
        }

        $this->assigner->assign(array_values(array_filter($event->getIds(), 'is_string')), $event->getContext());
    }

    public function onProductPropertyWritten(EntityWrittenEvent $event): void
    {
        if (!$this->isLive($event->getContext())) {
            return;
        }

        $ids = [];
        foreach ($event->getIds() as $key) {
            if (\is_array($key) && isset($key['productId'])) {
                $ids[] = $key['productId'];
            }
        }

        $this->assigner->assign($ids, $event->getContext());
    }

    /** Kategorie-Codes können sich geändert haben → Zwischenspeicher verwerfen */
    public function onCategoryWritten(EntityWrittenEvent $event): void
    {
        $this->assigner->resetCache();
    }

    public function onConfigChanged(SystemConfigChangedEvent $event): void
    {
        if ($event->getSalesChannelId() !== null || !str_starts_with($event->getKey(), CategoryAssigner::CONFIG)) {
            return;
        }

        $key = substr($event->getKey(), \strlen(CategoryAssigner::CONFIG));
        $context = Context::createDefaultContext();

        if (\in_array($key, ['propertyGroupIds', 'hideProperties'], true)) {
            $this->assigner->hideProperties($context);
        }

        if ($key === 'rebuildNow' && $event->getValue() === true) {
            // Schalter sofort zurücksetzen, Neuzuordnung im Hintergrund starten
            $this->config->set(CategoryAssigner::CONFIG . 'rebuildNow', false);
            $this->assigner->resetCache();
            $this->assigner->hideProperties($context);
            $this->bus->dispatch(new RebuildMessage(0));
        }
    }

    private function isLive(Context $context): bool
    {
        return $context->getVersionId() === Defaults::LIVE_VERSION;
    }
}
