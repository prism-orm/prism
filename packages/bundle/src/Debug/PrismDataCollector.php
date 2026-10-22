<?php

declare(strict_types=1);

namespace Prism\Bundle\Debug;

use Prism\Orm\Debug\N1Detector;
use Prism\Orm\Debug\NPlusOneDetected;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le panneau Prism du profiler (dev seulement, RG-07.4) : par connexion, les requêtes et leur
 * durée, les N+1 détectés avec leur origine (RG-04.15) ; par EntityManager, les chargements par
 * lot (pilier 1) et les flushs avec leurs changesets.
 *
 * @internal
 */
final class PrismDataCollector extends AbstractDataCollector
{
    /**
     * @param array<string, array{QueryRecorder, N1Detector}> $connections
     * @param array<string, FlushRecorder>                    $entityManagers
     */
    public function __construct(
        private readonly array $connections,
        private readonly array $entityManagers,
        private readonly int $limit,
    ) {
    }

    public function getName(): string
    {
        return 'prism';
    }

    public static function getTemplate(): string
    {
        return '@Prism/Collector/prism.html.twig';
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $connections = [];
        foreach ($this->connections as $name => [$queries, $detector]) {
            $connections[$name] = [
                'queries' => $queries->getQueries(),
                'count' => $queries->getCount(),
                'durationMs' => $queries->getDurationMs(),
                'nPlusOne' => array_map(static fn (NPlusOneDetected $d): array => ['sql' => $d->sql, 'count' => $d->count, 'origin' => $d->origin], $detector->getDetections()),
            ];
        }
        $entityManagers = [];
        foreach ($this->entityManagers as $name => $recorder) {
            $entityManagers[$name] = ['flushes' => $recorder->getFlushes(), 'batches' => $recorder->getBatches()];
        }
        $this->data = ['connections' => $connections, 'entityManagers' => $entityManagers, 'limit' => $this->limit];
    }

    /**
     * @return array<string, array{queries: list<array{sql: string, params: array<int|string, mixed>, durationMs: float}>, count: int, durationMs: float, nPlusOne: list<array{sql: string, count: int, origin: string}>}>
     */
    public function getConnections(): array
    {
        /** @var array<string, array{queries: list<array{sql: string, params: array<int|string, mixed>, durationMs: float}>, count: int, durationMs: float, nPlusOne: list<array{sql: string, count: int, origin: string}>}> */
        return $this->data['connections'] ?? [];
    }

    /**
     * @return array<string, array{flushes: list<list<array{action: string, class: string, id: string, changes: array<string, array{string, string}>}>>, batches: list<array{class: string, association: ?string, count: int}>}>
     */
    public function getEntityManagers(): array
    {
        /** @var array<string, array{flushes: list<list<array{action: string, class: string, id: string, changes: array<string, array{string, string}>}>>, batches: list<array{class: string, association: ?string, count: int}>}> */
        return $this->data['entityManagers'] ?? [];
    }

    public function getLimit(): int
    {
        return \is_int($this->data['limit'] ?? null) ? $this->data['limit'] : 0;
    }

    public function getQueryCount(): int
    {
        return array_sum(array_map(static fn (array $c): int => $c['count'], $this->getConnections()));
    }

    public function getDurationMs(): float
    {
        return array_sum(array_map(static fn (array $c): float => $c['durationMs'], $this->getConnections()));
    }

    public function getNPlusOneCount(): int
    {
        return array_sum(array_map(static fn (array $c): int => \count($c['nPlusOne']), $this->getConnections()));
    }

    public function getBatchCount(): int
    {
        return array_sum(array_map(static fn (array $em): int => \count($em['batches']), $this->getEntityManagers()));
    }

    public function getFlushCount(): int
    {
        return array_sum(array_map(static fn (array $em): int => \count($em['flushes']), $this->getEntityManagers()));
    }
}
