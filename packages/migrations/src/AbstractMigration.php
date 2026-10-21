<?php

declare(strict_types=1);

namespace Prism\Migrations;

use Prism\Migrations\Exception\IrreversibleMigrationException;

/**
 * Une migration versionnée (ADR 0012), nommée VersionYYYYMMDDHHMMSS (RG-06.1) : up() et down()
 * enregistrent des opérations de schéma, que la Platform de la connexion traduit en SQL.
 *
 *     final class Version20261021143000 extends AbstractMigration
 *     {
 *         public function up(MigrationBuilder $m): void
 *         {
 *             $m->addColumn('invoice', new Column('paid_at', 'datetime_immutable', nullable: true));
 *         }
 *
 *         public function down(MigrationBuilder $m): void
 *         {
 *             $m->dropColumn('invoice', 'paid_at');
 *         }
 *     }
 *
 * @api
 */
abstract class AbstractMigration
{
    abstract public function up(MigrationBuilder $m): void;

    /**
     * L'inverse de up() ; sans lui, la migration est irréversible (RG-06.7).
     *
     * @throws IrreversibleMigrationException
     */
    public function down(MigrationBuilder $m): void
    {
        throw IrreversibleMigrationException::forVersion(self::versionOf(static::class));
    }

    public function getDescription(): string
    {
        return '';
    }

    /**
     * Faux pour exécuter la migration hors transaction. Sous MySQL et MariaDB, une migration qui
     * contient du DDL l'est toujours : leur DDL valide la transaction implicitement (RG-06.6).
     */
    public function isTransactional(): bool
    {
        return true;
    }

    /**
     * La version d'une classe de migration : son nom court (Version20261021143000).
     *
     * @internal
     */
    public static function versionOf(string $class): string
    {
        $position = strrpos($class, '\\');

        return false === $position ? $class : substr($class, $position + 1);
    }
}
