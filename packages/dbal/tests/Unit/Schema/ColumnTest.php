<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ForeignKey;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\Sequence;

final class ColumnTest extends TestCase
{
    public function testWithersReturnCopies(): void
    {
        $column = new Column('total', 'decimal', precision: 12, scale: 2);

        $changed = $column->withName('amount')->withNullable(true)->withDefault('0.00');

        self::assertSame('total', $column->name);
        self::assertFalse($column->nullable);
        self::assertNull($column->default);
        self::assertSame(['amount', true, '0.00', 12, 2], [$changed->name, $changed->nullable, $changed->default, $changed->precision, $changed->scale]);
        self::assertSame(['string', 30], [$column->withType('string', 30)->type, $column->withType('string', 30)->length]);
        self::assertTrue(new Column('id', 'integer')->withAutoincrement(true)->autoincrement);
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function invalidDefinitions(): iterable
    {
        yield 'empty name' => [static fn (): Column => new Column('', 'string'), 'The column name must not be empty'];
        yield 'empty type' => [static fn (): Column => new Column('a', ''), 'the type must not be empty'];
        yield 'zero length' => [static fn (): Column => new Column('a', 'string', length: 0), 'the length must be positive, got 0'];
        yield 'decimal without precision' => [static fn (): Column => new Column('a', 'decimal'), 'a decimal column needs a precision and a scale'];
        yield 'precision too high' => [static fn (): Column => new Column('a', 'decimal', precision: 66, scale: 2), 'between 1 and 65, got 66'];
        yield 'scale above precision' => [static fn (): Column => new Column('a', 'decimal', precision: 4, scale: 5), 'the scale must be between 0 and the precision'];
        yield 'default on text' => [static fn (): Column => new Column('a', 'text', default: 'x'), 'a text column cannot have a default value'];
        yield 'default on json' => [static fn (): Column => new Column('a', 'json', default: '[]'), 'a json column cannot have a default value'];
        yield 'infinite default' => [static fn (): Column => new Column('a', 'float', default: \INF), 'the default value must be finite'];
        yield 'autoincrement string' => [static fn (): Column => new Column('a', 'string', autoincrement: true), 'only integer, smallint and bigint'];
        yield 'nullable autoincrement' => [static fn (): Column => new Column('a', 'integer', nullable: true, autoincrement: true), 'NOT NULL and has no default'];
        yield 'empty index name' => [static fn (): Index => new Index('', ['a']), 'The index name must not be empty'];
        yield 'index without columns' => [static fn (): Index => new Index('i', []), 'expected a non-empty list of column names'];
        yield 'index column twice' => [static fn (): Index => new Index('i', ['a', 'A']), 'column "A" is listed twice'];
        yield 'fk length mismatch' => [static fn (): ForeignKey => new ForeignKey('f', ['a', 'b'], 't', ['id']), 'must have the same length'];
        yield 'fk empty target' => [static fn (): ForeignKey => new ForeignKey('f', ['a'], '', ['id']), 'the foreign table must not be empty'];
        yield 'sequence increment zero' => [static fn (): Sequence => new Sequence('s', 1, 0), 'the increment must not be zero'];
    }

    /**
     * @param \Closure(): mixed $build
     */
    #[DataProvider('invalidDefinitions')]
    public function testInvalidDefinitionsAreRefused(\Closure $build, string $message): void
    {
        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage($message);

        $build();
    }
}
