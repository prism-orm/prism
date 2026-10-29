<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Configuration;
use Prism\Dbal\Query\Order;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Dbal\Tests\Support\TestLogger;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Persister\BasicEntityPersister;
use Prism\Orm\Persister\DbalPersisterProvider;
use Prism\Orm\Tests\Functional\PersisterFixtures\ReservedOrder;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\FixtureStatus;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Article;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Document;

final class EntityPersisterTest extends MappingSchemaTestCase
{
    private TestLogger $logger;

    private DbalPersisterProvider $provider;

    protected static function entities(): array
    {
        return [Article::class, Document::class, ReservedOrder::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new TestLogger();
        // même base, journal des requêtes : une seconde connexion n'existe pas pour SQLite en mémoire
        $this->provider = new DbalPersisterProvider($this->connection);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     */
    private function persister(string $class): BasicEntityPersister
    {
        foreach ($this->metadata() as $metadata) {
            if ($metadata->className === $class) {
                return $this->provider->getPersister($metadata);
            }
        }
        self::fail(\sprintf('%s is not mapped in this test.', $class));
    }

    /**
     * @return array<string, mixed> valeurs de la base pour un article
     */
    private function articleRow(string $title, string $price = '12.50', string $status = 'draft'): array
    {
        return ['title' => $title, 'price' => $price, 'published_at' => '2026-10-18 09:00:00', 'status' => $status, 'place_street' => null, 'place_zip' => null, 'place_city' => null, 'created_by' => 'alice', 'computed' => null, 'slug' => strtolower($title)];
    }

    private function withLogger(): void
    {
        $this->connection->close();
        $this->connection = TestDatabase::connection(new Configuration($this->logger));
        $this->provider = new DbalPersisterProvider($this->connection);
        if (':memory:' === $this->connection->getDatabase()) {
            $this->migrateTo($this->target());
        }
    }

    public function testInsertReturnsTheIdentityAndTheRowIsReadBack(): void
    {
        $persister = $this->persister(Article::class);

        self::assertSame('1', $persister->insert($this->articleRow('First')));
        self::assertSame('2', $persister->insert(['id' => null] + $this->articleRow('Second')), 'a NULL identifier is left to the database');

        $row = $persister->loadById(2);
        self::assertIsArray($row);
        self::assertSame('Second', $row['title']);
        // SQLite stocke un NUMERIC en flottant et perd l'échelle (divergence documentée en phase 1)
        self::assertSame('SQLite' === $this->connection->getPlatform()->getName() ? '12.5' : '12.50', $this->connection->convertToPhp($row['price'], 'decimal'));
        self::assertNull($persister->loadById(404));
    }

    public function testUpdateWritesOnlyTheGivenColumns(): void
    {
        // une nouvelle connexion SQLite en mémoire est une base vide : on la journalise avant d'insérer
        $this->withLogger();
        $persister = $this->persister(Article::class);
        $persister->insert($this->articleRow('Before'));

        self::assertSame(1, $persister->update(1, ['title' => 'After']));
        $update = array_values(array_filter($this->logger->sqlStatements(), static fn (string $s): bool => str_starts_with($s, 'UPDATE')));
        self::assertCount(1, $update);
        self::assertSame(\sprintf('UPDATE %s SET %s = :prism_1 WHERE %s = :prism_2', $this->q('article'), $this->q('title'), $this->q('id')), $update[0], 'RG-03.5');
        self::assertSame('After', $persister->loadById(1)['title'] ?? null);
        self::assertSame('alice', $persister->loadById(1)['created_by'] ?? null);
        self::assertSame(0, $persister->update(1, []), 'nothing to write');
    }

    // RG-03.9
    public function testVersionConditionsTheUpdateAndTheDelete(): void
    {
        $persister = $this->persister(Document::class);
        $persister->insert(['title' => 'v1', 'version' => 1]);

        self::assertSame(0, $persister->update(1, ['title' => 'v2', 'version' => 2], ['version', 7]), 'stale version: no row touched');
        self::assertSame('v1', $persister->loadById(1)['title'] ?? null);
        self::assertSame(1, $persister->update(1, ['title' => 'v2', 'version' => 2], ['version', 1]));

        self::assertSame(0, $persister->delete(1, ['version', 1]));
        self::assertSame(1, $persister->delete(1, ['version', 2]));
        self::assertNull($persister->loadById(1));
    }

    public function testDelete(): void
    {
        $persister = $this->persister(Article::class);
        $persister->insert($this->articleRow('Gone'));

        self::assertSame(1, $persister->delete(1));
        self::assertSame(0, $persister->delete(1));
    }

    public function testLoadByCriteriaOrderLimitAndOffset(): void
    {
        $persister = $this->persister(Article::class);
        foreach ([['A', '10.00', 'draft'], ['B', '20.00', 'paid'], ['C', '30.00', 'paid'], ['D', '40.00', 'paid']] as [$title, $price, $status]) {
            $persister->insert($this->articleRow($title, $price, $status));
        }

        $titles = static fn (iterable $rows): array => array_column(iterator_to_array($rows, false), 'title');

        self::assertSame(['D', 'C', 'B'], $titles($persister->loadBy(['status' => FixtureStatus::Paid], ['title' => Order::Desc])));
        self::assertSame(['C'], $titles($persister->loadBy(['status' => 'paid'], ['title' => Order::Asc], 1, 1)));
        self::assertSame(['A', 'C'], $titles($persister->loadBy(['title' => ['A', 'C']], ['title' => Order::Asc])));
        self::assertSame(['A', 'B', 'C', 'D'], $titles($persister->loadBy(['place.city' => null], ['price' => Order::Asc])), 'embedded field path and IS NULL');
        self::assertSame(3, $persister->count(['status' => FixtureStatus::Paid]));
        self::assertSame(4, $persister->count());
        self::assertSame(0, $persister->count(['title' => []]), 'an empty list matches nothing');
    }

    public function testUnknownFieldInCriteria(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(Article::class . ' has no mapped field "author".');

        $this->persister(Article::class)->loadBy(['author' => 'x']);
    }

    // RG-01.3 et liaison binaire (BYTEA sous PostgreSQL)
    public function testReservedNamesAndBinaryValues(): void
    {
        $persister = $this->persister(ReservedOrder::class);
        $payload = "\x00\x01\xFE\xFFbytes\x00";

        $persister->insert(['group' => 'vip', 'payload' => $payload]);
        $persister->update(1, ['group' => 'gold']);

        $row = iterator_to_array($persister->loadBy(['group' => 'gold']), false)[0] ?? [];
        self::assertSame('gold', $row['group'] ?? null);
        self::assertSame($payload, $this->connection->convertToPhp($row['payload'] ?? null, 'binary'));
    }

    // RG-03.6 : la transaction du flush passe par Connection::transactional()
    public function testTransactionalRollsBack(): void
    {
        $persister = $this->persister(Article::class);

        $failed = false;
        try {
            $this->provider->transactional(function () use ($persister): void {
                $persister->insert($this->articleRow('Rolled back'));
                $this->failIfInserted($persister);
            });
        } catch (\DomainException) {
            $failed = true;
        }

        self::assertTrue($failed);
        self::assertSame(0, $persister->count());
        $id = $this->provider->transactional(fn (): ?string => $persister->insert($this->articleRow('Kept')));
        self::assertNotNull($id, 'the value of the operations is returned');
        self::assertSame(1, $persister->count(), 'a successful transaction is committed');
    }

    // hors de la closure : l'échec n'est pas connu de l'analyse statique
    private function failIfInserted(BasicEntityPersister $persister): void
    {
        if ($persister->count() > 0) {
            throw new \DomainException('failure');
        }
    }

    private function q(string $name): string
    {
        return $this->connection->getPlatform()->quoteIdentifier($name);
    }
}
