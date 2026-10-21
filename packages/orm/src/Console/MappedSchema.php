<?php

declare(strict_types=1);

namespace Prism\Orm\Console;

use Prism\Dbal\Connection;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\SchemaDiff;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tools\SchemaTool;

/**
 * Logique commune aux commandes db:schema:* : schéma cible déduit du mapping, schéma réel
 * restreint à ce que le mapping connaît, diff et application.
 *
 * @internal
 */
final class MappedSchema
{
    /**
     * Préfixe réservé aux tables et séquences que Prism gère lui-même (prism_migration_versions,
     * prism_audit_log) : jamais vues comme non mappées, donc jamais supprimées (ADR 0012).
     */
    public const string RESERVED_PREFIX = 'prism_';

    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataFactory $metadata,
    ) {
    }

    public function target(): Schema
    {
        return new SchemaTool($this->connection->getPlatform(), $this->connection->getTypeRegistry())->fromMetadata($this->metadata->getAllMetadata());
    }

    /**
     * Base réelle. Sans $complete, seulement les tables et séquences du schéma cible : une table
     * que le mapping ne connaît pas n'est jamais comparée, donc jamais supprimée (RG-02.9). Avec
     * $complete, toutes, sauf celles que Prism gère lui-même (préfixe prism_ non mappé).
     */
    public function actual(Schema $target, bool $complete = false): Schema
    {
        $actual = $this->connection->createSchemaManager()->introspect();
        $tables = array_map(static fn (Table $t): string => strtolower($t->name), $target->getTables());
        $sequences = array_map(static fn (Sequence $s): string => strtolower($s->name), $target->getSequences());
        $kept = static fn (string $name, array $mapped): bool => \in_array(strtolower($name), $mapped, true)
            || ($complete && !str_starts_with(strtolower($name), self::RESERVED_PREFIX));

        return new Schema(
            array_values(array_filter($actual->getTables(), static fn (Table $t): bool => $kept($t->name, $tables))),
            array_values(array_filter($actual->getSequences(), static fn (Sequence $s): bool => $kept($s->name, $sequences))),
        );
    }

    public function diff(Schema $from, Schema $to): SchemaDiff
    {
        return new Comparator($this->connection->getPlatform())->compare($from, $to);
    }

    /**
     * @return list<string>
     */
    public function sql(SchemaDiff $diff): array
    {
        return $this->connection->getPlatform()->getMigrationSql($diff);
    }

    /**
     * @return list<string> requêtes exécutées
     */
    public function migrate(SchemaDiff $diff): array
    {
        return $this->connection->createSchemaManager()->migrate($diff);
    }

    public function getMetadataFactory(): MetadataFactory
    {
        return $this->metadata;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
