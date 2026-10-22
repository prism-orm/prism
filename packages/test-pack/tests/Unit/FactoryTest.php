<?php

declare(strict_types=1);

namespace Prism\TestPack\Tests\Unit;

use Prism\Orm\EntityManagerInterface;
use Prism\TestPack\Exception\TestPackException;
use Prism\TestPack\Tests\Fixtures\Entity\Billing\Invoice;
use Prism\TestPack\Tests\Fixtures\Entity\Library\Author;
use Prism\TestPack\Tests\Fixtures\Entity\Library\Book;
use Prism\TestPack\Tests\Fixtures\Factory\AuthorFactory;
use Prism\TestPack\Tests\Fixtures\Factory\BookFactory;
use Prism\TestPack\Tests\Fixtures\Factory\InvoiceFactory;
use Prism\TestPack\Tests\Fixtures\Factory\NamelessAuthorFactory;
use Prism\TestPack\Tests\Support\PrismKernelTestCase;

/**
 * Les factories du test-pack, sur SQLite en mémoire : constructeur et propriétés, séquences,
 * relations par factory, make() sans persistance, many() en un flush, EntityManager de la classe.
 */
final class FactoryTest extends PrismKernelTestCase
{
    protected static function url(): string
    {
        return 'sqlite:///:memory:?serverVersion=3.46.0';
    }

    private static function em(string $name = 'default'): EntityManagerInterface
    {
        $em = static::getContainer()->get('prism.entity_manager.' . $name);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    public function testCreateBuildsPersistsAndFlushes(): void
    {
        $book = BookFactory::new(['title' => 'Dune'])->create();

        self::assertNotNull($book->id, 'flushed: the identifier is generated');
        self::assertSame('Dune', $book->title);
        self::assertNotNull($book->author->id, 'the author factory is created with it');
        self::assertSame('0000000000000', $book->isbn, 'an optional constructor argument keeps its default');
        self::assertNull($book->note, 'a readonly property is written once, outside the constructor');

        self::em()->clear();
        self::assertSame('Dune', self::em()->find(Book::class, $book->id)?->title);
    }

    public function testMakeNeverPersists(): void
    {
        $book = BookFactory::new()->make();

        self::assertNull($book->id);
        self::assertNull($book->author->id);
        self::assertFalse(self::em()->contains($book));
        self::assertFalse(self::em()->contains($book->author));
    }

    public function testManyInOneFlushWithASequence(): void
    {
        $authors = AuthorFactory::new()->many(3);

        self::assertCount(3, $authors);
        $numbers = array_map(static fn (Author $a): int => (int) substr($a->name, 7), $authors);
        self::assertSame([$numbers[0], $numbers[0] + 1, $numbers[0] + 2], $numbers, 'the closure gets the sequence number');
        self::assertSame(3, self::em()->getRepository(Author::class)->count());
    }

    public function testWithAddsAttributesAndKeepsTheFactory(): void
    {
        $factory = AuthorFactory::new(['name' => 'Ada']);
        $author = $factory->with(['email' => 'ada@example.test'])->make();

        self::assertSame(['Ada', 'ada@example.test'], [$author->name, $author->email]);
        self::assertNull($factory->make()->email, 'with() returns a copy');
    }

    public function testEachEntityGoesToTheEntityManagerThatMapsIt(): void
    {
        $invoice = InvoiceFactory::new()->create();

        self::assertTrue(self::em('billing')->contains($invoice));
        self::assertFalse(self::em()->contains($invoice));
        self::assertSame('10.00', self::em('billing')->find(Invoice::class, $invoice->id)?->total);
    }

    public function testLastValueWins(): void
    {
        self::assertSame('2', BookFactory::new(['isbn' => '1'])->with(['isbn' => '2'])->make()->isbn);
    }

    public function testMisuseIsReported(): void
    {
        $cases = [
            [static fn (): object => AuthorFactory::new(['pages' => 3])->make(), 'sets "pages", which is neither a constructor argument nor a property of ' . Author::class],
            [static fn (): object => NamelessAuthorFactory::new()->make(), 'needs a value for the constructor argument $name of ' . Author::class],
            [static fn (): object => BookFactory::new(['isbn' => '1'])->with(['note' => 'x'])->make(), ''],
            [static fn (): object => AuthorFactory::new(['slug' => 'x'])->make(), 'cannot set ' . Author::class . '::$slug: the readonly property is already written by the constructor'],
            [static fn (): array => AuthorFactory::new()->many(0), 'many() needs at least one entity, got 0'],
            [static fn (): object => AuthorFactory::new()->using('archive')->create(), 'Unknown entity manager "archive"; declared: default, billing.'],
        ];
        foreach ($cases as [$build, $message]) {
            if ('' === $message) {
                $build();
                continue;
            }
            try {
                $build();
                self::fail('Expected TestPackException: ' . $message);
            } catch (TestPackException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }
}
