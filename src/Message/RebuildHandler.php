<?php declare(strict_types=1);

namespace Nova\KategorieAutomatik\Message;

use Nova\KategorieAutomatik\Service\CategoryAssigner;
use Psr\Log\LogLevel;
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
        // Der Worker lebt lange: Codes für jedes Paket frisch aus der Datenbank lesen
        $this->assigner->resetCache();
        $lock = $this->assigner->rebuildLock();

        $token = $message->token ?? null;
        if ($token === null) {
            $this->handleLegacy($message, $lock->isRunning());

            return;
        }

        if (!$lock->isCurrent($token)) {
            // Von einer neueren Neuzuordnung abgelöst
            return;
        }

        $batch = $this->assigner->fetchProductBatch($message->afterAutoIncrement, self::BATCH);
        $changed = $batch['ids'] === [] ? 0 : $this->assigner->assign($batch['ids'], Context::createDefaultContext());

        if (\count($batch['ids']) === self::BATCH) {
            $lock->progress($token, \count($batch['ids']), $changed);
            $this->bus->dispatch(new RebuildMessage(0, $token, $batch['last']));

            return;
        }

        $lock->progress($token, \count($batch['ids']), $changed);
        $state = $lock->finish($token);
        $this->assigner->protocol(LogLevel::INFO, 'Neuzuordnung abgeschlossen', [
            'artikel' => $state['processed'] ?? null,
            'geaendert' => $state['changed'] ?? null,
            'dauerSekunden' => isset($state['startedAt']) ? time() - (int) $state['startedAt'] : null,
        ]);
    }

    /**
     * Nachrichten ohne Kennzeichen (vor 1.2.0 eingereiht): verwerfen, wenn eine neue Kette läuft,
     * sonst wie bisher fortsetzen – jetzt mit frischen Codes.
     */
    private function handleLegacy(RebuildMessage $message, bool $chainRunning): void
    {
        if ($chainRunning) {
            return;
        }

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
