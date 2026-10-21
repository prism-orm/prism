<?php

declare(strict_types=1);

namespace Prism\Migrations;

use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;

/**
 * Les opérations d'une migration (ADR 0012), dans l'ordre où elle les écrit. Aucune n'est
 * exécutée ici : le Migrator les applique au schéma introspecté, et la Platform en écrit le SQL.
 *
 * @api
 */
final class MigrationBuilder
{
    /** @var list<Operation\Operation> */
    private array $operations = [];

    /**
     * @internal créé par le Migrator
     */
    public function __construct(private readonly AbstractPlatform $platform)
    {
    }

    /**
     * La plateforme qui exécutera la migration, pour réserver du SQL brut à une base.
     */
    public function platform(): AbstractPlatform
    {
        return $this->platform;
    }

    public function createTable(Table $table): void
    {
        $this->operations[] = new Operation\CreateTable($table);
    }

    public function dropTable(string $table): void
    {
        $this->operations[] = new Operation\DropTable($table);
    }

    public function renameTable(string $from, string $to): void
    {
        $this->operations[] = new Operation\RenameTable($from, $to);
    }

    public function addColumn(string $table, Column $column): void
    {
        $this->operations[] = new Operation\AddColumn($table, $column);
    }

    /**
     * Remplace la définition de la colonne de même nom.
     */
    public function changeColumn(string $table, Column $column): void
    {
        $this->operations[] = new Operation\ChangeColumn($table, $column);
    }

    public function dropColumn(string $table, string $column): void
    {
        $this->operations[] = new Operation\DropColumn($table, $column);
    }

    public function renameColumn(string $table, string $from, string $to): void
    {
        $this->operations[] = new Operation\RenameColumn($table, $from, $to);
    }

    /**
     * Index nommé de façon déterministe (RG-01.7) quand $name est null.
     *
     * @param list<string> $columns
     */
    public function createIndex(string $table, array $columns, bool $unique = false, ?string $name = null): void
    {
        $this->operations[] = new Operation\CreateIndex($table, $columns, $unique, $name);
    }

    /**
     * @param list<string>|string $index ses colonnes, ou son nom
     */
    public function dropIndex(string $table, array|string $index): void
    {
        $this->operations[] = new Operation\DropIndex($table, $index);
    }

    /**
     * Clé étrangère nommée de façon déterministe (RG-01.7) quand $name est null.
     *
     * @param list<string> $localColumns
     * @param list<string> $foreignColumns
     */
    public function addForeignKey(string $table, array $localColumns, string $foreignTable, array $foreignColumns, ForeignKeyAction $onDelete = ForeignKeyAction::NoAction, ?string $name = null): void
    {
        $this->operations[] = new Operation\AddForeignKey($table, $localColumns, $foreignTable, $foreignColumns, $onDelete, $name);
    }

    /**
     * @param list<string>|string $foreignKey ses colonnes locales, ou son nom
     */
    public function dropForeignKey(string $table, array|string $foreignKey): void
    {
        $this->operations[] = new Operation\DropForeignKey($table, $foreignKey);
    }

    public function createSequence(Sequence $sequence): void
    {
        $this->operations[] = new Operation\CreateSequence($sequence);
    }

    public function dropSequence(string $sequence): void
    {
        $this->operations[] = new Operation\DropSequence($sequence);
    }

    /**
     * SQL brut, avec ses paramètres liés : ni portable ni analysable, signalé par le linter.
     *
     * @param array<int<0, max>|string, mixed> $params
     */
    public function sql(string $sql, array $params = []): void
    {
        $this->operations[] = new Operation\Sql($sql, $params);
    }

    /**
     * @return list<Operation\Operation>
     *
     * @internal
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * Empreinte stable des opérations (RG-06.4) : indépendante de la mise en forme du fichier.
     *
     * @internal
     */
    public function checksum(): string
    {
        return hash('sha256', json_encode(array_map(static fn (Operation\Operation $o): array => $o->describe(), $this->operations), \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));
    }
}
