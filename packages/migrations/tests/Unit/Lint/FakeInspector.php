<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Unit\Lint;

use Prism\Migrations\Lint\DataInspector;

/**
 * Des données décrites par le test ; retient les tables interrogées, sous leur nom en base.
 */
final class FakeInspector implements DataInspector
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param list<string>                   $withRows   tables non vides
     * @param list<string>                   $large      tables au-delà du seuil
     * @param list<string>                   $nulls      « table.colonne » contenant des NULL
     * @param list<string>                   $duplicates « table(col,col) » avec doublons
     * @param list<string>                   $orphans    tables aux lignes orphelines
     * @param array<string, array{int, int}> $groups     « table.colonne » → lignes du plus gros groupe, lignes de la table
     */
    public function __construct(
        private readonly array $withRows = [],
        private readonly array $large = [],
        private readonly array $nulls = [],
        private readonly array $duplicates = [],
        private readonly array $orphans = [],
        private readonly array $groups = [],
    ) {
    }

    public function isEmpty(string $table): bool
    {
        $this->asked[] = $table;

        return !\in_array($table, $this->withRows, true);
    }

    public function hasMoreRowsThan(string $table, int $rows): bool
    {
        $this->asked[] = $table;
        foreach ($this->groups as $column => [, $total]) {
            if (str_starts_with($column, $table . '.')) {
                return $total > $rows;
            }
        }

        return \in_array($table, $this->large, true);
    }

    public function largestGroup(string $table, string $column): int
    {
        return $this->groups[$table . '.' . $column][0] ?? 0;
    }

    public function hasNulls(string $table, string $column): bool
    {
        return \in_array($table . '.' . $column, $this->nulls, true);
    }

    public function hasDuplicates(string $table, array $columns): bool
    {
        return \in_array($table . '(' . implode(',', $columns) . ')', $this->duplicates, true);
    }

    public function hasOrphans(string $table, array $localColumns, string $foreignTable, array $foreignColumns): bool
    {
        return \in_array($table, $this->orphans, true);
    }
}
