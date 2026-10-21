<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Migrations\Direction;
use Prism\Migrations\Exception\IrreversibleMigrationException;
use Prism\Migrations\Exception\MigrationException;
use Prism\Migrations\ExecutedMigration;
use Prism\Migrations\MigrationFinder;
use Prism\Migrations\MigrationStatus;
use Prism\Migrations\Migrator;
use Prism\Migrations\VersionStorage;
use Prism\Orm\EntityManager;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;

/**
 * RG-06.8 sur les 4 bases : une migration de données suit la migration de schéma de sa version,
 * s'exécute dans une transaction avec l'EntityManager, et se revert avant elle.
 */
final class DataMigrationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }
        $this->connection = TestDatabase::connection();
        $this->drop();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->drop();
            $this->connection->close();
        }
    }

    private function drop(): void
    {
        foreach (['dt_customer', VersionStorage::TABLE] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->connection->getPlatform()->quoteIdentifier($table));
        }
    }

    private function entityManager(?Connection $connection = null): EntityManager
    {
        return new EntityManager($connection ?? $this->connection, new MetadataFactory(new AttributeDriver([\dirname(__DIR__) . '/Fixtures/DataEntities'])));
    }

    private function migrator(string $fixtures = 'Data', bool $withEntityManager = true): Migrator
    {
        return new Migrator($this->connection, new MigrationFinder(\dirname(__DIR__) . '/Fixtures/' . $fixtures, 'Prism\\Migrations\\Tests\\Fixtures\\' . $fixtures), $withEntityManager ? $this->entityManager() : null);
    }

    private function seed(): void
    {
        $this->connection->executeStatement('INSERT INTO dt_customer (id, full_name) VALUES (1, :a), (2, :b)', ['a' => 'Ada Lovelace', 'b' => 'Alan Turing']);
    }

    /**
     * @return list<mixed>
     */
    private function firstNames(): array
    {
        return $this->connection->executeQuery('SELECT first_name FROM dt_customer ORDER BY id')->fetchFirstColumn();
    }

    /**
     * @param list<ExecutedMigration> $migrations
     *
     * @return list<string>
     */
    private static function versions(array $migrations): array
    {
        return array_map(static fn (ExecutedMigration $m): string => $m->version . ':' . $m->direction->value, $migrations);
    }

    public function testDataFollowsTheSchemaOfItsVersion(): void
    {
        $migrator = $this->migrator();
        $migrator->migrate('Version20261021100000');
        $this->seed();

        // la version 110000 comprend sa migration de données, pas la 120000
        $result = $migrator->migrate('Version20261021110000');

        self::assertSame(['Version20261021110000:up', 'Version20261021110000Data:up'], self::versions($result->migrations));
        self::assertTrue($result->migrations[1]->data);
        self::assertSame([], $result->migrations[1]->sql);
        self::assertSame(['Ada', 'Alan'], $this->firstNames());
        self::assertSame(
            ['Version20261021100000' => 'executed', 'Version20261021110000' => 'executed', 'Version20261021110000Data' => 'executed', 'Version20261021120000Data' => 'pending'],
            array_combine(array_map(static fn (MigrationStatus $s): string => $s->version, $migrator->status()), array_map(static fn (MigrationStatus $s): string => $s->state->value, $migrator->status())),
        );
        self::assertSame('First names from full names', $migrator->status()[2]->description);

        // retour : les données d'abord, puis le schéma de la même version
        $down = $migrator->migrate('Version20261021100000');
        self::assertSame(['Version20261021110000Data:down', 'Version20261021110000:down'], self::versions($down->migrations));
        self::assertSame([null, null], $this->firstNames());
    }

    // RG-06.7
    public function testDataMigrationWithoutRevertIsIrreversible(): void
    {
        $migrator = $this->migrator();
        $migrator->migrate('Version20261021100000');
        $this->seed();
        $migrator->migrate();
        self::assertSame('ADA LOVELACE', $this->connection->executeQuery('SELECT full_name FROM dt_customer WHERE id = 1')->fetchOne());

        $this->expectException(IrreversibleMigrationException::class);
        $this->expectExceptionMessage('The migration Version20261021120000Data cannot be reverted: it has no revert().');

        $migrator->migrate('Version20261021110000', dryRun: true);
    }

    public function testFailedDataMigrationIsRolledBackOnEveryPlatform(): void
    {
        $migrator = $this->migrator('DataFailing');
        // le schéma seul : migrate('Version20261021100000') comprendrait sa migration de données
        $migrator->execute('Version20261021100000', Direction::Up);
        $this->seed();
        $this->connection->executeStatement("UPDATE dt_customer SET first_name = 'kept'");

        try {
            $migrator->migrate();
            self::fail('Expected MigrationException.');
        } catch (MigrationException $e) {
            self::assertStringStartsWith('The migration Version20261021100000Data failed (up): Boom in the data migration.', $e->getMessage());
            self::assertStringEndsWith('Its transaction was rolled back: nothing was applied.', $e->getMessage());
        }

        self::assertSame(['kept', 'kept'], $this->firstNames(), 'the flushed changes are rolled back, even on MySQL');
        self::assertSame('pending', $migrator->status()[1]->state->value);
    }

    public function testDataMigrationNeedsAnEntityManager(): void
    {
        $migrator = $this->migrator(withEntityManager: false);

        try {
            $migrator->migrate();
            self::fail('Expected MigrationException.');
        } catch (MigrationException $e) {
            self::assertSame('The data migration Version20261021110000Data needs an EntityManager: give one to the Migrator (or to the command).', $e->getMessage());
        }
        self::assertSame('executed', $migrator->status()[1]->state->value, 'the schema migrations before it ran');
        self::assertSame('pending', $migrator->status()[2]->state->value);
    }

    public function testEntityManagerMustUseTheSameConnection(): void
    {
        $other = TestDatabase::connection();

        try {
            $this->expectException(MigrationException::class);
            $this->expectExceptionMessage('The EntityManager given to the Migrator uses another connection');

            new Migrator($this->connection, new MigrationFinder(\dirname(__DIR__) . '/Fixtures/Data', 'Prism\\Migrations\\Tests\\Fixtures\\Data'), $this->entityManager($other));
        } finally {
            $other->close();
        }
    }

    // RG-06.4 : le checksum d'une migration de données est celui de son fichier
    public function testModifiedDataMigrationIsDetected(): void
    {
        $migrator = $this->migrator();
        $migrator->migrate('Version20261021110000');
        $qb = $this->connection->createQueryBuilder();
        $qb->update(VersionStorage::TABLE)->set('checksum', 'edited')->where($qb->expr()->eq($qb->column('version'), 'Version20261021110000Data'))->executeStatement();

        self::assertSame('modified', $migrator->status()[2]->state->value);
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('The migration Version20261021110000Data was modified after it was executed');
        $migrator->migrate();
    }

    public function testDryRunListsDataMigrationsWithoutRunningThem(): void
    {
        $migrator = $this->migrator();

        $result = $migrator->migrate(dryRun: true);

        self::assertSame(['Version20261021100000:up', 'Version20261021110000:up', 'Version20261021110000Data:up', 'Version20261021120000Data:up'], self::versions($result->migrations));
        self::assertSame([false, false, true, true], array_map(static fn (ExecutedMigration $m): bool => $m->data, $result->migrations));
        self::assertSame(Direction::Up, $result->migrations[3]->direction);
    }
}
