<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Support;

use Psr\Clock\ClockInterface;

/**
 * Une horloge PSR-20 figée, avancée à la main.
 *
 * @internal
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}
