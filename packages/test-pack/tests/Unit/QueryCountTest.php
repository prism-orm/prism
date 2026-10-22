<?php

declare(strict_types=1);

namespace Prism\TestPack\Tests\Unit;

use PHPUnit\Framework\AssertionFailedError;
use Prism\Orm\EntityManagerInterface;
use Prism\TestPack\Tests\Fixtures\Entity\Library\Author;
use Prism\TestPack\Tests\Fixtures\Entity\Library\Book;
use Prism\TestPack\Tests\Fixtures\Factory\BookFactory;
use Prism\TestPack\Tests\Support\PrismKernelTestCase;

/**
 * assertQueryCount() : le garde-fou du pilier 1 dans les tests d'une application.
 */
final class QueryCountTest extends PrismKernelTestCase
{
    protected static function url(): string
    {
        return 'sqlite:///:memory:?serverVersion=3.46.0';
    }

    private static function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    // dix livres et leurs auteurs : deux requêtes, grâce au batch fetching
    public function testCountsTheQueriesOfTheCode(): void
    {
        BookFactory::new()->many(10);
        self::em()->clear();

        $names = $this->assertQueryCount(2, static function (): array {
            return array_map(static fn (Book $b): string => $b->author->name, self::em()->getRepository(Book::class)->findAll());
        });

        self::assertCount(10, $names, 'the result of the code is returned');
    }

    public function testFailureListsTheQueries(): void
    {
        try {
            $this->assertQueryCount(0, static fn (): ?Author => self::em()->find(Author::class, 1), connection: 'default');
            self::fail('Expected a failure.');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('0 queries expected, 1 executed:', $e->getMessage());
            self::assertStringContainsString('[default] SELECT', $e->getMessage());
        }
    }
}
