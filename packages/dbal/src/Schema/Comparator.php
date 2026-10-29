<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema;

use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\AbstractPlatform;

/**
 * Calcule le SchemaDiff qui mène de $from à $to.
 *
 * - Les deux côtés sont comparés sous leur forme canonique pour la plateforme : un schéma
 *   déclaré et le même schéma relu par introspection ne diffèrent pas (RG-01.8).
 * - Les noms sont comparés sans tenir compte de la casse, comme MySQL.
 * - Aucun renommage n'est deviné : il apparaît en suppression + ajout (RG-01.9).
 * - L'ordre des tables, colonnes, index et clés étrangères est ignoré.
 *
 * @internal
 */
final class Comparator
{
    private const array COLUMN_PROPERTIES = ['type', 'nullable', 'default', 'length', 'precision', 'scale', 'autoincrement', 'fixed'];

    public function __construct(private readonly AbstractPlatform $platform)
    {
    }

    /**
     * @throws InvalidSchemaException une clé étrangère du schéma cible vise une table absente
     */
    public function compare(Schema $from, Schema $to): SchemaDiff
    {
        $this->assertForeignTablesExist($to);

        $created = [];
        $altered = [];
        foreach ($to->getTables() as $table) {
            if (!$from->hasTable($table->name)) {
                $created[] = $table;
                continue;
            }
            $diff = $this->compareTables($from->getTable($table->name), $table);
            if (!$diff->isEmpty()) {
                $altered[] = $diff;
            }
        }

        $dropped = array_values(array_filter($from->getTables(), static fn (Table $t): bool => !$to->hasTable($t->name)));

        [$createdSequences, $droppedSequences, $alteredSequences] = self::compareSequences($from, $to);

        return new SchemaDiff($created, $dropped, $altered, $createdSequences, $droppedSequences, $alteredSequences);
    }

    public function compareTables(Table $from, Table $to): TableDiff
    {
        $from = $this->platform->getCanonicalTable($from);
        $to = $this->platform->getCanonicalTable($to);

        $added = [];
        $changed = [];
        foreach ($to->getColumns() as $column) {
            if (!$from->hasColumn($column->name)) {
                $added[] = $column;
                continue;
            }
            $properties = self::changedProperties($from->getColumn($column->name), $column);
            if ([] !== $properties) {
                $changed[] = new ColumnDiff($from->getColumn($column->name), $column, $properties);
            }
        }
        $droppedColumns = array_values(array_filter($from->getColumns(), static fn (Column $c): bool => !$to->hasColumn($c->name)));

        [$addedIndexes, $droppedIndexes] = self::compareByName($from->getIndexes(), $to->getIndexes(), self::sameIndex(...));
        [$addedForeignKeys, $droppedForeignKeys] = self::compareByName($from->getForeignKeys(), $to->getForeignKeys(), self::sameForeignKey(...));

        return new TableDiff(
            $to->name,
            $from,
            $to,
            $added,
            $droppedColumns,
            $changed,
            $from->primaryKey,
            $to->primaryKey,
            $addedIndexes,
            $droppedIndexes,
            $addedForeignKeys,
            $droppedForeignKeys,
        );
    }

    /**
     * @internal liste de noms comparable sans tenir compte de la casse
     *
     * @param list<string>|null $names
     *
     * @return list<string>|null
     */
    public static function normalizedList(?array $names): ?array
    {
        return null === $names ? null : array_map(strtolower(...), $names);
    }

    /**
     * @return list<'type'|'nullable'|'default'|'length'|'precision'|'scale'|'autoincrement'|'fixed'>
     */
    private static function changedProperties(Column $from, Column $to): array
    {
        return array_values(array_filter(self::COLUMN_PROPERTIES, static fn (string $property): bool => $from->{$property} !== $to->{$property}));
    }

    /**
     * @template T of Index|ForeignKey
     *
     * @param list<T>              $from
     * @param list<T>              $to
     * @param \Closure(T, T): bool $same
     *
     * @return array{list<T>, list<T>} ajoutés, supprimés
     */
    private static function compareByName(array $from, array $to, \Closure $same): array
    {
        $fromByName = [];
        foreach ($from as $item) {
            $fromByName[strtolower($item->name)] = $item;
        }
        $toByName = [];
        foreach ($to as $item) {
            $toByName[strtolower($item->name)] = $item;
        }

        $added = [];
        $dropped = [];
        foreach ($toByName as $key => $item) {
            if (!isset($fromByName[$key])) {
                $added[] = $item;
            } elseif (!$same($fromByName[$key], $item)) {
                $dropped[] = $fromByName[$key];
                $added[] = $item;
            }
        }
        foreach ($fromByName as $key => $item) {
            if (!isset($toByName[$key])) {
                $dropped[] = $item;
            }
        }

        return [$added, $dropped];
    }

    private static function sameIndex(Index $a, Index $b): bool
    {
        return $a->unique === $b->unique
            && self::normalizedList($a->columns) === self::normalizedList($b->columns)
            && strtolower($a->whereNull ?? '') === strtolower($b->whereNull ?? '')
            && $a->kind === $b->kind
            && $a->distance === $b->distance
            && $a->m === $b->m
            && $a->efConstruction === $b->efConstruction
            && $a->language === $b->language;
    }

    private static function sameForeignKey(ForeignKey $a, ForeignKey $b): bool
    {
        return $a->onDelete === $b->onDelete
            && 0 === strcasecmp($a->foreignTable, $b->foreignTable)
            && self::normalizedList($a->localColumns) === self::normalizedList($b->localColumns)
            && self::normalizedList($a->foreignColumns) === self::normalizedList($b->foreignColumns);
    }

    /**
     * Le point de départ d'une séquence n'a plus de sens une fois créée : seul le pas est comparé.
     *
     * @return array{list<Sequence>, list<Sequence>, list<Sequence>} créées, supprimées, modifiées
     */
    private static function compareSequences(Schema $from, Schema $to): array
    {
        $fromByName = [];
        foreach ($from->getSequences() as $sequence) {
            $fromByName[strtolower($sequence->name)] = $sequence;
        }

        $created = [];
        $altered = [];
        $kept = [];
        foreach ($to->getSequences() as $sequence) {
            $key = strtolower($sequence->name);
            $kept[$key] = true;
            if (!isset($fromByName[$key])) {
                $created[] = $sequence;
            } elseif ($fromByName[$key]->increment !== $sequence->increment) {
                $altered[] = $sequence;
            }
        }

        $dropped = array_values(array_filter($fromByName, static fn (Sequence $s): bool => !isset($kept[strtolower($s->name)])));

        return [$created, $dropped, $altered];
    }

    private function assertForeignTablesExist(Schema $schema): void
    {
        foreach ($schema->getTables() as $table) {
            foreach ($table->getForeignKeys() as $foreignKey) {
                if (!$schema->hasTable($foreignKey->foreignTable)) {
                    throw InvalidSchemaException::unknownForeignTable($table->name, $foreignKey->name, $foreignKey->foreignTable);
                }
            }
        }
    }
}
