<?php

declare(strict_types=1);

namespace Prism\TestPack\Tests\Functional;

use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Orm\EntityManagerInterface;
use Prism\TestPack\Attribute\WithRealTransactions;
use Prism\TestPack\Tests\Fixtures\Entity\Library\Author;
use Prism\TestPack\Tests\Fixtures\Factory\AuthorFactory;
use Prism\TestPack\Tests\Fixtures\Factory\BookFactory;
use Prism\TestPack\Tests\Support\PrismKernelTestCase;

/**
 * RG-07.6 sur les 4 bases : chaque test part d'une base vide, quel que soit l'ordre (aléatoire) ;
 * les flush() de l'application tournent dans la transaction du test ; #[WithRealTransactions]
 * commite vraiment, puis la base est remise à neuf.
 */
final class ResetDatabaseTest extends PrismKernelTestCase
{
    protected static function url(): string
    {
        return TestDatabase::url() ?? self::markTestSkipped('DATABASE_URL is not set.');
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        if (null === TestDatabase::url()) {
            return;
        }
        $connection = TestDatabase::connection();
        foreach (['tp_book', 'tp_author', 'tp_invoice'] as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $connection->getPlatform()->quoteIdentifier($table));
        }
        if ('PostgreSQL' === $connection->getPlatform()->getName()) {
            foreach (['tp_book_id_seq', 'tp_author_id_seq', 'tp_invoice_id_seq'] as $sequence) {
                $connection->executeStatement('DROP SEQUENCE IF EXISTS ' . $connection->getPlatform()->quoteIdentifier($sequence));
            }
        }
        $connection->close();
    }

    private static function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    // les trois tests qui écrivent commencent par vérifier qu'aucun autre ne leur a rien laissé
    private static function assertNoAuthor(): void
    {
        self::assertSame(0, self::em()->getRepository(Author::class)->count(), 'the database is empty at the start of each test');
    }

    public function testFirstWriter(): void
    {
        self::assertNoAuthor();
        AuthorFactory::new()->many(3);

        self::assertSame(3, self::em()->getRepository(Author::class)->count());
    }

    public function testSecondWriter(): void
    {
        self::assertNoAuthor();
        BookFactory::new()->many(2);

        self::assertSame(2, self::em()->getRepository(Author::class)->count());
    }

    // RG-01.1 : le flush transactionnel de l'application est un savepoint dans la transaction du test
    public function testApplicationTransactionsNestInsideTheTestOne(): void
    {
        self::assertNoAuthor();
        $em = self::em();
        self::assertTrue($em->getConnection()->isTransactionActive(), 'the test transaction');

        $em->wrapInTransaction(static function (EntityManagerInterface $em): void {
            $em->persist(new Author('Ada'));
        });

        self::assertSame(1, $em->getRepository(Author::class)->count());
        self::assertSame(1, $em->getConnection()->getTransactionNestingLevel(), 'back to the test transaction');
    }

    #[WithRealTransactions]
    public function testRealTransactionsAreCommitted(): void
    {
        self::assertNoAuthor();
        self::assertFalse(self::em()->getConnection()->isTransactionActive(), 'no wrapping transaction');
        AuthorFactory::new(['name' => 'Grace'])->create();

        // SQLite en mémoire : une autre connexion est une autre base
        if (':memory:' !== self::em()->getConnection()->getDatabase()) {
            $other = TestDatabase::connection();
            $count = $other->executeQuery('SELECT COUNT(*) FROM ' . $other->getPlatform()->quoteIdentifier('tp_author'))->fetchOne();
            $other->close();
            self::assertEquals(1, $count, 'committed: visible from another connection');
        }
    }
}
