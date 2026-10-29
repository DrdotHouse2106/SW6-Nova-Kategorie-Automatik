<?php declare(strict_types=1);

namespace Nova\KategorieAutomatik\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * Ein Paket der Neuzuordnung aller Artikel; das nächste Paket wird vom Handler nachgeschoben.
 */
class RebuildMessage implements AsyncMessageInterface
{
    /** Kennzeichen der Kette (RebuildLock); null bei Nachrichten aus Versionen vor 1.2.0 */
    public ?string $token = null;

    /** Ab hier weiterlesen (product.auto_increment); ersetzt den Offset bei Ketten mit Kennzeichen */
    public int $afterAutoIncrement = 0;

    public function __construct(public readonly int $offset = 0, ?string $token = null, int $afterAutoIncrement = 0)
    {
        $this->token = $token;
        $this->afterAutoIncrement = $afterAutoIncrement;
    }
}
