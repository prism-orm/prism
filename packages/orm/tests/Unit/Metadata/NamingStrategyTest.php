<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Orm\Metadata\Naming\DefaultNamingStrategy;
use Prism\Orm\Metadata\Naming\UnderscoreNamingStrategy;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Invoice;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\InvoiceLine;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Tag;

final class NamingStrategyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function snakeCases(): iterable
    {
        yield 'camelCase' => ['createdAt', 'created_at'];
        yield 'already snake' => ['created_at', 'created_at'];
        yield 'single word' => ['total', 'total'];
        yield 'trailing acronym' => ['userID', 'user_id'];
        yield 'leading acronym' => ['HTTPRequest', 'http_request'];
        yield 'inner acronym' => ['parseXMLFile', 'parse_xml_file'];
        yield 'digit' => ['line2Amount', 'line2_amount'];
        yield 'pascal case' => ['InvoiceLine', 'invoice_line'];
    }

    #[DataProvider('snakeCases')]
    public function testUnderscoreColumnNames(string $property, string $column): void
    {
        self::assertSame($column, new UnderscoreNamingStrategy()->propertyToColumnName($property));
    }

    public function testUnderscoreStrategy(): void
    {
        $naming = new UnderscoreNamingStrategy();

        // le namespace est ignoré : seul le nom court compte
        self::assertSame('invoice_line', $naming->classToTableName(InvoiceLine::class));
        self::assertSame('invoice', $naming->classToTableName(Invoice::class));
        self::assertSame('billing_address_', $naming->embeddedColumnPrefix('billingAddress'));
        self::assertSame('client_id', $naming->joinColumnName('client'));
        self::assertSame('main_contact_uuid', $naming->joinColumnName('mainContact', 'uuid'));
        self::assertSame('invoice_line_tag', $naming->joinTableName(InvoiceLine::class, Tag::class));
        self::assertSame('invoice_line_id', $naming->joinKeyColumnName(InvoiceLine::class));
    }

    public function testDefaultStrategyKeepsNamesAsWritten(): void
    {
        $naming = new DefaultNamingStrategy();

        self::assertSame('InvoiceLine', $naming->classToTableName(InvoiceLine::class));
        self::assertSame('createdAt', $naming->propertyToColumnName('createdAt'));
        self::assertSame('billingAddress_', $naming->embeddedColumnPrefix('billingAddress'));
        self::assertSame('client_id', $naming->joinColumnName('client'));
        self::assertSame('InvoiceLine_Tag', $naming->joinTableName(InvoiceLine::class, Tag::class));
        self::assertSame('Tag_id', $naming->joinKeyColumnName(Tag::class));
    }
}
