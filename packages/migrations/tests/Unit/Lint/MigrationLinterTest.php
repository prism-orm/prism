<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Unit\Lint;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\DriverManager;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Schema\VectorDistance;
use Prism\Migrations\AbstractMigration;
use Prism\Migrations\DataMigration;
use Prism\Migrations\Lint\Finding;
use Prism\Migrations\Lint\IgnoreLint;
use Prism\Migrations\Lint\LintResult;
use Prism\Migrations\Lint\MigrationLinter;
use Prism\Migrations\Lint\Severity;
use Prism\Migrations\MigrationBuilder;
use Prism\Migrations\MigrationFinder;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;

/**
 * RG-06.5 : chaque règle du linter, déclenchée et non déclenchée, sans base. Mapping :
 * dt_customer (id, full_name, first_name) ; invoice n'est pas mappée et vise dt_customer.
 */
final class MigrationLinterTest extends TestCase
{
    private const array URLS = [
        'postgresql' => 'postgresql://u:p@localhost/db?serverVersion=16',
        'mysql' => 'mysql://u:p@localhost/db?serverVersion=8.4',
        'mariadb' => 'mysql://u:p@localhost/db?serverVersion=11.8.0-MariaDB',
        'sqlite' => 'sqlite:///:memory:?serverVersion=3.46.0',
    ];

    private static function schema(): Schema
    {
        $customer = new Table('dt_customer', [new Column('id', 'integer'), new Column('full_name', 'string', length: 80), new Column('first_name', 'string', nullable: true, length: 40)], ['id']);
        $invoice = new Table('invoice', [new Column('id', 'integer'), new Column('customer_id', 'integer'), new Column('ref', 'string', length: 20)], ['id']);
        $passage = new Table('passage', [new Column('id', 'integer'), new Column('tenant_id', 'integer'), new Column('embedding', 'vector', length: 3)], ['id']);

        return new Schema([$customer, $invoice->withForeignKeyTo(['customer_id'], 'dt_customer', ['id']), $passage]);
    }

    /**
     * @param \Closure(MigrationBuilder): void $up
     */
    private static function migration(\Closure $up, bool $reversible = true): AbstractMigration
    {
        return $reversible
            ? new class($up) extends AbstractMigration {
                public function __construct(private readonly \Closure $up)
                {
                }

                public function up(MigrationBuilder $m): void
                {
                    ($this->up)($m);
                }

                public function down(MigrationBuilder $m): void
                {
                }
            }
        : new class($up) extends AbstractMigration {
            public function __construct(private readonly \Closure $up)
            {
            }

            public function up(MigrationBuilder $m): void
            {
                ($this->up)($m);
            }
        };
    }

    /**
     * @param array<string, AbstractMigration|DataMigration> $migrations
     */
    private static function lint(array $migrations, FakeInspector $data, string $platform = 'sqlite', bool $mapping = true): LintResult
    {
        $connection = DriverManager::fromUrl(self::URLS[$platform]);
        // les entités vectorielles, seulement là où le type existe
        $dirs = [\dirname(__DIR__, 2) . '/Fixtures/DataEntities', ...($connection->getPlatform()->supportsVectors() ? [\dirname(__DIR__, 2) . '/Fixtures/VectorEntities'] : [])];
        $metadata = $mapping ? new MetadataFactory(new AttributeDriver($dirs)) : null;

        return new MigrationLinter($connection, new MigrationFinder('/nonexistent', 'App'), $metadata, 1000, $data)->lintMigrations($migrations, self::schema());
    }

    /**
     * @return list<string>
     */
    private static function codes(LintResult $result): array
    {
        return array_map(static fn (Finding $f): string => $f->code, $result->findings);
    }

    /**
     * @return iterable<string, array{\Closure(MigrationBuilder): void, list<string>, FakeInspector, string}>
     */
    public static function cases(): iterable
    {
        $rows = new FakeInspector(['dt_customer', 'invoice']);
        $empty = new FakeInspector();

        yield 'L01 NOT NULL without default, rows' => [static fn (MigrationBuilder $m) => $m->addColumn('invoice', new Column('code', 'string', length: 5)), ['L01'], $rows, 'sqlite'];
        yield 'L01 no rows' => [static fn (MigrationBuilder $m) => $m->addColumn('invoice', new Column('code', 'string', length: 5)), [], $empty, 'sqlite'];
        yield 'L01 with a default' => [static fn (MigrationBuilder $m) => $m->addColumn('invoice', new Column('code', 'string', default: 'x', length: 5)), [], $rows, 'sqlite'];
        yield 'L02 NOT NULL over NULLs' => [static fn (MigrationBuilder $m) => $m->changeColumn('dt_customer', new Column('first_name', 'string', length: 40)), ['L02'], new FakeInspector(nulls: ['dt_customer.first_name']), 'sqlite'];
        yield 'L02 no NULL' => [static fn (MigrationBuilder $m) => $m->changeColumn('dt_customer', new Column('first_name', 'string', length: 40)), [], $empty, 'sqlite'];
        yield 'L03 mapped column dropped, L12 its data' => [static fn (MigrationBuilder $m) => $m->dropColumn('dt_customer', 'full_name'), ['L03', 'L12'], $rows, 'sqlite'];
        yield 'L12 unmapped column dropped' => [static fn (MigrationBuilder $m) => $m->dropColumn('invoice', 'ref'), ['L12'], $rows, 'sqlite'];
        yield 'L04 mapped table, L06 referenced, L11 rows' => [static fn (MigrationBuilder $m) => $m->dropTable('dt_customer'), ['L04', 'L06', 'L11'], $rows, 'sqlite'];
        yield 'L11 unmapped table with rows' => [static fn (MigrationBuilder $m) => $m->dropTable('invoice'), ['L11'], $rows, 'sqlite'];
        yield 'L05 mapped column renamed' => [static fn (MigrationBuilder $m) => $m->renameColumn('dt_customer', 'full_name', 'name'), ['L05'], $empty, 'sqlite'];
        yield 'L05 mapped table renamed' => [static fn (MigrationBuilder $m) => $m->renameTable('dt_customer', 'customer'), ['L05'], $empty, 'sqlite'];
        yield 'L05 unmapped rename' => [static fn (MigrationBuilder $m) => $m->renameColumn('invoice', 'ref', 'reference'), [], $empty, 'sqlite'];
        yield 'L07 unique over duplicates' => [static fn (MigrationBuilder $m) => $m->createIndex('invoice', ['ref'], unique: true), ['L07'], new FakeInspector(duplicates: ['invoice(ref)']), 'sqlite'];
        yield 'L07 no duplicate' => [static fn (MigrationBuilder $m) => $m->createIndex('invoice', ['ref'], unique: true), [], $empty, 'sqlite'];
        yield 'L08 orphans' => [static fn (MigrationBuilder $m) => $m->addForeignKey('dt_customer', ['id'], 'invoice', ['id']), ['L08'], new FakeInspector(orphans: ['dt_customer']), 'sqlite'];
        yield 'L09 index without CONCURRENTLY' => [static fn (MigrationBuilder $m) => $m->createIndex('invoice', ['ref']), ['L09'], $rows, 'postgresql'];
        yield 'L09 concurrently' => [static fn (MigrationBuilder $m) => $m->createIndex('invoice', ['ref'], concurrently: true), [], $rows, 'postgresql'];
        yield 'L09 not on MySQL' => [static fn (MigrationBuilder $m) => $m->createIndex('invoice', ['ref']), [], $rows, 'mysql'];
        yield 'L20 vector index over rows, L09 without CONCURRENTLY' => [static function (MigrationBuilder $m): void {
            $m->addColumn('invoice', new Column('embedding', 'vector', nullable: true, length: 3));
            $m->createVectorIndex('invoice', 'embedding', VectorDistance::Cosine);
        }, ['L09', 'L20'], $rows, 'postgresql'];
        yield 'L20 concurrently, still built over rows' => [static function (MigrationBuilder $m): void {
            $m->addColumn('invoice', new Column('embedding', 'vector', nullable: true, length: 3));
            $m->createVectorIndex('invoice', 'embedding', VectorDistance::Cosine, concurrently: true);
        }, ['L20'], $rows, 'postgresql'];
        yield 'L20 empty table' => [static function (MigrationBuilder $m): void {
            $m->addColumn('invoice', new Column('embedding', 'vector', nullable: true, length: 3));
            $m->createVectorIndex('invoice', 'embedding', VectorDistance::Cosine, concurrently: true);
        }, [], $empty, 'postgresql'];
        yield 'L20 not on SQLite, which builds no vector index' => [static function (MigrationBuilder $m): void {
            $m->addColumn('invoice', new Column('embedding', 'vector', nullable: true, length: 3));
            $m->createVectorIndex('invoice', 'embedding', VectorDistance::Cosine);
        }, [], $rows, 'sqlite'];
        // RG-09.11 : sous MariaDB, nearest() filtré par un gros tenant retombe sur une recherche exacte
        $vectorIndex = static fn (MigrationBuilder $m) => $m->createVectorIndex('passage', 'embedding', VectorDistance::Cosine);
        yield 'L21 large tenant among others, L10 large table' => [$vectorIndex, ['L20', 'L21', 'L10'], new FakeInspector(['passage'], groups: ['passage.tenant_id' => [50_000, 200_000]]), 'mariadb'];
        yield 'L21 small tenants' => [$vectorIndex, ['L20', 'L10'], new FakeInspector(['passage'], groups: ['passage.tenant_id' => [8_000, 200_000]]), 'mariadb'];
        yield 'L21 a single tenant, the index finds its rows' => [$vectorIndex, ['L20', 'L10'], new FakeInspector(['passage'], groups: ['passage.tenant_id' => [50_000, 50_000]]), 'mariadb'];
        yield 'L21 not on PostgreSQL, which scans the index iteratively' => [$vectorIndex, ['L09', 'L20'], new FakeInspector(['passage'], groups: ['passage.tenant_id' => [50_000, 200_000]]), 'postgresql'];
        yield 'L10 large table, once' => [static function (MigrationBuilder $m): void {
            $m->addColumn('invoice', new Column('a', 'integer', nullable: true));
            $m->addColumn('invoice', new Column('b', 'integer', nullable: true));
        }, ['L10', 'L17'], new FakeInspector(large: ['invoice']), 'mysql'];
        yield 'L10 not on PostgreSQL' => [static fn (MigrationBuilder $m) => $m->addColumn('invoice', new Column('a', 'integer', nullable: true)), [], new FakeInspector(large: ['invoice']), 'postgresql'];
        yield 'L13 narrower' => [static fn (MigrationBuilder $m) => $m->changeColumn('invoice', new Column('ref', 'string', length: 10)), ['L13'], $rows, 'sqlite'];
        yield 'L14 type change' => [static fn (MigrationBuilder $m) => $m->changeColumn('invoice', new Column('ref', 'integer')), ['L14'], $rows, 'sqlite'];
        yield 'L13 wider is fine' => [static fn (MigrationBuilder $m) => $m->changeColumn('invoice', new Column('ref', 'string', length: 40)), [], $rows, 'sqlite'];
        yield 'L15 primary key' => [static fn (MigrationBuilder $m) => $m->changePrimaryKey('invoice', ['id', 'ref']), ['L15'], $rows, 'sqlite'];
        yield 'L16 raw SQL' => [static fn (MigrationBuilder $m) => $m->sql('UPDATE invoice SET ref = :r', ['r' => 'x']), ['L16'], $empty, 'sqlite'];
        yield 'L17 two DDL on MySQL' => [static function (MigrationBuilder $m): void {
            $m->addColumn('invoice', new Column('a', 'integer', nullable: true));
            $m->createIndex('invoice', ['a']);
        }, ['L17'], $empty, 'mysql'];
        yield 'L17 one DDL' => [static fn (MigrationBuilder $m) => $m->addColumn('invoice', new Column('a', 'integer', nullable: true)), [], $empty, 'mysql'];
        yield 'L17 not on PostgreSQL' => [static function (MigrationBuilder $m): void {
            $m->addColumn('invoice', new Column('a', 'integer', nullable: true));
            $m->createIndex('invoice', ['a']);
        }, [], $empty, 'postgresql'];
        yield 'L18 no primary key' => [static fn (MigrationBuilder $m) => $m->createTable(new Table('log', [new Column('line', 'text')])), ['L18'], $empty, 'sqlite'];
        yield 'L00 impossible operation' => [static fn (MigrationBuilder $m) => $m->dropColumn('invoice', 'nope'), ['L00'], $empty, 'sqlite'];
        // une table créée par les migrations en attente est vide : aucune requête
        yield 'new table has no rows' => [static function (MigrationBuilder $m): void {
            $m->createTable(new Table('note', [new Column('id', 'integer')], ['id']));
            $m->addColumn('note', new Column('body', 'text'));
        }, [], new FakeInspector(['note']), 'sqlite'];
    }

    /**
     * @param \Closure(MigrationBuilder): void $up
     * @param list<string>                     $codes
     */
    #[DataProvider('cases')]
    public function testRule(\Closure $up, array $codes, FakeInspector $data, string $platform): void
    {
        $result = self::lint(['Version20261021100000' => self::migration($up)], $data, $platform);

        self::assertSame($codes, self::codes($result), implode("\n", array_map(static fn (Finding $f): string => $f->message, $result->findings)));
    }

    public function testSeverities(): void
    {
        $result = self::lint(['Version20261021100000' => self::migration(static function (MigrationBuilder $m): void {
            $m->dropColumn('dt_customer', 'full_name');
        })], new FakeInspector(['dt_customer']));

        self::assertSame([Severity::Error, Severity::Warning], array_map(static fn (Finding $f): Severity => $f->severity, $result->findings));
        self::assertSame('dt_customer.full_name is dropped but an entity still maps it: remove the field from the entity first.', $result->findings[0]->message);
        self::assertSame(['Version20261021100000'], $result->versions);
    }

    // RG-06.7
    public function testIrreversibleMigrations(): void
    {
        $data = new class extends DataMigration {
            public function run(EntityManagerInterface $em): void
            {
            }
        };
        $result = self::lint([
            'Version20261021100000' => self::migration(static fn (MigrationBuilder $m) => $m->addColumn('invoice', new Column('a', 'integer', nullable: true)), reversible: false),
            'Version20261021100000Data' => $data,
        ], new FakeInspector());

        self::assertSame(['L19', 'L19'], self::codes($result));
        self::assertSame('It has no revert(): it cannot be reverted.', $result->findings[1]->message);
    }

    public function testRenamesAreFollowedToTheRealTable(): void
    {
        $data = new FakeInspector(['invoice']);
        $result = self::lint(['Version20261021100000' => self::migration(static function (MigrationBuilder $m): void {
            $m->renameTable('invoice', 'bill');
            $m->renameColumn('bill', 'ref', 'reference');
            $m->dropColumn('bill', 'reference');
        })], $data);

        self::assertSame(['L12'], self::codes($result), 'the rows of invoice, under its new name');
        self::assertSame(['invoice'], array_values(array_unique($data->asked)));
    }

    public function testRuleCanBeIgnoredWithAReason(): void
    {
        $migration = new #[IgnoreLint('L12', reason: 'ref is empty in production')] class extends AbstractMigration {
            public function up(MigrationBuilder $m): void
            {
                $m->dropColumn('invoice', 'ref');
            }

            public function down(MigrationBuilder $m): void
            {
            }
        };

        $result = self::lint(['Version20261021100000' => $migration], new FakeInspector(['invoice']));

        self::assertSame('ref is empty in production', $result->findings[0]->ignoredBecause);
        self::assertSame([], $result->active(Severity::Warning));
    }

    public function testIgnoreNeedsAReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("#[IgnoreLint('L12')] needs a reason.");

        new IgnoreLint('L12', ' ');
    }

    public function testWithoutMappingTheMappedRulesAreSkipped(): void
    {
        $result = self::lint(['Version20261021100000' => self::migration(static fn (MigrationBuilder $m) => $m->dropColumn('dt_customer', 'full_name'))], new FakeInspector(), mapping: false);

        self::assertFalse($result->mappingChecked);
        self::assertSame([], self::codes($result));
    }
}
