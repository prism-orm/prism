<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ForeignKey;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;

/**
 * Les objets de schéma en tableaux de scalaires, pour une description stable.
 *
 * @internal
 */
final class Describe
{
    /**
     * @return array<string, mixed>
     */
    public static function column(Column $column): array
    {
        return ['name' => $column->name, 'type' => $column->type, 'nullable' => $column->nullable, 'default' => $column->default, 'length' => $column->length, 'precision' => $column->precision, 'scale' => $column->scale, 'autoincrement' => $column->autoincrement];
    }

    /**
     * @return array<string, mixed>
     */
    public static function index(Index $index): array
    {
        return ['name' => $index->name, 'columns' => $index->columns, 'unique' => $index->unique];
    }

    /**
     * @return array<string, mixed>
     */
    public static function foreignKey(ForeignKey $foreignKey): array
    {
        return ['name' => $foreignKey->name, 'localColumns' => $foreignKey->localColumns, 'foreignTable' => $foreignKey->foreignTable, 'foreignColumns' => $foreignKey->foreignColumns, 'onDelete' => $foreignKey->onDelete->name];
    }

    /**
     * @return array<string, mixed>
     */
    public static function sequence(Sequence $sequence): array
    {
        return ['name' => $sequence->name, 'start' => $sequence->start, 'increment' => $sequence->increment];
    }

    /**
     * @return array<string, mixed>
     */
    public static function table(Table $table): array
    {
        return [
            'name' => $table->name,
            'columns' => array_map(self::column(...), $table->getColumns()),
            'primaryKey' => $table->primaryKey,
            'indexes' => array_map(self::index(...), $table->getIndexes()),
            'foreignKeys' => array_map(self::foreignKey(...), $table->getForeignKeys()),
        ];
    }
}
