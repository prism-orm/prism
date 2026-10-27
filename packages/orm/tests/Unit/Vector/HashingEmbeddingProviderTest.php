<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Vector;

use PHPUnit\Framework\TestCase;
use Prism\Orm\Exception\EmbeddingException;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Vector\EmbeddingProviderRegistry;
use Prism\Orm\Vector\HashingEmbeddingProvider;
use Prism\Orm\Vector\Vector;

/**
 * Le fournisseur des tests et du développement hors ligne (ADR 0018), et le registre par nom.
 */
final class HashingEmbeddingProviderTest extends TestCase
{
    public function testDeterministicNormalizedVectorsOfItsDimension(): void
    {
        $provider = new HashingEmbeddingProvider(32);
        [$a, $b] = $provider->embed(['Partial payment of an invoice', 'Partial payment of an invoice']);

        self::assertSame(['prism/hashing-32', 32], [$provider->model(), $provider->dimensions()]);
        self::assertCount(32, $a);
        self::assertSame($a, $b);
        self::assertSame($a, new HashingEmbeddingProvider(32)->embed(['partial PAYMENT, of an invoice!'])[0], 'mots en minuscules, ponctuation ignorée');
        self::assertEqualsWithDelta(1.0, sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $a))), 1e-12);
    }

    // il rapproche les textes qui partagent des mots, sans comprendre le sens
    public function testSharedWordsAreCloser(): void
    {
        [$query, $near, $far] = new HashingEmbeddingProvider(256)->embed(['invoice payment', 'payment of an invoice', 'stock levels in the warehouse']);

        self::assertGreaterThan(new Vector($query)->cosine(new Vector($far)), new Vector($query)->cosine(new Vector($near)));
    }

    public function testTextWithoutWordsGivesTheZeroVector(): void
    {
        self::assertSame(array_fill(0, 4, 0.0), new HashingEmbeddingProvider(4)->embed([' ... '])[0]);
    }

    public function testRejectsANonPositiveDimension(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HashingEmbeddingProvider(0);
    }

    public function testRegistryByName(): void
    {
        $provider = new HashingEmbeddingProvider(8);
        $registry = new EmbeddingProviderRegistry(['default' => $provider]);

        self::assertSame($provider, $registry->get('default'));
        self::assertSame([true, false, ['default']], [$registry->has('default'), $registry->has('openai'), $registry->names()]);

        $this->expectException(EmbeddingException::class);
        $this->expectExceptionMessage('Unknown embedding provider "openai"; registered providers: default.');
        $registry->get('openai');
    }
}
