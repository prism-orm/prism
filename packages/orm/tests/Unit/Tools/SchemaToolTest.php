<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ForeignKey;
use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\IndexKind;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Schema\VectorDistance;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Mapping\GenerationStrategy;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\FieldMapping;
use Prism\Orm\Metadata\IdMapping;
use Prism\Orm\Metadata\IndexMapping;
use Prism\Orm\Tests\Functional\Fixtures\Author;
use Prism\Orm\Tests\Functional\Fixtures\Book;
use Prism\Orm\Tests\Functional\Fixtures\Label;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Client;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Invoice;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\InvoiceLine;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Tag;
use Prism\Orm\Tests\Unit\Mapping\InvalidFixtures\UnknownTypeEntity;
use Prism\Orm\Tests\Unit\Metadata\Fixtures\Inferred;
use Prism\Orm\Tests\Unit\Metadata\Fixtures\Person;
use Prism\Orm\Tests\Unit\Tools\Fixtures\Currency;
use Prism\Orm\Tests\Unit\Tools\Fixtures\Ledger;
use Prism\Orm\Tests\Unit\Tools\Fixtures\Note;
use Prism\Orm\Tests\Unit\Tools\Fixtures\Wallet;
use Prism\Orm\Tools\SchemaTool;

final class SchemaToolTest extends TestCase
{
    /**
     * @param list<class-string> $classes
     */
    private static function schema(AbstractPlatform $platform, array $classes): Schema
    {
        $driver = new AttributeDriver();

        return new SchemaTool($platform)->fromMetadata(array_map($driver->loadMetadata(...), $classes));
    }

    /**
     * @return list<array{string, string, bool, mixed, ?int, bool}>
     */
    private static function columns(Table $table): array
    {
        return array_map(static fn (Column $c): array => [$c->name, $c->type, $c->nullable, $c->default, $c->length, $c->autoincrement], $table->getColumns());
    }

    /**
     * @return array<string, array{list<string>, bool}>
     */
    private static function indexes(Table $table): array
    {
        $indexes = [];
        foreach ($table->getIndexes() as $index) {
            $indexes[$index->name] = [$index->columns, $index->unique];
        }
        ksort($indexes);

        return $indexes;
    }

    /**
     * @return list<array{list<string>, string, list<string>, ForeignKeyAction}>
     */
    private static function foreignKeys(Table $table): array
    {
        return array_map(static fn (ForeignKey $f): array => [$f->localColumns, $f->foreignTable, $f->foreignColumns, $f->onDelete], $table->getForeignKeys());
    }

    public function testRelationsProduceJoinColumnsForeignKeysIndexesAndPivots(): void
    {
        $schema = self::schema(new MariaDbPlatform('11.8'), [Author::class, Book::class, Label::class]);

        self::assertSame(['author', 'book', 'label', 'book_label'], array_map(static fn (Table $t): string => $t->name, $schema->getTables()));

        $book = $schema->getTable('book');
        self::assertSame([
            ['id', 'integer', false, null, null, true],
            ['title', 'string', false, null, 200, false],
            ['price', 'decimal', false, '0.00', null, false],
            // même type que l'identifiant visé, nullable comme la propriété
            ['author_id', 'integer', true, null, null, false],
            ['sequel_id', 'integer', true, null, null, false],
        ], self::columns($book));
        self::assertSame(['id'], $book->primaryKey);
        self::assertSame([
            [['author_id'], 'author', ['id'], ForeignKeyAction::SetNull],
            [['sequel_id'], 'book', ['id'], ForeignKeyAction::SetNull],
        ], self::foreignKeys($book));

        $indexes = array_values(self::indexes($book));
        // l'index déclaré (author_id, title) couvre la jointure : pas de second index sur author_id
        self::assertContains([['author_id', 'title'], false], $indexes);
        self::assertNotContains([['author_id'], false], $indexes);
        // OneToOne : unique
        self::assertContains([['sequel_id'], true], $indexes);
        self::assertCount(2, $indexes);

        $pivot = $schema->getTable('book_label');
        self::assertSame([['book_id', 'integer', false, null, null, false], ['label_id', 'integer', false, null, null, false]], self::columns($pivot));
        self::assertSame(['book_id', 'label_id'], $pivot->primaryKey);
        self::assertSame([
            [['book_id'], 'book', ['id'], ForeignKeyAction::Cascade],
            [['label_id'], 'label', ['id'], ForeignKeyAction::Cascade],
        ], self::foreignKeys($pivot));
        self::assertSame([[['label_id'], false]], array_values(self::indexes($pivot)));

        self::assertSame([[['name'], true]], array_values(self::indexes($schema->getTable('label'))), '#[Column(unique: true)]');
        self::assertSame([], $schema->getSequences(), 'Auto is IDENTITY on MariaDB');
    }

    public function testFixedLengthReachesTheJoinAndPivotColumns(): void
    {
        $schema = self::schema(new MariaDbPlatform('11.8'), [Currency::class, Wallet::class]);
        $describe = static fn (Table $t): array => array_map(static fn (Column $c): array => [$c->name, $c->type, $c->length, $c->fixed], $t->getColumns());

        self::assertSame([['code', 'string', 3, true], ['digest', 'binary', 16, true]], $describe($schema->getTable('currency')));
        // une clé étrangère CHAR(3) vers CHAR(3) : MySQL et MariaDB refusent sinon de modifier la colonne
        self::assertSame([['id', 'integer', null, false], ['currency_id', 'string', 3, true]], $describe($schema->getTable('wallet')));
        self::assertSame([['wallet_id', 'integer', null, false], ['currency_id', 'string', 3, true]], $describe($schema->getTable('wallet_currency')));
    }

    // RG-02.4 : Auto vaut SEQUENCE sous PostgreSQL, IDENTITY ailleurs
    public function testAutoStrategyPerPlatform(): void
    {
        $postgres = self::schema(new PostgreSqlPlatform('16'), [Author::class, Book::class, Label::class]);
        self::assertSame(['author_id_seq', 'book_id_seq', 'label_id_seq'], array_map(static fn ($s): string => $s->name, $postgres->getSequences()));
        self::assertFalse($postgres->getTable('author')->getColumn('id')->autoincrement);

        foreach ([new MySqlPlatform('8.4'), new MariaDbPlatform('11.8'), new SqlitePlatform('3.46')] as $platform) {
            $schema = self::schema($platform, [Author::class, Book::class, Label::class]);
            self::assertSame([], $schema->getSequences(), $platform->getName());
            self::assertTrue($schema->getTable('author')->getColumn('id')->autoincrement, $platform->getName());
        }
    }

    public function testExplicitSequenceName(): void
    {
        $schema = self::schema(new PostgreSqlPlatform('16'), [Invoice::class, Client::class, InvoiceLine::class, Tag::class]);

        self::assertSame(['invoice_id_seq', 'client_id_seq', 'invoice_line_id_seq', 'tag_seq'], array_map(static fn ($s): string => $s->name, $schema->getSequences()));
    }

    public function testSequenceIsRefusedWhereThePlatformHasNone(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(Tag::class . '::$id: GenerationStrategy::Sequence is not supported by SQLite; use Auto or Identity.');

        // SQLite plutôt que MySQL : la fixture Invoice a un vecteur, que MySQL refuse avant
        self::schema(new SqlitePlatform('3.46'), [Invoice::class, Client::class, InvoiceLine::class, Tag::class]);
    }

    // la fixture aux 33 attributs : embeddable, index partiel non unique, OneToOne, ManyToMany
    public function testInvoiceFixture(): void
    {
        $invoice = self::schema(new PostgreSqlPlatform('16'), [Invoice::class, Client::class, InvoiceLine::class, Tag::class])->getTable('invoice');

        self::assertTrue($invoice->getColumn('billing_city')->nullable, 'RG-02.5');
        self::assertSame(80, $invoice->getColumn('billing_city')->length);
        self::assertSame('string', $invoice->getColumn('status')->type, 'a string-backed enum');
        self::assertSame(['vector', 1024], [$invoice->getColumn('embedding')->type, $invoice->getColumn('embedding')->length]);

        $indexes = array_values(self::indexes($invoice));
        // l'index partiel non unique devient complet (plus large, jamais faux) ; décision A
        // entité #[Tenanted] : tenant_id en tête des index déclarés (RG-08.1)
        self::assertContains([['tenant_id', 'status', 'issued_at'], false], $indexes);
        self::assertContains([['tenant_id', 'issued_at'], false], $indexes);
        self::assertContains([['tenant_id', 'number'], true], $indexes);
        self::assertContains([['client_id'], false], $indexes);
        self::assertContains([['credit_note_id'], true], $indexes);

        // ADR 0017 : #[VectorIndex] et #[FullTextIndex], sans le tenant en tête (ni l'un ni l'autre
        // ne couvre une colonne entière ; le filtre tenant s'applique avant le classement)
        $search = array_values(array_filter($invoice->getIndexes(), static fn (Index $i): bool => IndexKind::Regular !== $i->kind));
        self::assertSame([['number', 'notes'], IndexKind::FullText, 'french'], [$search[0]->columns, $search[0]->kind, $search[0]->language]);
        self::assertSame([['embedding'], IndexKind::Vector, VectorDistance::Cosine, 16, 64], [$search[1]->columns, $search[1]->kind, $search[1]->distance, $search[1]->m, $search[1]->efConstruction]);
    }

    // RG-08.1 : colonne du tenant indexée, unicité par tenant
    public function testTenantedEntityGetsAnIndexedTenantColumn(): void
    {
        $note = self::schema(new MySqlPlatform('8.4'), [Note::class])->getTable('note');

        self::assertSame(['tenant_id', 'integer', false, null, null, false], self::columns($note)[2]);
        // l'index unique par tenant couvre déjà la colonne du tenant
        self::assertSame([[['tenant_id', 'code'], true]], array_values(self::indexes($note)));
    }

    // ADR 0014 : type de l'EntityManager, index propre, colonne nullable des référentiels partagés
    public function testTenantColumnTypeAndGlobalRows(): void
    {
        $driver = new AttributeDriver(tenantType: 'uuid');
        $schema = new SchemaTool(new PostgreSqlPlatform('16'))->fromMetadata(array_map($driver->loadMetadata(...), [Invoice::class, Client::class, InvoiceLine::class, Tag::class]));

        $client = $schema->getTable('client');
        self::assertSame('uuid', $client->getColumn('tenant_id')->type);
        self::assertFalse($client->getColumn('tenant_id')->nullable);
        self::assertSame([[['tenant_id'], false]], array_values(self::indexes($client)), 'no declared index covers it');
        self::assertTrue($schema->getTable('tag')->getColumn('tenant_id')->nullable, 'global: true');
    }

    // une propriété portant la colonne du tenant : pas de seconde colonne ; un index qui la nomme reste tel quel
    public function testTenantColumnCarriedByAProperty(): void
    {
        $ledger = self::schema(new SqlitePlatform('3.46'), [Ledger::class])->getTable('ledger');

        self::assertSame(['id', 'company_id', 'code', 'label'], array_column(self::columns($ledger), 0));
        self::assertEqualsCanonicalizing([['code', 'company_id'], ['company_id', 'label']], array_column(array_values(self::indexes($ledger)), 0));
    }

    public function testUniqueConstraintWithConditionIsRefusedUntilPhase8(): void
    {
        $metadata = new ClassMetadata(Inferred::class, 'inferred', new IdMapping('id'), [new FieldMapping('id', 'id', 'integer'), new FieldMapping('name', 'name', 'string')], indexes: [new IndexMapping(['name'], true, null, 'deleted_at IS NULL')]);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('the unique constraint on "name" has a "where" condition; partial indexes arrive with phase 8 (RG-08.15).');

        new SchemaTool(new PostgreSqlPlatform('16'))->fromMetadata([$metadata]);
    }

    public function testInvalidMappingIsRefusedBeforeBuildingTheSchema(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('unknown type "money"');

        new SchemaTool(new PostgreSqlPlatform('16'))->fromMetadata([new AttributeDriver()->loadMetadata(UnknownTypeEntity::class)]);
    }

    public function testEmbeddableAndScalarColumns(): void
    {
        $schema = self::schema(new SqlitePlatform('3.46'), [Person::class, Inferred::class]);

        self::assertSame(['uuid', 'home_street', 'home_zip', 'home_city', 'street', 'zip', 'city'], array_map(static fn (Column $c): string => $c->name, $schema->getTable('people')->getColumns()));
        self::assertFalse($schema->getTable('people')->getColumn('home_city')->nullable);
        self::assertTrue($schema->getTable('people')->getColumn('city')->nullable);
        self::assertSame(['uuid'], $schema->getTable('people')->primaryKey);

        $inferred = $schema->getTable('inferred');
        self::assertSame(['integer', 1], [$inferred->getColumn('priority')->type, $inferred->getColumn('priority')->default]);
        self::assertSame(['decimal', 10, 2], [$inferred->getColumn('amount')->type, $inferred->getColumn('amount')->precision, $inferred->getColumn('amount')->scale]);
        self::assertSame([Index::class], array_values(array_unique(array_map(static fn (Index $i): string => $i::class, $inferred->getIndexes()))));
        self::assertSame(GenerationStrategy::Identity, new SchemaTool(new SqlitePlatform('3.46'))->resolveStrategy(GenerationStrategy::Auto));
        self::assertNull(new SchemaTool(new SqlitePlatform('3.46'))->resolveStrategy(null));
    }
}
