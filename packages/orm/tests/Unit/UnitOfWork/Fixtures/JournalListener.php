<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures;

use Prism\Orm\Mapping as Prism;

final class JournalListener
{
    public static int $instances = 0;

    public function __construct()
    {
        ++self::$instances;
    }

    #[Prism\PrePersist, Prism\PostPersist, Prism\PreUpdate, Prism\PostUpdate, Prism\PreRemove, Prism\PostRemove, Prism\PostLoad]
    public function record(Journal $journal, object $event): void
    {
        Journal::$log[] = 'listener:' . Journal::short($event);
    }
}
