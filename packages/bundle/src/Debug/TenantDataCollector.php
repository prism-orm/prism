<?php

declare(strict_types=1);

namespace Prism\Bundle\Debug;

use Prism\Bundle\Tenancy\TenantResolver;
use Prism\Orm\Tenancy\TenantContext;
use Prism\Orm\Tenancy\TenantScope;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le panneau Tenant du profiler (dev seulement, ADR 0014) : le tenant de la requête, la
 * stratégie qui l'a résolu et l'identifiant lu, les passages en runAs() et runAsSystem().
 *
 * @internal
 */
final class TenantDataCollector extends AbstractDataCollector
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantRecorder $recorder,
        private readonly ?TenantResolver $resolver = null,
    ) {
    }

    public function getName(): string
    {
        return 'prism_tenant';
    }

    public static function getTemplate(): string
    {
        return '@Prism/Collector/tenant.html.twig';
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = [
            'tenant' => new TenantScope($this->context->getTenantId(), $this->context->isSystem())->describe(),
            'strategy' => $this->resolver?->getStrategy(),
            'identifier' => $this->resolver?->getIdentifier(),
            'switches' => $this->recorder->getSwitches(),
        ];
    }

    public function getTenant(): string
    {
        return \is_string($this->data['tenant'] ?? null) ? $this->data['tenant'] : 'no tenant';
    }

    public function getStrategy(): ?string
    {
        return \is_string($this->data['strategy'] ?? null) ? $this->data['strategy'] : null;
    }

    public function getIdentifier(): ?string
    {
        return \is_string($this->data['identifier'] ?? null) ? $this->data['identifier'] : null;
    }

    /**
     * @return list<array{from: string, to: string, leaving: bool}>
     */
    public function getSwitches(): array
    {
        /** @var list<array{from: string, to: string, leaving: bool}> */
        return $this->data['switches'] ?? [];
    }
}
