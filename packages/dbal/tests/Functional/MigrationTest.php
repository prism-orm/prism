<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\SchemaDiff;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Tests\Support\ReferenceSchemas;
use Prism\Dbal\Tests\Support\TestDatabase;

/**
 * Test d'or du DBAL (RG-01.8) : diff(introspect(apply(diff(A, B))), B) est vide.
 */
final class MigrationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }

        $this->connection = TestDatabase::connection();
        $this->reset();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->reset();
            $this->connection->close();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function creations(): iterable
    {
        foreach (array_keys(ReferenceSchemas::all(true)) as $name) {
            yield $name => [$name];
        }
    }

    // RG-01.8, depuis une base vide
    #[DataProvider('creations')]
    public function testCreationRoundTrip(string $name): void
    {
        $schemas = ReferenceSchemas::all($this->connection->getPlatform()->supportsSequences());
        if (!isset($schemas[$name])) {
            self::markTestSkipped('No sequences on this platform.');
        }

        $this->migrateTo($schemas[$name]);

        $this->assertNoDiff($schemas[$name]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function evolutions(): iterable
    {
        foreach (array_keys(ReferenceSchemas::evolutions(true)) as $name) {
            yield $name => [$name];
        }
    }

    // RG-01.8, de A vers B
    #[DataProvider('evolutions')]
    public function testEvolutionRoundTrip(string $name): void
    {
        $evolutions = ReferenceSchemas::evolutions($this->connection->getPlatform()->supportsSequences());
        if (!isset($evolutions[$name])) {
            self::markTestSkipped('No sequences on this platform.');
        }
        [$from, $to] = $evolutions[$name];

        $this->migrateTo($from);
        $this->assertNoDiff($from);

        $this->migrateTo($to);

        $this->assertNoDiff($to);
    }

    // une évolution conserve les données des colonnes gardées
    public function testDataSurvivesAColumnChange(): void
    {
        [$from, $to] = ReferenceSchemas::evolutions(false)['widen string'];
        $this->migrateTo($from);
        $q = $this->connection->getPlatform()->quoteIdentifier(...);
        $this->connection->executeStatement(\sprintf('INSERT INTO %s (%s, %s) VALUES (?, ?)', $q('ref_invoice'), $q('id'), $q('number')), [1, 'F-2026-001']);

        $this->migrateTo($to);

        self::assertSame('F-2026-001', $this->connection->executeQuery(\sprintf('SELECT %s FROM %s', $q('number'), $q('ref_invoice')))->fetchOne());
    }

    private function migrateTo(Schema $target): void
    {
        $manager = $this->connection->createSchemaManager();
        $manager->migrate(new Comparator($this->connection->getPlatform())->compare($this->referenceTables(), $target));
    }

    private function assertNoDiff(Schema $expected): void
    {
        $diff = new Comparator($this->connection->getPlatform())->compare($this->referenceTables(), $expected);

        self::assertTrue($diff->isEmpty(), 'Remaining diff: ' . implode(' | ', $this->connection->getPlatform()->getMigrationSql($diff)) . self::describe($diff));
    }

    // seules les tables et séquences de référence : la base de test peut en contenir d'autres
    private function referenceTables(): Schema
    {
        $actual = $this->connection->createSchemaManager()->introspect();

        return new Schema(
            array_values(array_filter($actual->getTables(), static fn (Table $t): bool => str_starts_with($t->name, 'ref_'))),
            array_values(array_filter($actual->getSequences(), static fn ($s): bool => str_starts_with($s->name, 'ref_'))),
        );
    }

    private function reset(): void
    {
        $this->migrateTo(new Schema());
    }

    private static function describe(SchemaDiff $diff): string
    {
        $parts = [];
        foreach ($diff->alteredTables as $table) {
            foreach ($table->changedColumns as $column) {
                $parts[] = \sprintf('%s.%s: %s (%s → %s)', $table->name, $column->to->name, implode(',', $column->changedProperties), var_export($column->from->default, true), var_export($column->to->default, true));
            }
        }

        return [] === $parts ? '' : ' | ' . implode(' | ', $parts);
    }
}
