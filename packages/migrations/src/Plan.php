<?php

declare(strict_types=1);

namespace Prism\Migrations;

use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\SchemaDiff;

/**
 * Ce qu'une migration fera, dans l'ordre : des diffs de schéma, que le SchemaManager applique
 * (reconstruction SQLite comprise), et des instructions explicites (renommages, SQL brut).
 *
 * @internal
 */
final class Plan
{
    /**
     * @param list<SchemaDiff|array{string, array<int<0, max>|string, mixed>}> $steps
     * @param Schema                                                           $schema le schéma après la migration
     * @param bool                                                             $ddl    vrai si le schéma change
     */
    public function __construct(
        public readonly array $steps,
        public readonly Schema $schema,
        public readonly bool $ddl,
        // une opération interdit la transaction (CREATE INDEX CONCURRENTLY)
        public readonly bool $outsideTransaction = false,
    ) {
    }

    /**
     * Le SQL de la plateforme, pour --dry-run et le compte rendu.
     *
     * @return list<string>
     */
    public function sql(AbstractPlatform $platform): array
    {
        $sql = [];
        foreach ($this->steps as $step) {
            array_push($sql, ...($step instanceof SchemaDiff ? $platform->getMigrationSql($step) : [$step[0]]));
        }

        return $sql;
    }
}
