<?php

declare(strict_types=1);

namespace Prism\Orm\Behavior;

use Psr\Clock\ClockInterface;

/**
 * L'heure courante en UTC (RG-08.13), quand l'application ne fournit pas d'horloge PSR-20.
 *
 * @internal
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
