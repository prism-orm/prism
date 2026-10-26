<?php

declare(strict_types=1);

namespace Prism\Orm\Tenancy;

use Prism\Dbal\QueryGuard;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Exception\UnfilteredTenantQueryException;
use Prism\Orm\Metadata\MetadataFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Garde du SQL brut (RG-08.7, ADR 0014) : toute requête qui touche une table #[Tenanted] doit
 * porter une condition sur sa colonne du tenant (WHERE, ON, ou colonne d'un INSERT). Une
 * requête qu'elle ne sait pas analyser est refusée. Inactive en mode système.
 *
 * Phase 8, étape 2 : l'analyse du SQL arrive à l'étape 6 ; les scénarios de référence sont
 * dans tests/Functional/TenantIsolationTest.
 *
 * @api
 */
final class TenantGuard implements QueryGuard
{
    /** @var array<string, string>|null colonne du tenant, par nom de table en minuscules */
    private ?array $tables = null;

    public function __construct(
        private readonly MetadataFactory $metadata,
        private readonly TenantContext $context,
        private readonly TenantGuardMode $mode = TenantGuardMode::Exception,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @throws UnfilteredTenantQueryException en mode Exception
     * @throws MappingException
     */
    public function guard(string $sql): void
    {
        if ($this->context->isSystem()) {
            return;
        }
        foreach ($this->violations($sql) as $violation) {
            if (TenantGuardMode::Exception === $this->mode) {
                throw $violation;
            }
            $this->logger->critical($violation->getMessage(), ['sql' => $sql]);
        }
    }

    /**
     * @return list<UnfilteredTenantQueryException>
     *
     * @throws MappingException
     */
    private function violations(string $sql): array
    {
        $this->tables ??= $this->tenantedTables();
        if ([] === $this->tables) {
            return [];
        }

        throw new \LogicException(\sprintf('The SQL analysis of the TenantGuard is implemented in phase 8, step 6 (%d tenanted tables, query: %s).', \count($this->tables), $sql));
    }

    /**
     * @return array<string, string>
     *
     * @throws MappingException
     */
    private function tenantedTables(): array
    {
        $tables = [];
        foreach ($this->metadata->getAllMetadata() as $metadata) {
            if (null !== $metadata->tenancy) {
                $tables[strtolower($metadata->tableName)] = $metadata->tenancy->column;
            }
        }

        return $tables;
    }
}
