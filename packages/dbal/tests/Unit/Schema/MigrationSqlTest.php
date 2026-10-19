<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;

/**
 * Instantanés du SQL de migration et ordre RG-01.10.
 */
final class MigrationSqlTest extends TestCase
{
    private static function from(): Schema
    {
        return new Schema([
            new Table('client', [new Column('id', 'bigint'), new Column('name', 'string', length: 100)], ['id']),
            new Table('invoice', [new Column('id', 'bigint'), new Column('client_id', 'bigint'), new Column('qty', 'integer', default: 1), new Column('old', 'string', nullable: true)], ['id'])
                ->withIndex(new Index('idx_old', ['old']))
                ->withForeignKeyTo(['client_id'], 'client', ['id'], ForeignKeyAction::Cascade, 'fk_client'),
            new Table('legacy', [new Column('id', 'integer'), new Column('client_id', 'bigint')], ['id'])->withForeignKeyTo(['client_id'], 'client', ['id'], name: 'fk_legacy'),
        ]);
    }

    private static function to(): Schema
    {
        return new Schema([
            new Table('client', [new Column('id', 'bigint'), new Column('name', 'string', length: 100)], ['id']),
            new Table('invoice', [new Column('id', 'bigint'), new Column('client_id', 'bigint'), new Column('qty', 'bigint', nullable: true, default: 5), new Column('note', 'text', nullable: true)], ['id'])
                ->withIndex(new Index('idx_client', ['client_id']))
                ->withForeignKeyTo(['client_id'], 'client', ['id'], ForeignKeyAction::SetNull, 'fk_client'),
            new Table('tag', [new Column('id', 'integer'), new Column('invoice_id', 'bigint')], ['id'])->withForeignKeyTo(['invoice_id'], 'invoice', ['id'], name: 'fk_tag'),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function sql(AbstractPlatform $platform, Schema $from, Schema $to): array
    {
        return $platform->getMigrationSql(new Comparator($platform)->compare($from, $to));
    }

    // RG-01.10 : drop FK, drop index, drop tables, create tables, alter colonnes, create index, create FK
    public function testMySql(): void
    {
        self::assertSame([
            'ALTER TABLE `legacy` DROP FOREIGN KEY `fk_legacy`',
            'ALTER TABLE `invoice` DROP FOREIGN KEY `fk_client`',
            // index implicite de MySQL : aucun index déclaré ne couvrait client_id
            'DROP INDEX `fk_client` ON `invoice`',
            'DROP INDEX `idx_old` ON `invoice`',
            'DROP TABLE `legacy`',
            'CREATE TABLE `tag` (`id` INT NOT NULL, `invoice_id` BIGINT NOT NULL, PRIMARY KEY (`id`))',
            'ALTER TABLE `invoice` DROP COLUMN `old`',
            'ALTER TABLE `invoice` ADD COLUMN `note` LONGTEXT NULL',
            'ALTER TABLE `invoice` MODIFY COLUMN `qty` BIGINT NULL DEFAULT 5',
            'CREATE INDEX `idx_client` ON `invoice` (`client_id`)',
            'ALTER TABLE `tag` ADD CONSTRAINT `fk_tag` FOREIGN KEY (`invoice_id`) REFERENCES `invoice` (`id`) ON DELETE NO ACTION',
            'ALTER TABLE `invoice` ADD CONSTRAINT `fk_client` FOREIGN KEY (`client_id`) REFERENCES `client` (`id`) ON DELETE SET NULL',
        ], self::sql(new MySqlPlatform('8.4'), self::from(), self::to()));
    }

    public function testPostgreSql(): void
    {
        self::assertSame([
            'ALTER TABLE "legacy" DROP CONSTRAINT "fk_legacy"',
            'ALTER TABLE "invoice" DROP CONSTRAINT "fk_client"',
            'DROP INDEX "idx_old"',
            'DROP TABLE "legacy"',
            'CREATE TABLE "tag" ("id" INTEGER NOT NULL, "invoice_id" BIGINT NOT NULL, PRIMARY KEY ("id"))',
            'ALTER TABLE "invoice" DROP COLUMN "old"',
            'ALTER TABLE "invoice" ADD COLUMN "note" TEXT NULL',
            'ALTER TABLE "invoice" ALTER COLUMN "qty" DROP DEFAULT',
            'ALTER TABLE "invoice" ALTER COLUMN "qty" TYPE BIGINT USING "qty"::BIGINT',
            'ALTER TABLE "invoice" ALTER COLUMN "qty" DROP NOT NULL',
            'ALTER TABLE "invoice" ALTER COLUMN "qty" SET DEFAULT 5',
            'CREATE INDEX "idx_client" ON "invoice" ("client_id")',
            'ALTER TABLE "tag" ADD CONSTRAINT "fk_tag" FOREIGN KEY ("invoice_id") REFERENCES "invoice" ("id") ON DELETE NO ACTION',
            'ALTER TABLE "invoice" ADD CONSTRAINT "fk_client" FOREIGN KEY ("client_id") REFERENCES "client" ("id") ON DELETE SET NULL',
        ], self::sql(new PostgreSqlPlatform('16'), self::from(), self::to()));
    }

    public function testMySqlKeepsTheForeignKeyIndexWhenADeclaredIndexCoversIt(): void
    {
        $from = new Schema([
            new Table('client', [new Column('id', 'bigint')], ['id']),
            new Table('invoice', [new Column('id', 'bigint'), new Column('client_id', 'bigint')], ['id'])
                ->withIndex(new Index('idx_client_id', ['client_id', 'id']))
                ->withForeignKeyTo(['client_id'], 'client', ['id'], name: 'fk_client'),
        ]);
        $to = $from->withTable($from->getTable('invoice')->withoutForeignKey('fk_client'));

        self::assertSame(['ALTER TABLE `invoice` DROP FOREIGN KEY `fk_client`'], self::sql(new MariaDbPlatform('11.8'), $from, $to));
    }

    public function testPrimaryKeyChange(): void
    {
        $from = new Schema([new Table('pivot', [new Column('a', 'integer'), new Column('b', 'integer')], ['a'])]);
        $to = new Schema([new Table('pivot', [new Column('a', 'integer'), new Column('b', 'integer')], ['a', 'b'])]);

        self::assertSame(['ALTER TABLE `pivot` DROP PRIMARY KEY', 'ALTER TABLE `pivot` ADD PRIMARY KEY (`a`, `b`)'], self::sql(new MySqlPlatform('8.4'), $from, $to));
        self::assertSame(['ALTER TABLE "pivot" DROP CONSTRAINT "pivot_pkey"', 'ALTER TABLE "pivot" ADD PRIMARY KEY ("a", "b")'], self::sql(new PostgreSqlPlatform('16'), $from, $to));
    }

    public function testPostgreSqlIdentityAndNullability(): void
    {
        $from = new Schema([new Table('t', [new Column('id', 'bigint'), new Column('label', 'string', nullable: true)], ['id'])]);
        $to = new Schema([new Table('t', [new Column('id', 'bigint', autoincrement: true), new Column('label', 'string', default: 'x')], ['id'])]);

        self::assertSame([
            'ALTER TABLE "t" ALTER COLUMN "id" ADD GENERATED BY DEFAULT AS IDENTITY',
            'ALTER TABLE "t" ALTER COLUMN "label" SET NOT NULL',
            'ALTER TABLE "t" ALTER COLUMN "label" SET DEFAULT \'x\'',
        ], self::sql(new PostgreSqlPlatform('16'), $from, $to));
        self::assertSame(['ALTER TABLE "t" ALTER COLUMN "id" DROP IDENTITY', 'ALTER TABLE "t" ALTER COLUMN "label" DROP DEFAULT', 'ALTER TABLE "t" ALTER COLUMN "label" DROP NOT NULL'], self::sql(new PostgreSqlPlatform('16'), $to, $from));
    }

    public function testSequences(): void
    {
        $from = new Schema([], [new Sequence('a_seq'), new Sequence('b_seq')]);
        $to = new Schema([], [new Sequence('b_seq', 1, 10), new Sequence('c_seq', 100)]);

        self::assertSame([
            'DROP SEQUENCE "a_seq"',
            'CREATE SEQUENCE "c_seq" START WITH 100 INCREMENT BY 1',
            'ALTER SEQUENCE "b_seq" INCREMENT BY 10',
        ], self::sql(new PostgreSqlPlatform('16'), $from, $to));
    }

    public function testEmptyDiffProducesNoSql(): void
    {
        self::assertSame([], self::sql(new MySqlPlatform('8.4'), self::from(), self::from()));
    }

    // SQLite : ce qu'ALTER TABLE sait faire, le reste attend la reconstruction (RG-01.11)
    public function testSqliteNativeAlterations(): void
    {
        $platform = new SqlitePlatform('3.46');
        $from = new Schema([new Table('t', [new Column('id', 'integer'), new Column('old', 'string', nullable: true)], ['id'])->withIndexOn(['old'], name: 'idx_old')]);
        $to = new Schema([new Table('t', [new Column('id', 'integer'), new Column('status', 'string', default: 'draft')], ['id'])]);

        self::assertSame([
            'DROP INDEX "idx_old"',
            'ALTER TABLE "t" DROP COLUMN "old"',
            'ALTER TABLE "t" ADD COLUMN "status" VARCHAR(255) NOT NULL DEFAULT \'draft\'',
        ], self::sql($platform, $from, $to));
    }

    public function testSqliteRefusesWhatNeedsARebuild(): void
    {
        $platform = new SqlitePlatform('3.46');
        $from = new Schema([new Table('t', [new Column('id', 'integer'), new Column('qty', 'integer')], ['id'])]);

        foreach ([
            'changed column' => new Schema([new Table('t', [new Column('id', 'integer'), new Column('qty', 'bigint')], ['id'])]),
            'not null column without default' => new Schema([new Table('t', [new Column('id', 'integer'), new Column('qty', 'integer'), new Column('x', 'integer')], ['id'])]),
            'primary key' => new Schema([new Table('t', [new Column('id', 'integer'), new Column('qty', 'integer')], ['id', 'qty'])]),
        ] as $case => $to) {
            try {
                self::sql($platform, $from, $to);
                self::fail(\sprintf('Expected InvalidSchemaException for %s.', $case));
            } catch (InvalidSchemaException $e) {
                self::assertStringContainsString('the table must be rebuilt', $e->getMessage(), $case);
            }
        }
    }
}
