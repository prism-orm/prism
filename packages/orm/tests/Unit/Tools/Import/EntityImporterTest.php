<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tools\Import;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Table;
use Prism\Orm\Tools\Import\EntityImporter;
use Prism\Orm\Tools\Import\ImportResult;

/**
 * RG-06.10 : entités final, typées strictement, relations déduites des clés étrangères, mapping
 * minimal mais exact ; ce que Prism 1.0 ne représente pas est signalé, jamais approximé en silence.
 */
final class EntityImporterTest extends TestCase
{
    public static function schema(): Schema
    {
        $country = new Table('shop_country', [new Column('id', 'integer', autoincrement: true), new Column('code', 'string', length: 2), new Column('name', 'string', nullable: true, length: 80)], ['id']);
        $customer = new Table('shop_customer', [
            new Column('id', 'integer', autoincrement: true),
            new Column('country_id', 'integer', nullable: true),
            new Column('full_name', 'string', length: 80),
            new Column('active', 'boolean', default: true),
            new Column('balance', 'decimal', default: '0.00', precision: 12, scale: 2),
            new Column('born', 'date_immutable', nullable: true),
            new Column('created_at', 'datetime_immutable'),
            new Column('notes', 'text', nullable: true),
            new Column('rating', 'float', nullable: true),
            new Column('meta', 'json', nullable: true),
            new Column('visits', 'smallint', default: 0),
            new Column('photo', 'binary', nullable: true),
        ], ['id']);
        $customer = $customer->withIndexOn(['full_name'], name: 'IDX_CUSTOMER_NAME')->withForeignKeyTo(['country_id'], 'shop_country', ['id'], ForeignKeyAction::SetNull, 'FK_CUSTOMER_COUNTRY');
        $profile = new Table('shop_profile', [new Column('id', 'integer', autoincrement: true), new Column('customer_id', 'integer'), new Column('bio', 'string', nullable: true, length: 200)], ['id']);
        $profile = $profile->withIndexOn(['customer_id'], unique: true, name: 'UNIQ_PROFILE_CUSTOMER')->withForeignKeyTo(['customer_id'], 'shop_customer', ['id'], ForeignKeyAction::Cascade, 'FK_PROFILE_CUSTOMER');
        $list = new Table('shop_list', [new Column('code', 'string', length: 10), new Column('customer', 'integer', default: 1), new Column('a', 'integer', nullable: true), new Column('b', 'integer', nullable: true)], ['code']);
        $list = $list->withForeignKeyTo(['customer'], 'shop_customer', ['id'], ForeignKeyAction::NoAction, 'FK_LIST_CUSTOMER')
            ->withForeignKeyTo(['a', 'b'], 'shop_pair', ['a', 'b'], name: 'FK_LIST_PAIR');

        return new Schema([
            $country->withIndexOn(['code'], unique: true, name: 'UNIQ_COUNTRY_CODE'),
            $customer,
            $profile,
            $list,
            new Table('shop_log', [new Column('line', 'text')]),
            new Table('shop_pair', [new Column('a', 'integer'), new Column('b', 'integer')], ['a', 'b']),
        ]);
    }

    private static function import(): ImportResult
    {
        return new EntityImporter()->import(self::schema(), 'App\\Entity', 'shop_');
    }

    public function testEntitiesAreTheReference(): void
    {
        $result = self::import();

        self::assertSame(['Country.php', 'Customer.php', 'ListEntity.php', 'Profile.php'], array_keys($result->entities), 'a reserved word gets a suffix');
        foreach ($result->entities as $file => $code) {
            self::assertStringEqualsFile(__DIR__ . '/Expected/' . basename($file, '.php') . '.txt', $code, $file);
        }
    }

    public function testWhatPrismCannotRepresentIsReported(): void
    {
        $result = self::import();

        self::assertSame([
            'shop_log' => 'it has no primary key: an entity needs exactly one identifier (RG-02.4).',
            'shop_pair' => 'its primary key is composite: an entity has exactly one identifier in 1.0 (RG-02.4).',
        ], $result->skipped);
        self::assertSame(['shop_list.FK_LIST_PAIR' => 'a, b → shop_pair: it is composite; the column stays a scalar property.'], $result->foreignKeys);
        // une colonne de jointure sans index (Prism en ajoute un), et le défaut d'une colonne de jointure
        self::assertSame(['shop_customer.country_id', 'shop_list.customer'], array_keys($result->approximations));
        self::assertStringContainsString('The default 1 of the join column', $result->approximations['shop_list.customer']);
    }

    public function testImportIsDeterministicAndKeepsPrefixedNamesWithoutStrip(): void
    {
        self::assertSame(self::import()->entities, self::import()->entities);
        self::assertSame(['ShopCountry.php', 'ShopCustomer.php', 'ShopList.php', 'ShopProfile.php'], array_keys(new EntityImporter()->import(self::schema(), 'App\\Entity')->entities));
    }

    public function testClassNameCollisionIsReported(): void
    {
        $schema = new Schema([new Table('order_line', [new Column('id', 'integer')], ['id']), new Table('order-line', [new Column('id', 'integer')], ['id'])]);

        $result = new EntityImporter()->import($schema, 'App\\Entity');

        self::assertSame(['OrderLine.php'], array_keys($result->entities));
        self::assertSame(['order-line' => 'its class name OrderLine is already taken by the table order_line.'], $result->skipped, 'the first table keeps the name');
    }
}
