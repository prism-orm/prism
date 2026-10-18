<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\ParameterType;

final class ParameterTypeTest extends TestCase
{
    public function testInference(): void
    {
        self::assertSame(ParameterType::Null, ParameterType::infer(null));
        self::assertSame(ParameterType::Integer, ParameterType::infer(42));
        self::assertSame(ParameterType::Boolean, ParameterType::infer(false));
        self::assertSame(ParameterType::String, ParameterType::infer('x'));
        // un float part en chaîne : jamais de perte silencieuse via un PARAM_INT
        self::assertSame(ParameterType::String, ParameterType::infer(1.5));
        self::assertNull(ParameterType::infer([]));
        self::assertNull(ParameterType::infer(new \stdClass()));
    }

    public function testPdoMapping(): void
    {
        self::assertSame(\PDO::PARAM_STR, ParameterType::String->toPdo());
        self::assertSame(\PDO::PARAM_INT, ParameterType::Integer->toPdo());
        self::assertSame(\PDO::PARAM_BOOL, ParameterType::Boolean->toPdo());
        self::assertSame(\PDO::PARAM_NULL, ParameterType::Null->toPdo());
        self::assertSame(\PDO::PARAM_LOB, ParameterType::Binary->toPdo());
    }
}
