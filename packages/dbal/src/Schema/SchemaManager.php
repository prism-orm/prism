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
 * @api
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
