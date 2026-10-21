<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\SchemaManager;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Tests\Support\TestDatabase;

/**
 * Renommages explicites (ADR 0012) sur les 4 bases : la base renommée par le SQL de la Platform
 * est exactement le schéma renommé en mémoire (diff vide, comme RG-01.8), données comprises ;
 * index et clés étrangères suivent.
 */
final class RenameTest extends TestCase
{
    private const array TABLES = ['rn_invoice', 'rn_bill', 'rn_client', 'rn_customer'];

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

    // la table enfant d'abord : ses clés étrangères partent avec elle
    private function drop(): void
    {
        foreach (self::TABLES as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->connection->getPlatform()->quoteIdentifier($table));
        }
    }

    private static function schema(): Schema
    {
        $client = new Table('rn_client', [new Column('id', 'integer'), new Column('mail', 'string', length: 120)], ['id']);
        $client = $client->withIndexOn(['mail'], unique: true);
        $invoice = new Table('rn_invoice', [new Column('id', 'integer'), new Column('client_id', 'integer'), new Column('ref', 'string', length: 20)], ['id']);
        $invoice = $invoice->withIndexOn(['client_id', 'ref'])->withForeignKeyTo(['client_id'], 'rn_client', ['id'], ForeignKeyAction::Cascade);

        return new Schema([$client, $invoice]);
    }

    private function assertDatabaseIs(Schema $expected): void
    {
        $actual = new SchemaManager($this->connection)->introspect();
        $mine = new Schema(array_values(array_filter($actual->getTables(), static fn (Table $t): bool => \in_array($t->name, self::TABLES, true))));
        $diff = new Comparator($this->connection->getPlatform())->compare($mine, $expected);

        self::assertTrue($diff->isEmpty(), 'the renamed database is the renamed schema: ' . implode('; ', $this->connection->getPlatform()->getMigrationSql($diff)));
    }

    public function testRenamedColumnsAndTablesKeepTheirData(): void
    {
        $platform = $this->connection->getPlatform();
        $schema = self::schema();
        foreach ($platform->getCreateSchemaSql($schema) as $sql) {
            $this->connection->executeStatement($sql);
        }
        $this->connection->executeStatement('INSERT INTO rn_client (id, mail) VALUES (1, :mail)', ['mail' => 'ada@example.com']);
        $this->connection->executeStatement('INSERT INTO rn_invoice (id, client_id, ref) VALUES (10, 1, :ref)', ['ref' => 'F-1']);

        // colonne citée par un index et une clé étrangère, puis colonne visée par une clé étrangère
        $this->connection->executeStatement($platform->getRenameColumnSql('rn_invoice', 'client_id', 'customer_id'));
        $this->connection->executeStatement($platform->getRenameColumnSql('rn_client', 'mail', 'email'));
        $schema = $schema->withRenamedColumn('rn_invoice', 'client_id', 'customer_id')->withRenamedColumn('rn_client', 'mail', 'email');
        $this->assertDatabaseIs($schema);

        // table visée par une clé étrangère, puis table qui la porte
        $this->connection->executeStatement($platform->getRenameTableSql('rn_client', 'rn_customer'));
        $this->connection->executeStatement($platform->getRenameTableSql('rn_invoice', 'rn_bill'));
        $schema = $schema->withRenamedTable('rn_client', 'rn_customer')->withRenamedTable('rn_invoice', 'rn_bill');
        $this->assertDatabaseIs($schema);

        self::assertSame(
            [['ref' => 'F-1', 'email' => 'ada@example.com']],
            $this->connection->executeQuery('SELECT b.ref, c.email FROM rn_bill b INNER JOIN rn_customer c ON c.id = b.customer_id')->fetchAllAssociative(),
            'the data follows, and the join works on the renamed foreign key',
        );
    }
}
