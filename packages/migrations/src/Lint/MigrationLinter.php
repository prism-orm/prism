<?php

declare(strict_types=1);

namespace Prism\Migrations\Lint;

use Prism\Dbal\Connection;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\AbstractMySqlPlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\IndexKind;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\SchemaManager;
use Prism\Migrations\AbstractMigration;
use Prism\Migrations\DataMigration;
use Prism\Migrations\Exception\MigrationException;
use Prism\Migrations\MigrationBuilder;
use Prism\Migrations\MigrationFinder;
use Prism\Migrations\Operation;
use Prism\Migrations\VersionStorage;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tools\SchemaTool;

/**
 * Analyse les migrations en attente avant leur exécution (RG-06.5) : leurs opérations typées
 * (ADR 0012), appliquées une à une au schéma, croisées avec les données de la base, le mapping
 * des entités et la plateforme. Une table créée par une migration en attente est vide.
 *
 * | Code | Sévérité | Cas |
 * | --- | --- | --- |
 * | L00 | erreur | opération impossible sur le schéma |
 * | L01 | erreur | colonne NOT NULL sans défaut ajoutée à une table non vide |
 * | L02 | erreur | colonne passée à NOT NULL alors qu'elle contient des NULL |
 * | L03 | erreur | colonne supprimée, encore mappée |
 * | L04 | erreur | table supprimée, encore mappée |
 * | L05 | erreur | table ou colonne renommée dont l'ancien nom est encore mappé |
 * | L06 | erreur | table supprimée, encore visée par une clé étrangère |
 * | L07 | erreur | index unique sur des doublons |
 * | L08 | erreur | clé étrangère sur des lignes orphelines |
 * | L09 | avertissement | index sans CONCURRENTLY sur une table non vide, sous PostgreSQL |
 * | L10 | avertissement | DDL sur une table de plus d'un million de lignes, sous MySQL et MariaDB |
 * | L11 | avertissement | table non vide supprimée |
 * | L12 | avertissement | colonne supprimée d'une table non vide |
 * | L13 | avertissement | colonne rétrécie sur une table non vide |
 * | L14 | avertissement | type de colonne changé sur une table non vide |
 * | L15 | avertissement | clé primaire changée sur une table non vide |
 * | L16 | avertissement | SQL brut, ni portable ni analysable |
 * | L17 | avertissement | plusieurs DDL sans transaction, sous MySQL et MariaDB (RG-06.6) |
 * | L18 | avertissement | table créée sans clé primaire |
 * | L19 | avertissement | migration irréversible (RG-06.7) |
 * | L20 | avertissement | index vectoriel créé sur une table non vide (ADR 0017) |
 * | L21 | avertissement | index vectoriel sur une table #[Tenanted] dont un tenant, parmi d'autres, dépasse le seuil, sous MariaDB (RG-09.11) |
 *
 * @internal
 */
final class MigrationLinter
{
    private readonly DataInspector $inspector;

    /** @var array<string, string|null> nom courant → nom en base (null : table créée par une migration en attente) */
    private array $realTables = [];

    /** @var array<string, array<string, string>> table courante → colonne courante → colonne en base */
    private array $realColumns = [];

    /** @var list<Finding> */
    private array $findings = [];

    /** @var array<string, string> table #[Tenanted] → colonne du tenant, d'après le mapping */
    private array $tenantColumns = [];

    /**
     * @param MetadataFactory|null $mapping     sans lui, L03 à L05 ne sont pas vérifiées
     * @param int                  $largeTable  seuil de L10, en lignes
     * @param int                  $largeTenant seuil de L21, en lignes d'un tenant
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly MigrationFinder $finder,
        private readonly ?MetadataFactory $mapping = null,
        private readonly int $largeTable = 1_000_000,
        ?DataInspector $inspector = null,
        private readonly int $largeTenant = 10_000,
    ) {
        $this->inspector = $inspector ?? new DatabaseInspector($connection);
    }

    /**
     * Les migrations en attente, jusqu'à une version (comprise) ou toutes.
     *
     * @throws MigrationException fichier de migration invalide
     */
    public function lint(?string $to = null): LintResult
    {
        $executed = new VersionStorage($this->connection)->executed();
        $pending = array_filter(
            $this->finder->find(),
            static fn (string $version): bool => !isset($executed[$version]) && (null === $to || strcmp(substr($version, 7, 14), substr($to, 7, 14)) <= 0),
            \ARRAY_FILTER_USE_KEY,
        );

        return $this->lintMigrations($pending, new SchemaManager($this->connection)->introspect());
    }

    /**
     * Des migrations, dans l'ordre, sur un schéma donné.
     *
     * @param array<string, AbstractMigration|DataMigration> $migrations par version
     *
     * @internal lint() y passe les migrations en attente ; les tests, les leurs
     */
    public function lintMigrations(array $migrations, Schema $schema): LintResult
    {
        $this->findings = [];
        $this->realTables = [];
        $this->realColumns = [];
        foreach ($schema->getTables() as $table) {
            $this->realTables[strtolower($table->name)] = $table->name;
            foreach ($table->getColumns() as $column) {
                $this->realColumns[strtolower($table->name)][strtolower($column->name)] = $column->name;
            }
        }
        $mapped = null === $this->mapping ? null : new SchemaTool($this->connection->getPlatform(), $this->connection->getTypeRegistry())->fromMetadata($this->mapping->getAllMetadata());
        $this->tenantColumns = [];
        foreach (null === $this->mapping ? [] : $this->mapping->getAllMetadata() as $class) {
            if (null !== $class->tenancy) {
                $this->tenantColumns[strtolower($class->tableName)] = $class->tenancy->column;
            }
        }

        foreach ($migrations as $version => $migration) {
            $ignored = self::ignored($migration);
            $before = \count($this->findings);
            if ($migration instanceof AbstractMigration) {
                $schema = $this->migration($version, $migration, $schema, $mapped);
            } elseif (!$migration->isReversible()) {
                $this->find($version, 'L19', Severity::Warning, 'It has no revert(): it cannot be reverted.');
            }
            $this->findings = [...\array_slice($this->findings, 0, $before), ...array_map(
                static fn (Finding $f): Finding => isset($ignored[$f->code]) ? new Finding($f->version, $f->code, $f->severity, $f->message, $ignored[$f->code]) : $f,
                \array_slice($this->findings, $before),
            )];
        }

        return new LintResult(array_map(strval(...), array_keys($migrations)), $this->findings, null !== $mapped);
    }

    private function migration(string $version, AbstractMigration $migration, Schema $schema, ?Schema $mapped): Schema
    {
        $platform = $this->connection->getPlatform();
        $builder = new MigrationBuilder($platform);
        $migration->up($builder);
        $large = [];

        foreach ($builder->operations() as $operation) {
            try {
                $after = $operation instanceof Operation\SchemaOperation ? $operation->apply($schema) : $schema;
            } catch (InvalidSchemaException $e) {
                $this->find($version, 'L00', Severity::Error, \sprintf('The operation %s cannot apply to the schema: %s', self::name($operation), $e->getMessage()));
                continue;
            }
            $this->operation($version, $operation, $schema, $after, $mapped);
            $table = self::tableOf($operation);
            if (null !== $table && $platform instanceof AbstractMySqlPlatform && !isset($large[strtolower($table)]) && $this->isLarge($table)) {
                $large[strtolower($table)] = true;
                $this->find($version, 'L10', Severity::Warning, \sprintf('%s has more than %d rows: %s may copy and lock it for a long time on %s; plan a maintenance window or an online schema change tool.', $table, $this->largeTable, self::name($operation), $platform->getName()));
            }
            $this->track($operation);
            $schema = $after;
        }

        $ddl = array_filter($builder->operations(), static fn (Operation\Operation $o): bool => $o instanceof Operation\SchemaOperation);
        if ($platform instanceof AbstractMySqlPlatform && \count($ddl) > 1) {
            $this->find($version, 'L17', Severity::Warning, \sprintf('It runs %d schema operations without a transaction on %s: if one fails, the ones before it stay applied. Split it, or keep it small.', \count($ddl), $platform->getName()));
        }
        if (AbstractMigration::class === new \ReflectionMethod($migration, 'down')->getDeclaringClass()->getName()) {
            $this->find($version, 'L19', Severity::Warning, 'It has no down(): it cannot be reverted.');
        }

        return $schema;
    }

    private function operation(string $version, Operation\Operation $o, Schema $before, Schema $after, ?Schema $mapped): void
    {
        $platform = $this->connection->getPlatform();
        $rows = fn (string $table): bool => null !== ($real = $this->realTable($table)) && !$this->inspector->isEmpty($real);

        if ($o instanceof Operation\AddColumn && !$o->column->nullable && null === $o->column->default && !$o->column->autoincrement && $rows($o->table)) {
            $this->find($version, 'L01', Severity::Error, \sprintf('%s.%s is added NOT NULL without a default to a table with rows: the existing rows have no value for it. Add it nullable, fill it, then make it NOT NULL; or give it a default.', $o->table, $o->column->name));
        }
        if ($o instanceof Operation\ChangeColumn) {
            $from = $before->getTable($o->table)->getColumn($o->column->name);
            $to = $o->column;
            $column = $this->realColumn($o->table, $from->name);
            if ($from->nullable && !$to->nullable && null !== $column && $this->inspector->hasNulls((string) $this->realTable($o->table), $column)) {
                $this->find($version, 'L02', Severity::Error, \sprintf('%s.%s becomes NOT NULL but contains NULL values: fill them first.', $o->table, $to->name));
            }
            if ($rows($o->table)) {
                if ($from->type !== $to->type) {
                    $this->find($version, 'L14', Severity::Warning, \sprintf('%s.%s changes from %s to %s on a table with rows: check that every value converts.', $o->table, $to->name, $from->type, $to->type));
                } elseif (self::narrower($from, $to)) {
                    $this->find($version, 'L13', Severity::Warning, \sprintf('%s.%s gets narrower on a table with rows: longer values would be truncated or rejected.', $o->table, $to->name));
                }
            }
        }
        if ($o instanceof Operation\DropColumn) {
            if (null !== $mapped && $mapped->hasTable($o->table) && $mapped->getTable($o->table)->hasColumn($o->column)) {
                $this->find($version, 'L03', Severity::Error, \sprintf('%s.%s is dropped but an entity still maps it: remove the field from the entity first.', $o->table, $o->column));
            }
            if ($rows($o->table)) {
                $this->find($version, 'L12', Severity::Warning, \sprintf('%s.%s is dropped from a table with rows: its data is lost.', $o->table, $o->column));
            }
        }
        if ($o instanceof Operation\DropTable) {
            if (null !== $mapped && $mapped->hasTable($o->table)) {
                $this->find($version, 'L04', Severity::Error, \sprintf('The table %s is dropped but an entity still maps it.', $o->table));
            }
            foreach ($after->getTables() as $table) {
                foreach ($table->getForeignKeys() as $fk) {
                    if (0 === strcasecmp($fk->foreignTable, $o->table)) {
                        $this->find($version, 'L06', Severity::Error, \sprintf('The table %s is dropped but %s.%s still references it (%s): drop that foreign key first.', $o->table, $table->name, implode(', ', $fk->localColumns), $fk->name));
                    }
                }
            }
            if ($rows($o->table)) {
                $this->find($version, 'L11', Severity::Warning, \sprintf('The table %s has rows: they are lost.', $o->table));
            }
        }
        if (null !== $mapped && $o instanceof Operation\RenameTable && $mapped->hasTable($o->from) && !$mapped->hasTable($o->to)) {
            $this->find($version, 'L05', Severity::Error, \sprintf('The table %s is renamed to %s but an entity still maps %s.', $o->from, $o->to, $o->from));
        }
        if (null !== $mapped && $o instanceof Operation\RenameColumn && $mapped->hasTable($o->table) && $mapped->getTable($o->table)->hasColumn($o->from) && !$mapped->getTable($o->table)->hasColumn($o->to)) {
            $this->find($version, 'L05', Severity::Error, \sprintf('%s.%s is renamed to %s but an entity still maps %s.', $o->table, $o->from, $o->to, $o->from));
        }
        if ($o instanceof Operation\CreateIndex) {
            $real = $this->realTable($o->table);
            $columns = array_map(fn (string $c): ?string => $this->realColumn($o->table, $c), $o->columns);
            if ($o->unique && null !== $real && !\in_array(null, $columns, true) && $this->inspector->hasDuplicates($real, array_values(array_filter($columns, static fn (?string $c): bool => null !== $c)))) {
                $this->find($version, 'L07', Severity::Error, \sprintf('The unique index on %s (%s) cannot be created: the column(s) already hold duplicates.', $o->table, implode(', ', $o->columns)));
            }
            if ($platform->supportsConcurrentIndexCreation() && !$o->concurrently && $rows($o->table)) {
                $this->find($version, 'L09', Severity::Warning, \sprintf('The index on %s (%s) blocks writes while it is built on %s: pass concurrently: true.', $o->table, implode(', ', $o->columns), $platform->getName()));
            }
            // SQLite ne crée pas d'index vectoriel : rien à construire
            if (IndexKind::Vector === $o->kind && null !== $platform->getCanonicalIndex($o->index()) && $rows($o->table)) {
                $this->find($version, 'L20', Severity::Warning, \sprintf('The vector index on %s.%s is built over the existing rows on %s: an HNSW graph takes memory and time to build. Plan it, and size the build memory (maintenance_work_mem under PostgreSQL, mhnsw_max_cache_size under MariaDB).', $o->table, $o->columns[0], $platform->getName()));
            }
            if (IndexKind::Vector === $o->kind && $platform->vectorIndexMayMissRows()) {
                $this->largeTenant($version, $o->table);
            }
        }
        if ($o instanceof Operation\AddForeignKey) {
            $real = $this->realTable($o->table);
            $foreign = $this->realTable($o->foreignTable);
            $local = array_map(fn (string $c): ?string => $this->realColumn($o->table, $c), $o->localColumns);
            $remote = array_map(fn (string $c): ?string => $this->realColumn($o->foreignTable, $c), $o->foreignColumns);
            if (null !== $real && null !== $foreign && !\in_array(null, [...$local, ...$remote], true)
                && $this->inspector->hasOrphans($real, array_values(array_filter($local, static fn (?string $c): bool => null !== $c)), $foreign, array_values(array_filter($remote, static fn (?string $c): bool => null !== $c)))) {
                $this->find($version, 'L08', Severity::Error, \sprintf('The foreign key %s (%s) → %s cannot be added: some rows reference no %s row.', $o->table, implode(', ', $o->localColumns), $o->foreignTable, $o->foreignTable));
            }
        }
        if ($o instanceof Operation\ChangePrimaryKey && $rows($o->table)) {
            $this->find($version, 'L15', Severity::Warning, \sprintf('The primary key of %s changes on a table with rows: check that the new key is unique, and the references to the old one.', $o->table));
        }
        if ($o instanceof Operation\Sql) {
            $ddl = 1 === preg_match('/^\s*(ALTER|CREATE|DROP|RENAME|TRUNCATE)\b/i', $o->sql);
            $this->find($version, 'L16', Severity::Warning, $ddl
                ? 'Raw DDL in sql(): it is neither portable nor analysed, and the next diff will not know about it; use the schema operations.'
                : 'Raw SQL in sql(): it is neither portable nor analysed by the linter.');
        }
        if ($o instanceof Operation\CreateTable && null === $o->table->primaryKey) {
            $this->find($version, 'L18', Severity::Warning, \sprintf('The table %s has no primary key: an entity cannot map it, and some replicas refuse it.', $o->table->name));
        }
    }

    /**
     * RG-09.11 : sous un filtre sélectif, l'index HNSW de MariaDB rend trop peu de lignes, et
     * nearest() relance une recherche exacte sur les lignes du tenant (ADR 0017) ; son coût suit
     * leur nombre. Un tenant seul dans la table n'est pas sélectif : l'index trouve ses lignes.
     */
    private function largeTenant(string $version, string $table): void
    {
        $real = $this->realTable($table);
        $column = $this->tenantColumns[strtolower($table)] ?? null;
        $realColumn = null === $column ? null : $this->realColumn($table, $column);
        if (null === $real || null === $realColumn) {
            return;
        }
        $largest = $this->inspector->largestGroup($real, $realColumn);
        if ($largest > $this->largeTenant && $this->inspector->hasMoreRowsThan($real, $largest)) {
            $this->find($version, 'L21', Severity::Warning, \sprintf('%s is #[Tenanted] and one of its tenants has %d rows among others: on %s, a nearest() filtered by that tenant often falls back to an exact search over its rows, whose cost grows with them. Keep tenant_id indexed, size innodb_buffer_pool_size to hold the vectors, or prefer PostgreSQL, whose iterative index scan keeps the index.', $table, $largest, $this->connection->getPlatform()->getName()));
        }
    }

    /**
     * Le nom en base d'une table que les opérations précédentes ont pu renommer ; null si elle
     * n'existe pas encore en base.
     */
    private function realTable(string $table): ?string
    {
        return $this->realTables[strtolower($table)] ?? null;
    }

    private function realColumn(string $table, string $column): ?string
    {
        return null === $this->realTable($table) ? null : ($this->realColumns[strtolower($table)][strtolower($column)] ?? null);
    }

    // les noms suivent les renommages ; une table ou une colonne créée n'a pas de données
    private function track(Operation\Operation $o): void
    {
        if ($o instanceof Operation\RenameTable) {
            $this->realTables[strtolower($o->to)] = $this->realTables[strtolower($o->from)] ?? null;
            $this->realColumns[strtolower($o->to)] = $this->realColumns[strtolower($o->from)] ?? [];
            unset($this->realTables[strtolower($o->from)], $this->realColumns[strtolower($o->from)]);
        } elseif ($o instanceof Operation\RenameColumn && isset($this->realColumns[strtolower($o->table)][strtolower($o->from)])) {
            $this->realColumns[strtolower($o->table)][strtolower($o->to)] = $this->realColumns[strtolower($o->table)][strtolower($o->from)];
            unset($this->realColumns[strtolower($o->table)][strtolower($o->from)]);
        } elseif ($o instanceof Operation\CreateTable) {
            $this->realTables[strtolower($o->table->name)] = null;
        } elseif ($o instanceof Operation\DropTable) {
            unset($this->realTables[strtolower($o->table)], $this->realColumns[strtolower($o->table)]);
        } elseif ($o instanceof Operation\DropColumn) {
            unset($this->realColumns[strtolower($o->table)][strtolower($o->column)]);
        }
    }

    private function isLarge(string $table): bool
    {
        $real = $this->realTable($table);

        return null !== $real && $this->inspector->hasMoreRowsThan($real, $this->largeTable);
    }

    private static function tableOf(Operation\Operation $o): ?string
    {
        return match (true) {
            $o instanceof Operation\AddColumn, $o instanceof Operation\ChangeColumn, $o instanceof Operation\DropColumn,
            $o instanceof Operation\RenameColumn, $o instanceof Operation\CreateIndex, $o instanceof Operation\DropIndex,
            $o instanceof Operation\AddForeignKey, $o instanceof Operation\DropForeignKey, $o instanceof Operation\ChangePrimaryKey => $o->table,
            default => null,
        };
    }

    private static function name(Operation\Operation $operation): string
    {
        $name = $operation->describe()['operation'] ?? null;

        return \is_string($name) ? $name : '?';
    }

    private static function narrower(Column $from, Column $to): bool
    {
        $smaller = static fn (?int $a, ?int $b): bool => null !== $a && null !== $b && $b < $a;

        return $smaller($from->length, $to->length) || $smaller($from->precision, $to->precision) || $smaller($from->scale, $to->scale);
    }

    /**
     * @return array<string, string> code → raison
     */
    private static function ignored(AbstractMigration|DataMigration $migration): array
    {
        $ignored = [];
        foreach (new \ReflectionClass($migration)->getAttributes(IgnoreLint::class) as $attribute) {
            $ignore = $attribute->newInstance();
            $ignored[$ignore->code] = $ignore->reason;
        }

        return $ignored;
    }

    private function find(string $version, string $code, Severity $severity, string $message): void
    {
        $this->findings[] = new Finding($version, $code, $severity, $message);
    }
}
