<?php

declare(strict_types=1);

namespace Prism\Orm\Tenancy;

/**
 * Contexte de tenant en pile : setTenantId() fixe la base (TenantResolver du bundle),
 * runAs() et runAsSystem() empilent un état et le dépilent en sortie. reset() revient à
 * l'état vide entre deux requêtes d'un worker (kernel.reset, branché par le bundle).
 *
 * Les listeners sont prévenus avant chaque changement effectif, sauf au reset(), où
 * l'EntityManager est réinitialisé de son côté ; ils sont gardés en références faibles.
 *
 * @api
 */
final class StackTenantContext implements TenantContext
{
    private int|string|null $base = null;

    /** @var list<array{int|string|null, bool}> tenant et mode système, du plus ancien au plus récent */
    private array $stack = [];

    /** @var list<\WeakReference<TenantSwitchListener>> */
    private array $listeners = [];

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
        // sous un runAs(), le tenant effectif ne change pas
        if ([] === $this->stack) {
            $this->notify($this->scope(), new TenantScope($tenantId), leaving: false);
        }
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

    public function addSwitchListener(TenantSwitchListener $listener): void
    {
        $this->listeners[] = \WeakReference::create($listener);
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
        $scope = new TenantScope($tenantId, $system);
        $this->notify($this->scope(), $scope, leaving: false);
        $this->stack[] = [$tenantId, $system];
        try {
            $result = $callback();
        } catch (\Throwable $e) {
            array_pop($this->stack);
            // l'exception du callback l'emporte : les listeners ont remis leur état en ordre
            try {
                $this->notify($scope, $this->scope(), leaving: true);
            } catch (\Throwable) {
            }

            throw $e;
        }
        array_pop($this->stack);
        $this->notify($scope, $this->scope(), leaving: true);

        return $result;
    }

    private function scope(): TenantScope
    {
        return new TenantScope($this->getTenantId(), $this->isSystem());
    }

    private function notify(TenantScope $from, TenantScope $to, bool $leaving): void
    {
        if ($from->equals($to)) {
            return;
        }
        // les listeners disparus sont oubliés
        $this->listeners = array_values(array_filter($this->listeners, static fn (\WeakReference $reference): bool => null !== $reference->get()));
        foreach ($this->listeners as $reference) {
            $reference->get()?->switching($from, $to, $leaving);
        }
    }
}
