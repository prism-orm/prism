<?php

declare(strict_types=1);

namespace Prism\Bundle\Debug;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le panneau Audit du profiler (dev seulement, ADR 0015) : les lignes du journal écrites pendant
 * la requête, par EntityManager.
 *
 * @internal
 */
final class AuditDataCollector extends AbstractDataCollector
{
    /**
     * @param list<AuditRecorder> $recorders
     */
    public function __construct(
        private readonly array $recorders,
    ) {
    }

    public function getName(): string
    {
        return 'prism_audit';
    }

    public static function getTemplate(): string
    {
        return '@Prism/Collector/audit.html.twig';
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = ['entries' => array_merge(...array_map(static fn (AuditRecorder $r): array => $r->getEntries(), $this->recorders))];
    }

    /**
     * @return list<array{entityManager: string, class: string, id: string, tenant: ?string, action: string, changes: array<string, array{string, string}>, actor: ?string, system: bool}>
     */
    public function getEntries(): array
    {
        /** @var list<array{entityManager: string, class: string, id: string, tenant: ?string, action: string, changes: array<string, array{string, string}>, actor: ?string, system: bool}> */
        return $this->data['entries'] ?? [];
    }
}
