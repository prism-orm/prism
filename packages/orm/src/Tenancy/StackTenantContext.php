<?php

declare(strict_types=1);

namespace Prism\Orm\Tenancy;

/**
 * Contexte de tenant en pile : setTenantId() fixe la base (TenantResolver du bundle),
 * runAs() et runAsSystem() empilent un état et le dépilent en sortie. reset() revient à
 * l'état vide entre deux requêtes d'un worker (kernel.reset, branché par le bundle).
 *
 * @api
 */
final class StackTenantContext implements TenantContext
{
    private int|string|null $base = null;

    /** @var list<array{int|string|null, bool}> tenant et mode système, du plus ancien au plus récent */
    private array $stack = [];

    public function __construct(int|string|null $tenantId = null)
    {
        $this->base = $tenantId;
    }

    public function getTenantId(): int|string|null
    {
        return [] === $this->stack ? $this->base : $this->stack[array_key_last($this->stack)][0];
    }

    public function isSystem(): bool
    {
        return [] !== $this->stack && $this->stack[array_key_last($this->stack)][1];
    }

    public function setTenantId(int|string|null $tenantId): void
    {
        $this->base = $tenantId;
    }

    public function runAs(int|string $tenantId, \Closure $callback): mixed
    {
        return $this->run($tenantId, false, $callback);
    }

    public function runAsSystem(\Closure $callback): mixed
    {
        return $this->run(null, true, $callback);
    }

    public function reset(): void
    {
        $this->base = null;
        $this->stack = [];
    }

    /**
     * @template R
     *
     * @param \Closure(): R $callback
     *
     * @return R
     */
    private function run(int|string|null $tenantId, bool $system, \Closure $callback): mixed
    {
        $this->stack[] = [$tenantId, $system];
        try {
            return $callback();
        } finally {
            array_pop($this->stack);
        }
    }
}
