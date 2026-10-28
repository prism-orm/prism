<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema;

use Prism\Dbal\Connection;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\AbstractMySqlPlatform;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Schema\Introspection\Introspector;
use Prism\Dbal\Schema\Introspection\MySqlIntrospector;
use Prism\Dbal\Schema\Introspection\PostgreSqlIntrospector;
use Prism\Dbal\Schema\Introspection\SqliteIntrospector;

/**
 * Lit le schéma réel de la base courante. Le SQL du catalogue vit dans la Platform ;
 * l'Introspector de la plateforme ne fait que lire les lignes.
 *
 * @internal
 */
final class SchemaManager
{
    private ?Introspector $introspector = null;

    /**
     * @internal utiliser Connection::createSchemaManager()
     */
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Tables (sans tables système) et séquences autonomes de la base courante.
     *
     * @throws InvalidSchemaException colonne d'un type sans équivalent Prism
     */
    public function introspect(): Schema
    {
        $tables = array_map($this->introspectTable(...), $this->listTableNames());

        return new Schema($tables, $this->introspector()->listSequences());
    }

    /**
     * @return list<string>
     */
    public function listTableNames(): array
    {
        return $this->introspector()->listTableNames();
    }

    /**
     * @throws InvalidSchemaException table absente, ou colonne d'un type sans équivalent Prism
     */
    public function introspectTable(string $name): Table
    {
        return $this->introspector()->introspectTable($name);
    }

    /**
     * Les colonnes des tables introspectées que Prism a lues avec une perte (un ENUM MySQL lu
     * comme un string), par « table.colonne ». db:import les signale.
     *
     * @return array<string, string>
     *
     * @phpstan-impure suit les introspections faites
     */
    public function getIntrospectionNotes(): array
    {
        return $this->introspector?->notes() ?? [];
    }

    /**
     * Diff qui mène du schéma réel de la base au schéma cible.
     *
     * @throws InvalidSchemaException
     */
    public function diffTo(Schema $target): SchemaDiff
    {
        return new Comparator($this->connection->getPlatform())->compare($this->introspect(), $target);
    }

    /**
     * Applique un diff. Sous PostgreSQL, tout passe dans une transaction (DDL transactionnel) ;
     * sous MySQL et MariaDB, chaque DDL est validé implicitement par le serveur : un échec laisse
     * la base à mi-chemin (RG-06.6).
     *
     * @return list<string> requêtes exécutées
     *
     * @throws InvalidSchemaException opération impossible sur la plateforme
     */
    public function migrate(SchemaDiff $diff): array
    {
        $platform = $this->connection->getPlatform();
        $sql = $platform->getMigrationSql($diff);

        $run = static function (Connection $connection) use ($sql): void {
            foreach ($sql as $statement) {
                $connection->executeStatement($statement);
            }
        };

        match (true) {
            $platform instanceof AbstractMySqlPlatform => $run($this->connection),
            $platform instanceof SqlitePlatform => $this->migrateSqlite($platform, $run),
            default => $this->connection->transactional($run),
        };

        return $sql;
    }

    /**
     * RG-01.11 : clés étrangères désactivées hors transaction (vérifié, pas supposé), migration
     * dans une transaction, contrôle des clés avant le commit, puis réactivation.
     *
     * @param \Closure(Connection): void $run
     */
    private function migrateSqlite(SqlitePlatform $platform, \Closure $run): void
    {
        if ($this->connection->isTransactionActive()) {
            throw InvalidSchemaException::migrationInsideTransaction($platform->getName());
        }

        $this->connection->executeStatement($platform->getForeignKeysSql(false));
        try {
            if (0 !== (int) self::scalar($this->connection->executeQuery($platform->getForeignKeysStateSql())->fetchOne())) {
                throw InvalidSchemaException::foreignKeysStillEnabled($platform->getName());
            }

            $this->connection->transactional(static function (Connection $connection) use ($platform, $run): void {
                $run($connection);

                $violation = $connection->executeQuery($platform->getForeignKeyCheckSql())->fetchAssociative();
                if (false !== $violation) {
                    throw InvalidSchemaException::foreignKeyViolation(self::scalar($violation['table'] ?? ''), self::scalar($violation['parent'] ?? ''));
                }
            });
        } finally {
            $this->connection->executeStatement($platform->getForeignKeysSql(true));
        }
    }

    private static function scalar(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private function introspector(): Introspector
    {
        $platform = $this->connection->getPlatform();

        return $this->introspector ??= match (true) {
            $platform instanceof AbstractMySqlPlatform => new MySqlIntrospector($this->connection, $platform, $platform instanceof MariaDbPlatform),
            $platform instanceof PostgreSqlPlatform => new PostgreSqlIntrospector($this->connection, $platform),
            $platform instanceof SqlitePlatform => new SqliteIntrospector($this->connection, $platform),
            default => throw InvalidSchemaException::unsupportedFeature($platform->getName(), 'schema introspection'),
        };
    }
}
