<?php

declare(strict_types=1);

namespace Prism\Bundle\Debug;

use Prism\Dbal\QueryObserver;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Les requêtes d'une connexion pendant une requête HTTP, pour le profiler (dev seulement,
 * RG-07.4) : au plus $limit enregistrées, toutes comptées ; vidé par kernel.reset.
 *
 * @internal
 */
final class QueryRecorder implements QueryObserver, ResetInterface
{
    /** @var list<array{sql: string, params: array<int|string, mixed>, durationMs: float}> */
    private array $queries = [];

    private int $count = 0;

    private float $durationMs = 0.0;

    // jamais remis à zéro : assertQueryCount() compte à travers les requêtes HTTP d'un test
    private int $lifetimeCount = 0;

    private int $resets = 0;

    public function __construct(private readonly int $limit = 1000)
    {
    }

    public function queryExecuted(string $sql, array $params, float $durationMs): void
    {
        ++$this->count;
        ++$this->lifetimeCount;
        $this->durationMs += $durationMs;
        if (\count($this->queries) < $this->limit) {
            $this->queries[] = ['sql' => $sql, 'params' => $params, 'durationMs' => $durationMs];
        }
    }

    /**
     * @return list<array{sql: string, params: array<int|string, mixed>, durationMs: float}>
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    // toutes les requêtes, enregistrées ou non
    public function getCount(): int
    {
        return $this->count;
    }

    public function getDurationMs(): float
    {
        return $this->durationMs;
    }

    public function getLifetimeCount(): int
    {
        return $this->lifetimeCount;
    }

    // une remise à zéro par requête HTTP (kernel.reset)
    public function getResets(): int
    {
        return $this->resets;
    }

    public function reset(): void
    {
        ++$this->resets;
        $this->queries = [];
        $this->count = 0;
        $this->durationMs = 0.0;
    }
}
