<?php

declare(strict_types=1);

namespace Prism\Migrations\Generator;

use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\Schema;
use Prism\Migrations\Operation;
use Prism\Migrations\Operation\RenameColumn;
use Prism\Migrations\Operation\RenameSequence;
use Prism\Migrations\Operation\RenameTable;

/**
 * Traduit l'écart entre la base et les entités en opérations (ADR 0012), chacune avec son
 * inverse : renommages confirmés d'abord (RG-06.3), puis le diff, table par table dans un ordre
 * que chaque opération accepte (clés et index retirés avant leurs colonnes, ajoutés après).
 *
 * down() n'est généré que s'il est sûr (RG-06.7) : sans retour possible pour une table, une
 * colonne ou une séquence supprimée, ni pour une colonne que l'inverse rétrécirait.
 *
 * @internal
 */
final class DiffTranslator
{
    public function __construct(private readonly AbstractPlatform $platform)
    {
    }

    /**
     * @param Schema                        $from         la base entière, hors tables prism_ : une
     *                                                    table renommée n'est plus mappée sous son
     *                                                    ancien nom, mais reste candidate (RG-06.3)
     * @param (\Closure(string): bool)|null $confirm      questions de renommage ; null : non interactif
     * @param bool                          $dropUnmapped --complete : supprimer aussi ce qui n'est pas
     *                                                    mappé ; sinon, l'ignorer (RG-02.9)
     */
    public function translate(Schema $from, Schema $to, ?\Closure $confirm, bool $dropUnmapped = false): GeneratedMigration
    {
        $detected = new RenameDetector($this->platform)->detect($from, $to, $confirm, $dropUnmapped);
        $base = $dropUnmapped ? $detected->from : self::mappedOnly($detected->from, $to);
        $diff = new Comparator($this->platform)->compare($base, $to);

        /** @var list<array{Operation\Operation, Operation\Operation|string}> $pairs l'opération, son inverse ou ce qu'elle détruit */
        $pairs = [];
        foreach ($detected->renames as $rename) {
            $pairs[] = [$rename, self::inverseRename($rename)];
        }
        foreach ($diff->createdSequences as $sequence) {
            $pairs[] = [new Operation\CreateSequence($sequence), new Operation\DropSequence($sequence->name)];
        }
        foreach ($diff->alteredSequences as $sequence) {
            $before = $sequence;
            foreach ($base->getSequences() as $current) {
                if (0 === strcasecmp($current->name, $sequence->name)) {
                    $before = $current;
                }
            }
            $pairs[] = [new Operation\ChangeSequence($sequence), new Operation\ChangeSequence($before)];
        }
        foreach ($diff->createdTables as $table) {
            $pairs[] = [new Operation\CreateTable($table), new Operation\DropTable($table->name)];
        }

        foreach ($diff->alteredTables as $t) {
            foreach ($t->droppedForeignKeys as $fk) {
                $pairs[] = [new Operation\DropForeignKey($t->name, $fk->name), new Operation\AddForeignKey($t->name, $fk->localColumns, $fk->foreignTable, $fk->foreignColumns, $fk->onDelete, $fk->name)];
            }
            foreach ($t->droppedIndexes as $index) {
                $pairs[] = [new Operation\DropIndex($t->name, $index->name), new Operation\CreateIndex($t->name, $index->columns, $index->unique, $index->name)];
            }
            foreach ($t->addedColumns as $column) {
                $pairs[] = [new Operation\AddColumn($t->name, $column), new Operation\DropColumn($t->name, $column->name)];
            }
            foreach ($t->changedColumns as $change) {
                $pairs[] = [new Operation\ChangeColumn($t->name, $change->to), self::narrows($change->to, $change->from)
                    ? \sprintf('it changes %s.%s, and changing it back could truncate or reject its data', $t->name, $change->from->name)
                    : new Operation\ChangeColumn($t->name, $change->from)];
            }
            if ($t->fromPrimaryKey !== $t->toPrimaryKey) {
                $pairs[] = [new Operation\ChangePrimaryKey($t->name, $t->toPrimaryKey), new Operation\ChangePrimaryKey($t->name, $t->fromPrimaryKey)];
            }
            foreach ($t->droppedColumns as $column) {
                $pairs[] = [new Operation\DropColumn($t->name, $column->name), \sprintf('it drops %s.%s, whose data cannot be restored', $t->name, $column->name)];
            }
            foreach ($t->addedIndexes as $index) {
                $pairs[] = [new Operation\CreateIndex($t->name, $index->columns, $index->unique, $index->name), new Operation\DropIndex($t->name, $index->name)];
            }
            foreach ($t->addedForeignKeys as $fk) {
                $pairs[] = [new Operation\AddForeignKey($t->name, $fk->localColumns, $fk->foreignTable, $fk->foreignColumns, $fk->onDelete, $fk->name), new Operation\DropForeignKey($t->name, $fk->name)];
            }
        }

        foreach ($diff->droppedTables as $table) {
            $pairs[] = [new Operation\DropTable($table->name), \sprintf('it drops the table %s and its data', $table->name)];
        }
        foreach ($diff->droppedSequences as $sequence) {
            $pairs[] = [new Operation\DropSequence($sequence->name), \sprintf('it drops the sequence %s and its counter', $sequence->name)];
        }

        $up = [];
        $down = [];
        $irreversible = [];
        foreach ($pairs as [$operation, $inverse]) {
            $up[] = $operation;
            if (\is_string($inverse)) {
                $irreversible[] = $inverse;
            } else {
                array_unshift($down, $inverse);
            }
        }

        return new GeneratedMigration($up, [] === $irreversible ? $down : [], $irreversible, $detected->warnings);
    }

    /**
     * Les seules tables et séquences que les entités connaissent.
     */
    private static function mappedOnly(Schema $schema, Schema $mapped): Schema
    {
        return new Schema(
            array_values(array_filter($schema->getTables(), static fn ($t): bool => $mapped->hasTable($t->name))),
            array_values(array_filter($schema->getSequences(), static fn ($s): bool => [] !== array_filter($mapped->getSequences(), static fn ($m): bool => 0 === strcasecmp($m->name, $s->name)))),
        );
    }

    private static function inverseRename(Operation\Operation $rename): Operation\Operation
    {
        return match (true) {
            $rename instanceof RenameTable => new RenameTable($rename->to, $rename->from),
            $rename instanceof RenameColumn => new RenameColumn($rename->table, $rename->to, $rename->from),
            $rename instanceof RenameSequence => new RenameSequence($rename->to, $rename->from),
            default => throw new \LogicException('Only renames are detected.'),
        };
    }

    /**
     * Vrai si passer de $from à $to peut tronquer ou refuser des données : autre type, longueur,
     * précision ou échelle réduite, ou NOT NULL retrouvé.
     */
    private static function narrows(Column $from, Column $to): bool
    {
        $smaller = static fn (?int $a, ?int $b): bool => null !== $b && (null === $a || $b < $a);

        return $from->type !== $to->type
            || $smaller($from->length, $to->length)
            || $smaller($from->precision, $to->precision)
            || $smaller($from->scale, $to->scale)
            || ($from->nullable && !$to->nullable);
    }
}
