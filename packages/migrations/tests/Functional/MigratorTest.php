<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\Schema\SchemaManager;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Migrations\Direction;
use Prism\Migrations\Exception\IrreversibleMigrationException;
use Prism\Migrations\Exception\MigrationException;
use Prism\Migrations\MigrationFinder;
use Prism\Migrations\MigrationStatus;
use Prism\Migrations\Migrator;
use Prism\Migrations\VersionStorage;

/**
 * Le Migrator sur les 4 bases (ADR 0012) : la même migration partout, ordre chronologique
 * (RG-06.1), données gardées par les renommages, checksum (RG-06.4), transactions selon la
 * plateforme (RG-06.6), migrations irréversibles (RG-06.7).
 *
 * Différence documentée : seul PostgreSQL annule entière une migration échouée ; MySQL,
 * MariaDB et SQLite gardent ce qui a précédé l'échec.
 */
final class MigratorTest extends TestCase
{
    private const array TABLES = ['mg_bill', 'mg_invoice', 'mg_client', 'mg_partial', VersionStorage::TABLE];

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
        foreach (self::TABLES as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->connection->getPlatform()->quoteIdentifier($table));
        }
    }

    private function migrator(string $fixtures = 'Basic'): Migrator
    {
        return new Migrator($this->connection, new MigrationFinder(\dirname(__DIR__) . '/Fixtures/' . $fixtures, 'Prism\\Migrations\\Tests\\Fixtures\\' . $fixtures));
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $tables = array_values(array_intersect(array_map(strtolower(...), new SchemaManager($this->connection)->listTableNames()), self::TABLES));
        sort($tables);

        return $tables;
    }

    /**
     * @param list<MigrationStatus> $status
     *
     * @return array<string, string>
     */
    private static function states(array $status): array
    {
        $states = [];
        foreach ($status as $migration) {
            $states[$migration->version] = $migration->state->value;
        }

        return $states;
    }

    public function testMigrateUpKeepsTheDataThroughRenames(): void
    {
        $migrator = $this->migrator();
        self::assertSame(['Version20261021100000' => 'pending', 'Version20261021110000' => 'pending', 'Version20261021120000' => 'pending'], self::states($migrator->status()));

        $first = $migrator->migrate('Version20261021100000');
        self::assertSame(['Version20261021100000'], array_map(static fn ($m): string => $m->version, $first->migrations));
        $this->connection->executeStatement('INSERT INTO mg_client (id, mail) VALUES (1, :mail)', ['mail' => 'ada@example.com']);
        $this->connection->executeStatement('INSERT INTO mg_invoice (id, client_id, ref) VALUES (10, 1, :ref)', ['ref' => 'f-1']);

        $rest = $migrator->migrate();
        self::assertSame(['Version20261021110000', 'Version20261021120000'], array_map(static fn ($m): string => $m->version, $rest->migrations));
        self::assertSame(['mg_bill', 'mg_client', VersionStorage::TABLE], $this->tables());
        self::assertSame(
            [['ref' => 'F-1', 'email' => 'ada@example.com', 'paid_at' => null]],
            $this->connection->executeQuery('SELECT b.ref, c.email, b.paid_at FROM mg_bill b INNER JOIN mg_client c ON c.id = b.client_id')->fetchAllAssociative(),
            'renamed column and table keep their rows; the raw SQL ran',
        );

        $status = $migrator->status();
        self::assertSame(['executed', 'executed', 'executed'], array_values(self::states($status)));
        self::assertSame('Payment date, client email', $status[1]->description);
        self::assertNotNull($status[1]->executedAt);
        self::assertGreaterThanOrEqual(0, $status[1]->executionTimeMs);
        self::assertSame([], $migrator->migrate()->migrations, 'nothing left to run');
    }

    // RG-06.6 : sans DDL transactionnel, chaque migration tourne hors transaction, et le dit
    public function testTransactionsFollowThePlatform(): void
    {
        $result = $this->migrator()->migrate('Version20261021100000');
        $transactional = $this->connection->getPlatform()->supportsTransactionalDdl();

        self::assertSame($transactional, $result->migrations[0]->transactional);
        self::assertCount($transactional ? 0 : 1, $result->warnings);
        if (!$transactional) {
            self::assertStringContainsString('ran without a transaction', $result->warnings[0]);
        }
    }

    public function testMigrateDownToAVersionThenRevertAll(): void
    {
        $migrator = $this->migrator();
        $migrator->migrate('Version20261021110000');

        $down = $migrator->migrate('Version20261021100000');
        self::assertSame([['Version20261021110000', Direction::Down]], array_map(static fn ($m): array => [$m->version, $m->direction], $down->migrations));
        self::assertTrue(new SchemaManager($this->connection)->introspectTable('mg_client')->hasColumn('mail'), 'renamed back');
        self::assertFalse(new SchemaManager($this->connection)->introspectTable('mg_invoice')->hasColumn('paid_at'));

        $migrator->migrate('0');
        self::assertSame([VersionStorage::TABLE], $this->tables());
        self::assertSame(['pending', 'pending', 'pending'], array_values(self::states($migrator->status())));
    }

    // RG-06.7
    public function testIrreversibleMigrationBlocksTheRevert(): void
    {
        $migrator = $this->migrator();
        $migrator->migrate();

        try {
            $migrator->migrate('Version20261021110000');
            self::fail('Expected IrreversibleMigrationException.');
        } catch (IrreversibleMigrationException $e) {
            self::assertSame('The migration Version20261021120000 cannot be reverted: it has no down().', $e->getMessage());
        }
        self::assertSame('executed', self::states($migrator->status())['Version20261021120000']);
    }

    public function testDryRunWritesNothing(): void
    {
        $result = $this->migrator()->migrate(dryRun: true);

        self::assertTrue($result->dryRun);
        self::assertCount(3, $result->migrations);
        self::assertStringContainsString('mg_client', implode("\n", $result->migrations[0]->sql));
        self::assertStringContainsString('RENAME', $result->migrations[2]->sql[0], 'planned on the schema the earlier migrations would leave');
        self::assertSame([], $this->tables(), 'not even the versions table');
    }

    // RG-06.4
    public function testModifiedMigrationBlocksMigrate(): void
    {
        $migrator = $this->migrator();
        $migrator->migrate('Version20261021100000');
        $qb = $this->connection->createQueryBuilder();
        $qb->update(VersionStorage::TABLE)->set('checksum', str_repeat('0', 64))->where($qb->expr()->eq($qb->column('version'), 'Version20261021100000'))->executeStatement();

        self::assertSame('modified', self::states($migrator->status())['Version20261021100000']);
        try {
            $migrator->migrate();
            self::fail('Expected MigrationException.');
        } catch (MigrationException $e) {
            self::assertStringStartsWith('The migration Version20261021100000 was modified after it was executed', $e->getMessage());
        }
        self::assertCount(2, $migrator->migrate(allowModified: true)->migrations, '--allow-modified');
    }

    public function testExecuteOneMigration(): void
    {
        $migrator = $this->migrator();

        self::assertSame(Direction::Up, $migrator->execute('Version20261021100000', Direction::Up)->migrations[0]->direction);
        try {
            $migrator->execute('Version20261021100000', Direction::Up);
            self::fail('Expected MigrationException.');
        } catch (MigrationException $e) {
            self::assertSame('The migration Version20261021100000 is already executed: run it --down first.', $e->getMessage());
        }
        $migrator->execute('Version20261021100000', Direction::Down);
        self::assertSame([VersionStorage::TABLE], $this->tables());

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('The migration Version20261021100000 is not executed: there is nothing to revert.');
        $migrator->execute('Version20261021100000', Direction::Down);
    }

    public function testUnknownVersionIsRefused(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Unknown migration version "Version20990101000000": no such migration file.');

        $this->migrator()->migrate('Version20990101000000');
    }

    // RG-06.6 : seul PostgreSQL annule entière une migration échouée
    public function testFailedMigration(): void
    {
        $migrator = $this->migrator('Failing');
        $transactional = $this->connection->getPlatform()->supportsTransactionalDdl();

        try {
            $migrator->migrate();
            self::fail('Expected MigrationException.');
        } catch (MigrationException $e) {
            self::assertStringStartsWith('The migration Version20261021100000 failed (up): ', $e->getMessage());
            self::assertStringEndsWith($transactional ? 'Its transaction was rolled back: nothing was applied.' : 'check the schema before running it again.', $e->getMessage());
        }

        self::assertSame($transactional ? [VersionStorage::TABLE] : ['mg_partial', VersionStorage::TABLE], $this->tables(), 'the table created before the failure');
        self::assertSame('pending', self::states($migrator->status())['Version20261021100000'], 'never recorded');
    }

    public function testInvalidOperationIsReportedWithItsVersion(): void
    {
        $migrator = $this->migrator();
        $migrator->migrate('Version20261021100000');
        $this->connection->executeStatement('DROP TABLE mg_invoice');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('The migration Version20261021110000 cannot apply to the current schema: ');

        $migrator->migrate();
    }
}
