<?php

declare(strict_types=1);

namespace Prism\Bundle\Debug;

use Prism\Orm\Tenancy\TenantScope;
use Prism\Orm\Tenancy\TenantSwitchListener;

/**
 * Les changements de tenant d'une requête, pour le panneau Tenant du profiler (dev seulement) :
 * runAs(), runAsSystem() et leurs sorties.
 *
 * @internal
 */
final class TenantRecorder implements TenantSwitchListener
{
    /** @var list<array{from: string, to: string, leaving: bool}> */
    private array $switches = [];

    public function switching(TenantScope $from, TenantScope $to, bool $leaving): void
    {
        $this->switches[] = ['from' => $from->describe(), 'to' => $to->describe(), 'leaving' => $leaving];
    }

    /**
     * @return list<array{from: string, to: string, leaving: bool}>
     */
    public function getSwitches(): array
    {
        return $this->switches;
    }

    public function reset(): void
    {
        $this->switches = [];
    }
}
