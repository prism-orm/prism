<?php

declare(strict_types=1);

namespace PrismVsDoctrine;

use Doctrine\DBAL\DriverManager as DoctrineDriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager as DoctrineEntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Prism\Dbal\Configuration;
use Prism\Dbal\Connection;
use Prism\Dbal\DriverManager;
use Prism\Dbal\QueryObserver;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\Schema;
use Prism\Orm\EntityManager;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tools\SchemaTool;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * La base commune aux deux ORM et leurs EntityManagers, réglés comme en production (ADR 0021) :
 * metadata en cache et réchauffées, objets paresseux natifs de PHP 8.4, aucun journal SQL. Seul
 * le comptage des requêtes branche un observateur, hors des mesures de durée.
 */
final class Setup
{
    public const string DATABASE = 'prism_bench_doctrine';

    public const int ITEMS = 10_000;

    public const int CUSTOMERS = 100;

    public const int ORDERS = 1_000;

    public const int LINES_PER_ORDER = 5;

    private function __construct()
    {
    }

    /**
     * L'URL du serveur (DATABASE_URL), la base remplacée par celle du benchmark.
     */
    public static function url(): string
    {
        $url = getenv('DATABASE_URL');
        if (!\is_string($url) || '' === $url) {
            throw new \LogicException('DATABASE_URL is not set: run make bench-doctrine.');
        }

        return (string) preg_replace('#/[^/?]*(\?.*)?$#', '/' . self::DATABASE . '$1', $url, 1);
    }

    public static function prism(?QueryObserver $observer = null): EntityManager
    {
        $connection = DriverManager::fromUrl(self::url(), new Configuration(observers: null === $observer ? [] : [$observer]));
        $metadata = new MetadataFactory(new AttributeDriver([__DIR__ . '/Prism']), new ArrayAdapter(), $connection->getTypeRegistry());
        $metadata->warmUp();

        return new EntityManager($connection, $metadata);
    }

    public static function doctrine(?\Closure $onQuery = null): DoctrineEntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/Doctrine'], isDevMode: false, cache: new ArrayAdapter());
        $config->enableNativeLazyObjects(true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER));
        if (null !== $onQuery) {
            $config->setMiddlewares([new Middleware(new class($onQuery) extends AbstractLogger {
                public function __construct(private readonly \Closure $onQuery)
                {
                }

                public function log($level, string|\Stringable $message, array $context = []): void
                {
                    if (str_starts_with((string) $message, 'Executing')) {
                        ($this->onQuery)();
                    }
                }
            })]);
        }
        $params = new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql'])->parse(self::url());
        $em = new DoctrineEntityManager(DoctrineDriverManager::getConnection($params, $config), $config);
        $em->getMetadataFactory()->getAllMetadata();

        return $em;
    }

    /**
     * Recrée la base, son schéma (celui des entités Prism, que celles de Doctrine lisent) et ses
     * données, d'une graine fixe.
     */
    public static function create(): void
    {
        $server = (string) getenv('DATABASE_URL');
        $admin = DriverManager::fromUrl($server);
        $quoted = $admin->getPlatform()->quoteIdentifier(self::DATABASE);
        $force = str_starts_with($server, 'postgresql') ? ' WITH (FORCE)' : '';
        $admin->executeStatement('DROP DATABASE IF EXISTS ' . $quoted . $force);
        $admin->executeStatement('CREATE DATABASE ' . $quoted);
        $admin->close();

        $connection = DriverManager::fromUrl(self::url());
        $driver = new AttributeDriver([__DIR__ . '/Prism']);
        $target = new SchemaTool($connection->getPlatform())->fromMetadata(array_map($driver->loadMetadata(...), $driver->getAllClassNames()));
        $connection->createSchemaManager()->migrate(new Comparator($connection->getPlatform())->compare(new Schema(), $target));

        mt_srand(42);
        $created = '2026-10-28 08:00:00';
        self::insert($connection, 'item', ['code', 'name', 'price', 'quantity', 'active', 'created_at'], self::ITEMS, static fn (int $i): array => [
            \sprintf('IT-%06d', $i), 'Item ' . $i, \sprintf('%d.%02d', mt_rand(1, 999), mt_rand(0, 99)), mt_rand(0, 500), $i % 3 > 0 ? 1 : 0, $created,
        ]);
        self::insert($connection, 'customer', ['name', 'city'], self::CUSTOMERS, static fn (int $i): array => ['Customer ' . $i, ['Paris', 'Lyon', 'Lille', 'Nantes'][$i % 4]]);
        self::insert($connection, 'purchase_order', ['reference', 'status', 'total', 'customer_id'], self::ORDERS, static fn (int $i): array => [
            \sprintf('ORD-%05d', $i), 'open', \sprintf('%d.%02d', mt_rand(10, 9999), mt_rand(0, 99)), mt_rand(1, self::CUSTOMERS),
        ]);
        self::insert($connection, 'order_line', ['order_id', 'product', 'quantity', 'price'], self::ORDERS * self::LINES_PER_ORDER, static fn (int $i): array => [
            intdiv($i - 1, self::LINES_PER_ORDER) + 1, 'Product ' . mt_rand(1, 300), mt_rand(1, 20), \sprintf('%d.%02d', mt_rand(1, 500), mt_rand(0, 99)),
        ]);
        $connection->close();
    }

    public static function drop(): void
    {
        $server = (string) getenv('DATABASE_URL');
        $admin = DriverManager::fromUrl($server);
        $admin->executeStatement('DROP DATABASE IF EXISTS ' . $admin->getPlatform()->quoteIdentifier(self::DATABASE) . (str_starts_with($server, 'postgresql') ? ' WITH (FORCE)' : ''));
        $admin->close();
    }

    /**
     * Lignes 1 à $rows, par INSERT de 500 lignes (outil de mesure, hors de l'ORM).
     *
     * @param list<string>                     $columns
     * @param \Closure(int): list<scalar>      $row
     */
    private static function insert(Connection $connection, string $table, array $columns, int $rows, \Closure $row): void
    {
        $platform = $connection->getPlatform();
        $head = \sprintf('INSERT INTO %s (%s) VALUES ', $platform->quoteIdentifier($table), implode(', ', array_map($platform->quoteIdentifier(...), $columns)));
        $connection->transactional(static function (Connection $connection) use ($head, $rows, $row): void {
            for ($start = 1; $start <= $rows; $start += 500) {
                $values = [];
                $params = [];
                for ($i = $start; $i < min($start + 500, $rows + 1); ++$i) {
                    $names = [];
                    foreach ($row($i) as $c => $value) {
                        $names[] = ':p' . $i . '_' . $c;
                        $params['p' . $i . '_' . $c] = $value;
                    }
                    $values[] = '(' . implode(', ', $names) . ')';
                }
                $connection->executeStatement($head . implode(', ', $values), $params);
            }
        });
    }
}
