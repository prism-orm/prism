<?php

declare(strict_types=1);

namespace Prism\Migrations\Generator;

use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Table;
use Prism\Migrations\Operation\Operation;
use Prism\Migrations\Operation\RenameColumn;
use Prism\Migrations\Operation\RenameSequence;
use Prism\Migrations\Operation\RenameTable;

/**
 * RG-06.3 : le Comparator ne devine jamais un renommage (RG-01.9) ; le détecteur repère les
 * candidats et les fait confirmer. Une table supprimée et une table créée aux mêmes colonnes,
 * une colonne supprimée et une colonne ajoutée de même type dans une table.
 *
 * Confirmé : un renommage, appliqué au schéma de départ ; la séquence {table}_id_seq suit sa
 * table. Sans question possible (mode non interactif) : drop + add, et un avertissement.
 *
 * @internal
 */
final class RenameDetector
{
    public function __construct(private readonly AbstractPlatform $platform)
    {
    }

    /**
     * @param (\Closure(string): bool)|null $confirm      null : mode non interactif
     * @param bool                          $dropUnmapped vrai si l'ancienne table sera supprimée (--complete)
     */
    public function detect(Schema $from, Schema $to, ?\Closure $confirm, bool $dropUnmapped = true): DetectedRenames
    {
        $renames = [];
        $warnings = [];
        $diff = new Comparator($this->platform)->compare($from, $to);

        $created = $diff->createdTables;
        foreach ($diff->droppedTables as $dropped) {
            foreach ($created as $i => $table) {
                if (!self::sameColumns($dropped, $table)) {
                    continue;
                }
                if (null === $confirm) {
                    $warnings[] = $dropUnmapped
                        ? \sprintf('The table %s is dropped and %s created with the same columns: if it is a rename, replace the dropTable() and createTable() below with $m->renameTable(\'%s\', \'%s\') to keep the data.', $dropped->name, $table->name, $dropped->name, $table->name)
                        : \sprintf('The table %s is no longer mapped and %s is created with the same columns: if it is a rename, replace the createTable() below with $m->renameTable(\'%s\', \'%s\') to keep the data.', $dropped->name, $table->name, $dropped->name, $table->name);
                } elseif ($confirm(\sprintf('Rename the table %s to %s?', $dropped->name, $table->name))) {
                    $renames[] = new RenameTable($dropped->name, $table->name);
                    $from = $from->withRenamedTable($dropped->name, $table->name);
                    [$from, $renames] = $this->sequence($from, $to, $dropped->name, $table->name, $renames);
                    unset($created[$i]);
                }
                break;
            }
        }

        foreach (new Comparator($this->platform)->compare($from, $to)->alteredTables as $tableDiff) {
            $added = $tableDiff->addedColumns;
            foreach ($tableDiff->droppedColumns as $dropped) {
                foreach ($added as $i => $column) {
                    if ($dropped->type !== $column->type) {
                        continue;
                    }
                    if (null === $confirm) {
                        $warnings[] = \sprintf('%s.%s is dropped and %s.%s added, both %s: if it is a rename, replace the dropColumn() and addColumn() below with $m->renameColumn(\'%s\', \'%s\', \'%s\') to keep the data.', $tableDiff->name, $dropped->name, $tableDiff->name, $column->name, $column->type, $tableDiff->name, $dropped->name, $column->name);
                    } elseif ($confirm(\sprintf('Rename the column %s.%s to %s.%s?', $tableDiff->name, $dropped->name, $tableDiff->name, $column->name))) {
                        $renames[] = new RenameColumn($tableDiff->name, $dropped->name, $column->name);
                        $from = $from->withRenamedColumn($tableDiff->name, $dropped->name, $column->name);
                        unset($added[$i]);
                    }
                    break;
                }
            }
        }

        return new DetectedRenames($from, $renames, $warnings);
    }

    /**
     * La séquence par défaut {ancienne}_id_seq devient {nouvelle}_id_seq : renommée, son
     * compteur est gardé (sinon recréée, elle repartirait à 1).
     *
     * @param list<Operation> $renames
     *
     * @return array{Schema, list<Operation>}
     */
    private function sequence(Schema $from, Schema $to, string $oldTable, string $newTable, array $renames): array
    {
        $old = $oldTable . '_id_seq';
        $new = $newTable . '_id_seq';
        $has = static fn (Schema $schema, string $name): bool => [] !== array_filter($schema->getSequences(), static fn ($s): bool => 0 === strcasecmp($s->name, $name));
        if ($has($from, $old) && !$has($from, $new) && $has($to, $new) && !$has($to, $old)) {
            $renames[] = new RenameSequence($old, $new);
            $from = $from->withRenamedSequence($old, $new);
        }

        return [$from, $renames];
    }

    private static function sameColumns(Table $a, Table $b): bool
    {
        $signature = static fn (Table $t): array => array_map(static fn (Column $c): string => strtolower($c->name) . ':' . $c->type, $t->getColumns());

        return $signature($a) === $signature($b);
    }
}
