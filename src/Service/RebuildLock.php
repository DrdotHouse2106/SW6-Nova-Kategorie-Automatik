<?php declare(strict_types=1);

namespace Nova\KategorieAutomatik\Service;

use Doctrine\DBAL\Connection;
use Nova\KategorieAutomatik\NovaKategorieAutomatik;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Sperre für die Neuzuordnung: Es läuft immer nur eine Kette. Jede Nachricht trägt das Kennzeichen
 * ihrer Kette; eine neu gestartete Kette löst die laufende ab, deren nächste Nachricht dann endet.
 *
 * Gespeichert in `app_config` (Schlüssel/Wert-Tabelle von Shopware) statt in der System-Konfiguration,
 * damit Fortschrittsmeldungen keine Konfigurations-Ereignisse und Cache-Leerungen auslösen.
 */
class RebuildLock
{
    private const KEY = 'nova_kategorie_automatik.rebuild';

    /** Ohne Lebenszeichen gilt eine Kette danach als abgebrochen (z. B. Nachricht endgültig fehlgeschlagen) */
    private const STALE_SECONDS = 1800;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Neue Kette anmelden. Läuft bereits eine Kette mit denselben Codes, wird keine zweite gestartet (null).
     */
    public function start(string $fingerprint, bool $force = false): ?string
    {
        $state = $this->read();
        if (!$force && $state !== null && $this->isAlive($state) && ($state['fingerprint'] ?? null) === $fingerprint) {
            return null;
        }

        $token = Uuid::randomHex();
        $this->write([
            'token' => $token,
            'fingerprint' => $fingerprint,
            'startedAt' => time(),
            'heartbeat' => time(),
            'processed' => 0,
            'changed' => 0,
        ]);

        return $token;
    }

    public function isCurrent(string $token): bool
    {
        $state = $this->read();

        return $state !== null && ($state['token'] ?? null) === $token;
    }

    public function isRunning(): bool
    {
        $state = $this->read();

        return $state !== null && $this->isAlive($state);
    }

    /**
     * Fortschritt eines Pakets vermerken.
     */
    public function progress(string $token, int $processed, int $changed): void
    {
        $state = $this->read();
        if ($state === null || ($state['token'] ?? null) !== $token) {
            return;
        }

        $state['heartbeat'] = time();
        $state['processed'] = (int) ($state['processed'] ?? 0) + $processed;
        $state['changed'] = (int) ($state['changed'] ?? 0) + $changed;
        $this->write($state);
    }

    /**
     * Kette beenden; liefert den Endstand für das Protokoll.
     *
     * @return array<string, mixed>|null
     */
    public function finish(string $token): ?array
    {
        $state = $this->read();
        if ($state === null || ($state['token'] ?? null) !== $token) {
            return null;
        }

        $this->connection->executeStatement('DELETE FROM `app_config` WHERE `key` = :key', ['key' => self::KEY]);

        return $state;
    }

    /**
     * Kennung des aktuellen Code-Stands (Kategorie-Codes und gewählte Eigenschaften).
     *
     * @param list<string> $groupIds
     */
    public function fingerprint(array $groupIds): string
    {
        $rows = $this->connection->fetchAllNumeric(
            'SELECT LOWER(HEX(category_id)), LOWER(HEX(language_id)), JSON_UNQUOTE(JSON_EXTRACT(custom_fields, :path))
             FROM category_translation
             WHERE category_version_id = :live AND JSON_EXTRACT(custom_fields, :path) IS NOT NULL
             ORDER BY category_id, language_id',
            ['path' => '$.' . NovaKategorieAutomatik::CODE_FIELD, 'live' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );
        sort($groupIds);

        return md5((string) json_encode([$rows, $groupIds]));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isAlive(array $state): bool
    {
        return time() - (int) ($state['heartbeat'] ?? 0) < self::STALE_SECONDS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(): ?array
    {
        $value = $this->connection->fetchOne('SELECT `value` FROM `app_config` WHERE `key` = :key', ['key' => self::KEY]);
        if (!\is_string($value)) {
            return null;
        }
        $state = json_decode($value, true);

        return \is_array($state) ? $state : null;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function write(array $state): void
    {
        $this->connection->executeStatement(
            'REPLACE INTO `app_config` (`key`, `value`) VALUES (:key, :value)',
            ['key' => self::KEY, 'value' => json_encode($state)]
        );
    }
}
