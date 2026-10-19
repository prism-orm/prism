<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Dbal\Exception\QueryFailedException;
use Prism\Dbal\Tests\Support\ReferenceSchemas;

/**
 * Le DDL généré pour chaque schéma de référence est accepté par les 4 bases. L'introspection
 * (5b) vérifiera ensuite que le schéma relu est identique.
 */
final class CreateSchemaTest extends SchemaTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function schemaNames(): iterable
    {
        foreach (array_keys(ReferenceSchemas::all(true)) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('schemaNames')]
    public function testGeneratedDdlIsAccepted(string $name): void
    {
        $platform = $this->connection->getPlatform();
        $schemas = ReferenceSchemas::all($platform->supportsSequences());
        if (!isset($schemas[$name])) {
            self::markTestSkipped(\sprintf('%s does not support sequences.', $platform->getName()));
        }

        $this->create($schemas[$name]);

        foreach ($schemas[$name]->getTables() as $table) {
            self::assertEquals(0, $this->rowsIn($table->name), $table->name);
        }
    }

    // les clés étrangères générées sont appliquées par la base (RG-04.6)
    public function testForeignKeyActionsAreEnforced(): void
    {
        $this->create(ReferenceSchemas::all(false)['composite primary key with cascades']);
        $insert = fn (string $table, array $values) => $this->connection->executeStatement(
            \sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->q($table), implode(', ', array_map($this->q(...), array_keys($values))), implode(', ', array_fill(0, \count($values), '?'))),
            array_values($values),
        );

        $insert('ref_client', ['id' => 1, 'name' => 'ACME']);
        $insert('ref_invoice', ['id' => 10, 'client_id' => 1, 'number' => 'F-1']);
        $insert('ref_invoice_tag', ['invoice_id' => 10, 'tag' => 'urgent']);

        try {
            $insert('ref_invoice', ['id' => 11, 'client_id' => 99, 'number' => 'F-2']);
            self::fail('Expected a foreign key violation.');
        } catch (QueryFailedException) {
        }

        $this->connection->executeStatement(\sprintf('DELETE FROM %s WHERE %s = 1', $this->q('ref_client'), $this->q('id')));
        self::assertEquals(0, $this->rowsIn('ref_invoice'));
        self::assertEquals(0, $this->rowsIn('ref_invoice_tag'));
    }

    public function testDefaultsAreAppliedByTheDatabase(): void
    {
        $this->create(ReferenceSchemas::all(false)['nullable and defaults']);

        $this->connection->executeStatement(\sprintf('INSERT INTO %s (%s) VALUES (1)', $this->q('ref_defaults'), $this->q('id')));
        $row = $this->connection->executeQuery(\sprintf('SELECT * FROM %s', $this->q('ref_defaults')))->fetchAssociative();
        self::assertIsArray($row);

        self::assertSame('draft', $row['status']);
        self::assertSame("O'Reilly \\ co", $row['quote']);
        self::assertEquals([0, -5], [$row['qty'], $row['negative']]);
        self::assertTrue($this->connection->convertToPhp($row['active'], 'boolean'));
        self::assertFalse($this->connection->convertToPhp($row['archived'], 'boolean'));
        self::assertEquals(9.9, $row['amount']);
        self::assertEquals(1.5, $row['ratio']);
        self::assertSame('2026-01-01', $row['since']);
        self::assertNull($row['note']);
    }
}
