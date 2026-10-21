<?php

declare(strict_types=1);

namespace Prism\Migrations\Lint;

/**
 * Ce que le linter demande aux données d'une table existante. Chaque question est bon marché
 * (LIMIT 1) ; doublons et orphelins ne sont cherchés que pour les opérations concernées.
 *
 * @internal
 */
interface DataInspector
{
    public function isEmpty(string $table): bool;

    public function hasMoreRowsThan(string $table, int $rows): bool;

    public function hasNulls(string $table, string $column): bool;

    /**
     * @param list<string> $columns
     */
    public function hasDuplicates(string $table, array $columns): bool;

    /**
     * @param list<string> $localColumns
     * @param list<string> $foreignColumns
     */
    public function hasOrphans(string $table, array $localColumns, string $foreignTable, array $foreignColumns): bool;
}
