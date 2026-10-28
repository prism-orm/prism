<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Vector;

use PHPUnit\Framework\TestCase;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Vector\Distance;
use Prism\Orm\Vector\Vector;

/**
 * Le value object Vector (RG-09.2) : valeurs flottantes, normalisation, cosinus, distances calculées
 * comme la base (plus petite = plus proche).
 */
final class VectorTest extends TestCase
{
    public function testHoldsFloats(): void
    {
        $vector = new Vector([1, 2.5, -3]);

        self::assertSame([1.0, 2.5, -3.0], $vector->toArray());
        self::assertSame(3, $vector->dimensions());
        self::assertCount(3, $vector);
    }

    public function testNormalize(): void
    {
        self::assertEqualsWithDelta([0.6, 0.8], new Vector([3.0, 4.0])->normalize()->toArray(), 1e-12);
    }

    public function testCosineAndDistances(): void
    {
        $a = new Vector([1.0, 0.0]);
        $b = new Vector([0.0, 2.0]);
        $c = new Vector([-3.0, 0.0]);

        self::assertEqualsWithDelta(0.0, $a->cosine($b), 1e-12);
        self::assertEqualsWithDelta(-1.0, $a->cosine($c), 1e-12);
        self::assertEqualsWithDelta(2.0, $a->distance($c, Distance::Cosine), 1e-12);
        self::assertEqualsWithDelta(4.0, $a->distance($c, Distance::Euclidean), 1e-12);
        self::assertEqualsWithDelta(3.0, $a->distance($c, Distance::InnerProduct), 1e-12);
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [static fn (): Vector => new Vector([]), 'non-empty list'];
        yield 'NaN' => [static fn (): Vector => new Vector([1.0, \NAN]), 'must be finite'];
        yield 'infinite' => [static fn (): Vector => new Vector([\INF]), 'must be finite'];
        yield 'zero vector normalized' => [static fn (): Vector => new Vector([0.0, 0.0])->normalize(), 'cannot be normalized'];
        yield 'cosine of a zero vector' => [static fn (): float => new Vector([0.0])->cosine(new Vector([1.0])), 'undefined'];
        yield 'different dimensions' => [static fn (): float => new Vector([1.0])->distance(new Vector([1.0, 2.0]), Distance::Euclidean), 'have 1 and 2 dimensions'];
    }

    /**
     * @param \Closure(): mixed $make
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalidIsRefused(\Closure $make, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $make();
    }
}
