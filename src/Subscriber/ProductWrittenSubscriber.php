<?php declare(strict_types=1);

namespace Nova\KategorieAutomatik\Subscriber;

use Nova\KategorieAutomatik\Service\CategoryAssigner;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
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
            // Einmal je Schreibvorgang (Artikel, Übersetzung und Eigenschaften zusammen), vor Shopwares Indexierung (1000)
            EntityWrittenContainerEvent::class => ['onWritten', 1100],
            'category.written' => 'onCategoryWritten',
            SystemConfigChangedEvent::class => 'onConfigChanged',
        ];
    }

    public function onWritten(EntityWrittenContainerEvent $event): void
    {
        if (!$this->isLive($event->getContext())) {
            return;
        }

        $ids = [];
        foreach ($event->getPrimaryKeys('product') as $id) {
            if (\is_string($id)) {
                $ids[$id] = $id;
            }
        }
        foreach (['product_property', 'product_translation'] as $entity) {
            foreach ($event->getPrimaryKeys($entity) as $key) {
                if (\is_array($key) && isset($key['productId']) && \is_string($key['productId'])) {
                    $ids[$key['productId']] = $key['productId'];
                }
            }
        }

        if ($ids !== []) {
            $this->assigner->assign(array_values($ids), $event->getContext());
        }
    }

    /** Nur noch für den Übergang, solange beim Update der alte Container läuft */
    public function onProductWritten(EntityWrittenEvent $event): void
    {
        if (!$this->isLive($event->getContext())) {
            return;
        }

        $this->assigner->assign(array_values(array_filter($event->getIds(), 'is_string')), $event->getContext());
    }

    /** Nur noch für den Übergang, solange beim Update der alte Container läuft */
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
            // Schalter sofort zurücksetzen, Neuzuordnung im Hintergrund starten (höchstens eine Kette)
            $this->config->set(CategoryAssigner::CONFIG . 'rebuildNow', false);
            $this->assigner->hideProperties($context);
            $this->assigner->startRebuild($this->bus);
        }
    }

    private function isLive(Context $context): bool
    {
        return $context->getVersionId() === Defaults::LIVE_VERSION;
    }
}
