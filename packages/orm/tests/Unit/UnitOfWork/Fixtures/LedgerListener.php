<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures;

use Prism\Orm\Mapping as Prism;

// une dépendance au constructeur : le résolveur par défaut ne sait pas le construire
final class LedgerListener
{
    /** @var list<string> */
    public array $seen = [];

    public function __construct(public readonly string $channel)
    {
    }

    #[Prism\PrePersist]
    public function prePersist(Ledger $ledger): void
    {
        $this->seen[] = $this->channel;
    }
}
