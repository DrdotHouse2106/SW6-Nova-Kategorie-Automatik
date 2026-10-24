<?php declare(strict_types=1);

namespace Nova\KategorieAutomatik\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * Ein Paket der Neuzuordnung aller Artikel; das nächste Paket wird vom Handler nachgeschoben.
 */
class RebuildMessage implements AsyncMessageInterface
{
    public function __construct(public readonly int $offset = 0)
    {
    }
}
