<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema;

use Prism\Dbal\Exception\InvalidSchemaException;

/**
 * Table : colonnes ordonnées, clé primaire (composite permise), index et clés étrangères.
 * Immuable ; toute incohérence locale est refusée dès la construction.
 *
 * @api
 */
final class Table
{
    /** @var array<string, Column> par nom en minuscules, dans l'ordre de déclaration */
    private readonly array $columns;

    /** @var list<string>|null */
    public readonly ?array $primaryKey;

    /** @var array<string, Index> */
    private readonly array $indexes;

    /** @var array<string, ForeignKey> */
    private readonly array $foreignKeys;

    /**
     * @param list<Column>      $columns
     * @param list<string>|null $primaryKey
     * @param list<Index>       $indexes
     * @param list<ForeignKey>  $foreignKeys
     */
    public function __construct(
        public readonly string $name,
        array $columns = [],
        ?array $primaryKey = null,
        array $indexes = [],
        array $foreignKeys = [],
    ) {
        if ('' === trim($name)) {
            throw InvalidSchemaException::emptyName('table');
        }

        $this->columns = self::byName('column', $name, $columns, static fn (Column $c): string => $c->name);
        $this->indexes = self::byName('index', $name, $indexes, static fn (Index $i): string => $i->name);
        $this->foreignKeys = self::byName('foreign key', $name, $foreignKeys, static fn (ForeignKey $f): string => $f->name);
        $this->primaryKey = null === $primaryKey ? null : Keys::columns('primary key', $name, $primaryKey);

        $this->validate();
    }

    /**
     * @return list<Column>
     */
    public function getColumns(): array
    {
        return array_values($this->columns);
    }

    public function hasColumn(string $name): bool
    {
        return isset($this->columns[strtolower($name)]);
    }

    public function getColumn(string $name): Column
    {
        return $this->columns[strtolower($name)] ?? throw InvalidSchemaException::unknownColumn($this->name, $name, 'getColumn()');
    }

    /**
     * @return list<Index>
     */
    public function getIndexes(): array
    {
        return array_values($this->indexes);
    }

    /**
     * @return list<ForeignKey>
     */
    public function getForeignKeys(): array
    {
        return array_values($this->foreignKeys);
    }

    /**
     * Ajoute la colonne, ou remplace celle de même nom à la même position.
     */
    public function withColumn(Column $column): self
    {
        $columns = $this->columns;
        $columns[strtolower($column->name)] = $column;

        return $this->copy(columns: array_values($columns));
    }

    public function withoutColumn(string $name): self
    {
        $key = strtolower($name);
        if (!isset($this->columns[$key])) {
            throw InvalidSchemaException::unknownColumn($this->name, $name, 'withoutColumn()');
        }
        foreach ($this->usages() as [$columns, $usedBy]) {
            if (\in_array($key, array_map(strtolower(...), $columns), true)) {
                throw InvalidSchemaException::columnInUse($this->name, $name, $usedBy);
            }
        }

        $columns = $this->columns;
        unset($columns[$key]);

        return $this->copy(columns: array_values($columns));
    }

    /**
     * La même table sous un autre nom (renommage explicite d'une migration, ADR 0012).
     */
    public function withName(string $name): self
    {
        return new self($name, array_values($this->columns), $this->primaryKey, array_values($this->indexes), array_values($this->foreignKeys));
    }

    /**
     * La colonne renommée à sa place, et partout où la table la cite : clé primaire, index,
     * colonnes locales des clés étrangères. Les noms d'index et de clés sont gardés, comme la
     * base les garde (ADR 0012).
     */
    public function withRenamedColumn(string $from, string $to): self
    {
        $key = strtolower($from);
        if (!isset($this->columns[$key])) {
            throw InvalidSchemaException::unknownColumn($this->name, $from, 'withRenamedColumn()');
        }
        if (strtolower($to) !== $key && $this->hasColumn($to)) {
            throw InvalidSchemaException::duplicate('column', $to, $this->name);
        }
        $columns = array_map(static fn (Column $c): Column => strtolower($c->name) === $key ? $c->withName($to) : $c, array_values($this->columns));

        return new self(
            $this->name,
            $columns,
            null === $this->primaryKey ? null : self::renamed($this->primaryKey, $key, $to),
            array_map(static fn (Index $i): Index => new Index($i->name, self::renamed($i->columns, $key, $to), $i->unique), array_values($this->indexes)),
            array_map(static fn (ForeignKey $f): ForeignKey => new ForeignKey($f->name, self::renamed($f->localColumns, $key, $to), $f->foreignTable, $f->foreignColumns, $f->onDelete), array_values($this->foreignKeys)),
        );
    }

    /**
     * @param list<string> $columns
     *
     * @return list<string>
     */
    private static function renamed(array $columns, string $key, string $to): array
    {
        return array_map(static fn (string $c): string => strtolower($c) === $key ? $to : $c, $columns);
    }

    /**
     * Les clés étrangères qui visent une table renommée, ou une de ses colonnes renommée.
     *
     * @internal Schema::withRenamedTable() et withRenamedColumn()
     */
    public function withRenamedReference(string $table, ?string $newTable, ?string $column = null, ?string $newColumn = null): self
    {
        $foreignKeys = [];
        foreach ($this->foreignKeys as $foreignKey) {
            if (0 !== strcasecmp($foreignKey->foreignTable, $table)) {
                $foreignKeys[] = $foreignKey;
                continue;
            }
            $columns = null === $column ? $foreignKey->foreignColumns : array_map(static fn (string $c): string => 0 === strcasecmp($c, $column) ? (string) $newColumn : $c, $foreignKey->foreignColumns);
            $foreignKeys[] = new ForeignKey($foreignKey->name, $foreignKey->localColumns, $newTable ?? $foreignKey->foreignTable, $columns, $foreignKey->onDelete);
        }

        return $this->copy(foreignKeys: $foreignKeys);
    }

    /**
     * @param list<string>|null $columns
     */
    public function withPrimaryKey(?array $columns): self
    {
        return $this->copy(primaryKey: $columns, resetPrimaryKey: null === $columns);
    }

    public function withIndex(Index $index): self
    {
        $indexes = $this->indexes;
        $indexes[strtolower($index->name)] = $index;

        return $this->copy(indexes: array_values($indexes));
    }

    /**
     * Index nommé automatiquement (RG-01.7) quand $name est null ; partiel avec $whereNull.
     *
     * @param list<string> $columns
     */
    public function withIndexOn(array $columns, bool $unique = false, ?string $name = null, ?string $whereNull = null): self
    {
        // un index partiel ne prend pas le nom de l'index complet sur les mêmes colonnes
        $named = null === $whereNull ? $columns : [...$columns, 'where ' . $whereNull . ' is null'];

        return $this->withIndex(new Index($name ?? NameGenerator::generate($unique ? 'uniq' : 'idx', $this->name, $named), $columns, $unique, $whereNull));
    }

    public function withoutIndex(string $name): self
    {
        $indexes = $this->indexes;
        unset($indexes[strtolower($name)]);

        return $this->copy(indexes: array_values($indexes));
    }

    public function withForeignKey(ForeignKey $foreignKey): self
    {
        $foreignKeys = $this->foreignKeys;
        $foreignKeys[strtolower($foreignKey->name)] = $foreignKey;

        return $this->copy(foreignKeys: array_values($foreignKeys));
    }

    /**
     * Clé étrangère nommée automatiquement (RG-01.7) quand $name est null.
     *
     * @param list<string> $localColumns
     * @param list<string> $foreignColumns
     */
    public function withForeignKeyTo(array $localColumns, string $foreignTable, array $foreignColumns, ForeignKeyAction $onDelete = ForeignKeyAction::NoAction, ?string $name = null): self
    {
        return $this->withForeignKey(new ForeignKey($name ?? NameGenerator::generate('fk', $this->name, $localColumns), $localColumns, $foreignTable, $foreignColumns, $onDelete));
    }

    public function withoutForeignKey(string $name): self
    {
        $foreignKeys = $this->foreignKeys;
        unset($foreignKeys[strtolower($name)]);

        return $this->copy(foreignKeys: array_values($foreignKeys));
    }

    /**
     * @param list<Column>|null     $columns
     * @param list<string>|null     $primaryKey
     * @param list<Index>|null      $indexes
     * @param list<ForeignKey>|null $foreignKeys
     */
    private function copy(?array $columns = null, ?array $primaryKey = null, bool $resetPrimaryKey = false, ?array $indexes = null, ?array $foreignKeys = null): self
    {
        return new self(
            $this->name,
            $columns ?? array_values($this->columns),
            $resetPrimaryKey ? null : ($primaryKey ?? $this->primaryKey),
            $indexes ?? array_values($this->indexes),
            $foreignKeys ?? array_values($this->foreignKeys),
        );
    }

    private function validate(): void
    {
        foreach ($this->usages() as [$columns, $usedBy]) {
            foreach ($columns as $column) {
                if (!$this->hasColumn($column)) {
                    throw InvalidSchemaException::unknownColumn($this->name, $column, $usedBy);
                }
            }
        }

        foreach ($this->primaryKey ?? [] as $column) {
            if ($this->getColumn($column)->nullable) {
                throw InvalidSchemaException::nullablePrimaryKey($this->name, $column);
            }
        }

        // règle portable : MySQL exige une clé, SQLite un INTEGER PRIMARY KEY seul
        foreach ($this->columns as $column) {
            if ($column->autoincrement && [strtolower($column->name)] !== array_map(strtolower(...), $this->primaryKey ?? [])) {
                throw InvalidSchemaException::invalidAutoincrement($this->name, $column->name, 'it must be the only primary key column.');
            }
        }
    }

    /**
     * @return list<array{list<string>, string}> colonnes utilisées, et par quoi
     */
    private function usages(): array
    {
        $usages = [];
        if (null !== $this->primaryKey) {
            $usages[] = [$this->primaryKey, 'the primary key'];
        }
        foreach ($this->indexes as $index) {
            $usages[] = [$index->columns, \sprintf('index "%s"', $index->name)];
        }
        foreach ($this->foreignKeys as $foreignKey) {
            $usages[] = [$foreignKey->localColumns, \sprintf('foreign key "%s"', $foreignKey->name)];
        }

        return $usages;
    }

    /**
     * @template T of object
     *
     * @param list<T>             $items
     * @param \Closure(T): string $name
     *
     * @return array<string, T>
     */
    private static function byName(string $object, string $table, array $items, \Closure $name): array
    {
        $byName = [];
        foreach ($items as $item) {
            $key = strtolower($name($item));
            if (isset($byName[$key])) {
                throw InvalidSchemaException::duplicate($object, $name($item), $table);
            }
            $byName[$key] = $item;
        }

        return $byName;
    }
}
