<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Type;

use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Dbal\Exception\ConversionException;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\ParameterType;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Type\VectorType;

/**
 * Type vector (ADR 0017, RG-09.2) : texte à l'écriture sur toutes les plateformes, binaire
 * float32 (MariaDB) ou texte à la lecture ; SQL du paramètre propre à chaque plateforme.
 */
final class VectorTypeTest extends TypeTestCase
{
    public function testWritesTheShortestTextThatReadsBackTheSameFloats(): void
    {
        $type = new VectorType();

        self::assertSame('[0.5,-1.25,3.0,0.1,1.0e-7]', $type->toDatabase([0.5, -1.25, 3, 0.1, 1.0e-7], self::context()));
        self::assertSame([0.5, -1.25, 3.0, 0.1, 1.0e-7], $type->toPhp('[0.5,-1.25,3.0,0.1,1.0e-7]', self::context()));
    }

    // MariaDB rend la colonne brute : float32 little-endian
    public function testReadsTheBinaryFormOfMariaDb(): void
    {
        $read = new VectorType()->toPhp(pack('g*', 0.5, -1.25, 3.0), self::context());

        self::assertSame([0.5, -1.25, 3.0], $read);
    }

    public function testReadsAStream(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, '[1.0,2.0]');
        rewind($stream);

        self::assertSame([1.0, 2.0], new VectorType()->toPhp($stream, self::context()));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPhpValues(): iterable
    {
        yield 'empty' => [[]];
        yield 'string' => ['[1,2]'];
        yield 'map' => [['x' => 1.0]];
        yield 'not a number' => [[1.0, '2']];
        yield 'NaN' => [[1.0, \NAN]];
        yield 'infinite' => [[\INF]];
        yield 'nested' => [[[1.0]]];
    }

    #[DataProvider('invalidPhpValues')]
    public function testRefusesWhatIsNotAListOfFiniteFloats(mixed $value): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('a non-empty list of finite floats');

        new VectorType()->toDatabase($value, self::context());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidDatabaseValues(): iterable
    {
        yield 'empty' => [''];
        yield 'bad JSON' => ['[1,'];
        yield 'JSON object' => ['[{"a":1}]'];
        yield 'truncated binary' => ["\x00\x00\x00"];
        yield 'integer' => [3];
    }

    #[DataProvider('invalidDatabaseValues')]
    public function testRefusesAnUnreadableDatabaseValue(mixed $value): void
    {
        $this->expectException(ConversionException::class);

        new VectorType()->toPhp($value, self::context());
    }

    public function testIsBoundAsText(): void
    {
        self::assertSame(ParameterType::String, new VectorType()->getBindingType());
    }

    public function testParameterSqlConvertsTheTextOnEachPlatform(): void
    {
        $type = new VectorType();

        self::assertSame('VEC_FromText(:p)', $type->getParameterSql(':p', new MariaDbPlatform('11.8')));
        self::assertSame('CAST(:p AS vector)', $type->getParameterSql(':p', new PostgreSqlPlatform('16')));
        self::assertSame(':p', $type->getParameterSql(':p', new SqlitePlatform('3.45')));
    }

    public function testMySqlHasNoVectors(): void
    {
        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage('MySQL does not support the vector type: it has neither a vector distance nor a vector index. Use MariaDB 11.8+ or PostgreSQL with pgvector 0.8+.');

        new VectorType()->getParameterSql(':p', new MySqlPlatform('8.4'));
    }
}
