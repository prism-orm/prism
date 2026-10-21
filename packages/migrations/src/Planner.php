<?php

declare(strict_types=1);

namespace Prism\Migrations;

use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\SchemaDiff;
use Prism\Migrations\Operation\Operation;
use Prism\Migrations\Operation\RenameColumn;
use Prism\Migrations\Operation\RenameTable;
use Prism\Migrations\Operation\SchemaOperation;
use Prism\Migrations\Operation\Sql;

/**
 * Traduit les opérations d'une migration en étapes (ADR 0012) : les opérations de schéma qui se
 * suivent sont appliquées au schéma, puis comparées en un seul diff ; un renommage ou du SQL
 * brut ferme le diff en cours et devient une instruction explicite, dans l'ordre écrit.
 *
 * @internal
 */
final class Planner
{
    public function __construct(private readonly AbstractPlatform $platform)
    {
    }

    /**
     * @param list<Operation> $operations
     *
     * @throws InvalidSchemaException opération impossible sur ce schéma
     */
    public function plan(Schema $schema, array $operations): Plan
    {
        $comparator = new Comparator($this->platform);
        /** @var list<SchemaDiff|array{string, array<int<0, max>|string, mixed>}> $steps */
        $steps = [];
        $base = $schema;
        $ddl = false;
        $flush = static function () use ($comparator, &$steps, &$base, &$schema, &$ddl): void {
            $diff = $comparator->compare($base, $schema);
            if (!$diff->isEmpty()) {
                $steps[] = $diff;
                $ddl = true;
            }
            $base = $schema;
        };

        foreach ($operations as $operation) {
            if ($operation instanceof RenameTable || $operation instanceof RenameColumn) {
                $flush();
                $schema = $operation->apply($schema);
                $steps[] = [$operation instanceof RenameTable
                    ? $this->platform->getRenameTableSql($operation->from, $operation->to)
                    : $this->platform->getRenameColumnSql($operation->table, $operation->from, $operation->to), []];
                $base = $schema;
                $ddl = true;
            } elseif ($operation instanceof Sql) {
                $flush();
                $steps[] = [$operation->sql, $operation->params];
            } elseif ($operation instanceof SchemaOperation) {
                $schema = $operation->apply($schema);
            }
        }
        $flush();

        return new Plan($steps, $schema, $ddl);
    }
}
