<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Vector;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Vector\HybridOptions;

/**
 * RG-09.5 : k = 60 et des poids égaux par défaut ; des candidats en nombre suffisant pour la fusion.
 */
final class HybridOptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $options = new HybridOptions();

        self::assertSame([60, 1.0, 1.0], [$options->k, $options->textWeight, $options->vectorWeight]);
        self::assertSame(40, $options->candidatesFor(5));
        self::assertSame(100, $options->candidatesFor(25));
        self::assertSame(12, new HybridOptions(candidates: 12)->candidatesFor(25));
    }

    /**
     * @return iterable<string, array{\Closure(): HybridOptions}>
     */
    public static function invalid(): iterable
    {
        yield 'k' => [static fn (): HybridOptions => new HybridOptions(k: 0)];
        yield 'negative weight' => [static fn (): HybridOptions => new HybridOptions(textWeight: -1.0)];
        yield 'both weights zero' => [static fn (): HybridOptions => new HybridOptions(textWeight: 0.0, vectorWeight: 0.0)];
        yield 'candidates' => [static fn (): HybridOptions => new HybridOptions(candidates: 0)];
    }

    /**
     * @param \Closure(): HybridOptions $make
     */
    #[DataProvider('invalid')]
    public function testInvalidIsRefused(\Closure $make): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid hybrid search options');

        $make();
    }
}
