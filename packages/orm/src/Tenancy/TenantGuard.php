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
 * L'analyse (SqlTenantAnalyzer) est mise en cache par texte SQL : le SQL paramétré de l'ORM est
 * stable ; le cache est vidé au-delà de 1 000 requêtes, les listes IN en produisant beaucoup.
 *
 * @api
 */
final class TenantGuard implements QueryGuard
{
    private const int CACHE_SIZE = 1000;

    /** @var array<string, string>|null colonne du tenant, par nom de table en minuscules */
    private ?array $tables = null;

    private ?SqlTenantAnalyzer $analyzer = null;

    /** @var array<string, list<array{string, string}>> violations, par texte SQL */
    private array $cache = [];

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
        if (!isset($this->cache[$sql])) {
            if (\count($this->cache) >= self::CACHE_SIZE) {
                $this->cache = [];
            }
            $this->tables ??= $this->tenantedTables();
            $this->analyzer ??= new SqlTenantAnalyzer($this->tables);
            $this->cache[$sql] = $this->analyzer->violations($sql);
        }
        $tables = $this->tables ?? [];

        return array_map(static fn (array $violation): UnfilteredTenantQueryException => match ($violation[0]) {
            SqlTenantAnalyzer::UNPARSABLE => UnfilteredTenantQueryException::unparsable($violation[1], $sql),
            SqlTenantAnalyzer::TRUNCATE => UnfilteredTenantQueryException::truncate($violation[1], $sql),
            default => UnfilteredTenantQueryException::forTable($violation[1], $tables[$violation[1]] ?? '', $sql),
        }, $this->cache[$sql]);
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
