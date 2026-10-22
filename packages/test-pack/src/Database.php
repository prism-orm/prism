<?php

declare(strict_types=1);

namespace Prism\TestPack;

use Prism\Dbal\Connection;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;
use Prism\Orm\EntityManager;
use Prism\Orm\Tools\SchemaTool;

/**
 * Le travail de ResetDatabase (RG-07.6) : le schéma des entités créé une fois par base et par
 * processus (une fois par connexion pour SQLite en mémoire), une transaction par test, annulée à
 * la fin. Seules les tables et séquences mappées sont touchées : le reste de la base de test est
 * laissé tel quel.
 *
 * @internal
 */
final class Database
{
    /** @var array<string, true> bases dont le schéma est prêt, par DSN */
    private static array $prepared = [];

    /** @var \WeakMap<Connection, true>|null les bases SQLite en mémoire, par connexion */
    private static ?\WeakMap $memory = null;

    /** @var list<EntityManager> les EntityManagers du test en cours */
    private static array $entityManagers = [];

    /**
     * Début de test : schéma prêt, puis une transaction par connexion, sauf commits réels.
     */
    public static function begin(bool $realTransactions): void
    {
        self::$entityManagers = array_map(TestContext::entityManager(...), TestContext::entityManagerNames());
        foreach (self::$entityManagers as $em) {
            self::prepare($em, force: false);
        }
        if (!$realTransactions) {
            foreach (self::connections() as $connection) {
                $connection->beginTransaction();
            }
        }
    }

    /**
     * Fin de test : la transaction annulée, ou le schéma recréé après des commits réels.
     */
    public static function end(bool $realTransactions): void
    {
        foreach (self::$entityManagers as $em) {
            $em->clear();
        }
        foreach (self::connections() as $connection) {
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        if ($realTransactions) {
            foreach (self::$entityManagers as $em) {
                self::prepare($em, force: true);
            }
        }
        self::$entityManagers = [];
    }

    /**
     * @return list<Connection> une fois chacune, même partagée par plusieurs EntityManagers
     */
    private static function connections(): array
    {
        $connections = [];
        foreach (self::$entityManagers as $em) {
            $connections[spl_object_id($em->getConnection())] = $em->getConnection();
        }

        return array_values($connections);
    }

    // les tables mappées supprimées puis recréées : une base propre, quelle que soit son histoire
    private static function prepare(EntityManager $em, bool $force): void
    {
        $connection = $em->getConnection();
        $memory = ':memory:' === $connection->getDatabase();
        self::$memory ??= new \WeakMap();
        $target = new SchemaTool($connection->getPlatform(), $connection->getTypeRegistry())->fromMetadata($em->getMetadataFactory()->getAllMetadata());
        $names = array_map(static fn (Table $t): string => strtolower($t->name), $target->getTables());
        // une base et ses tables : un nouveau kernel à chaque test retrouve le schéma déjà prêt
        $key = $connection->getPlatform()->getName() . ':' . $connection->getDatabase() . ':' . implode(',', $names);
        if (!$force && ($memory ? isset(self::$memory[$connection]) : isset(self::$prepared[$key]))) {
            return;
        }

        $schemaManager = $connection->createSchemaManager();
        // les séquences aussi : sous PostgreSQL, les identifiants en viennent
        $sequences = array_map(static fn (Sequence $s): string => strtolower($s->name), $target->getSequences());
        $actual = $schemaManager->introspect();
        $existing = new Schema(
            array_values(array_filter($actual->getTables(), static fn (Table $t): bool => \in_array(strtolower($t->name), $names, true))),
            array_values(array_filter($actual->getSequences(), static fn (Sequence $s): bool => \in_array(strtolower($s->name), $sequences, true))),
        );
        $comparator = new Comparator($connection->getPlatform());
        $schemaManager->migrate($comparator->compare($existing, new Schema()));
        $schemaManager->migrate($comparator->compare(new Schema(), $target));

        if ($memory) {
            self::$memory[$connection] = true;
        } else {
            self::$prepared[$key] = true;
        }
    }
}
