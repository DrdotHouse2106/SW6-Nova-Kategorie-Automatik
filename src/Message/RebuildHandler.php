<?php declare(strict_types=1);

namespace Nova\KategorieAutomatik\Message;

use Nova\KategorieAutomatik\Service\CategoryAssigner;
use Shopware\Core\Framework\Context;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class RebuildHandler
{
    private const BATCH = 200;

    public function __construct(
        private readonly CategoryAssigner $assigner,
        private readonly MessageBusInterface $bus
    ) {
    }

    public function __invoke(RebuildMessage $message): void
    {
        $ids = $this->assigner->fetchProductIds($message->offset, self::BATCH);
        if ($ids === []) {
            return;
        }

        $this->assigner->assign($ids, Context::createDefaultContext());

        if (\count($ids) === self::BATCH) {
            $this->bus->dispatch(new RebuildMessage($message->offset + self::BATCH));
        }
    }
}
