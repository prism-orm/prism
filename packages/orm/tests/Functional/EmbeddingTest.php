<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Orm\Console\Command\EmbeddingsComputeCommand;
use Prism\Orm\Console\Command\EmbeddingsStatusCommand;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Exception\EmbeddingException;
use Prism\Orm\Filter\FilterCollection;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tenancy\StackTenantContext;
use Prism\Orm\Tests\Functional\VectorFixtures\Page;
use Prism\Orm\Tests\Functional\VectorFixtures\TenantPage;
use Prism\Orm\Vector\EmbeddingProvider;
use Prism\Orm\Vector\EmbeddingProviderRegistry;
use Prism\Orm\Vector\EmbeddingScheduler;
use Prism\Orm\Vector\EmbeddingUpdater;
use Prism\Orm\Vector\HashingEmbeddingProvider;
use Prism\Orm\Vector\HybridOptions;
use Prism\Orm\Vector\StaleEmbeddings;
use Prism\Orm\Vector\SynchronousEmbeddingScheduler;
use Prism\Orm\Vector\Vector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Cycle de vie d'un vecteur #[EmbeddedVector] sur MariaDB, PostgreSQL et SQLite (RG-09.7 à
 * RG-09.10, ADR 0018) : périmé à l'INSERT et quand une source change, planifié après le commit
 * seulement, recalculé par l'EmbeddingUpdater sans écraser une modification concurrente, exclu
 * de nearest() tant qu'il est périmé.
 */
final class EmbeddingTest extends MappingSchemaTestCase
{
    private MetadataFactory $metadataFactory;

    private HashingEmbeddingProvider $provider;

    /** @var \ArrayObject<int, array{StaleEmbeddings, bool}> lots reçus, et si une transaction était ouverte */
    private \ArrayObject $scheduled;

    protected static function entities(): array
    {
        return [Page::class, TenantPage::class];
    }

    protected function setUp(): void
    {
        if (null !== TestDatabase::url() && !TestDatabase::connection()->getPlatform()->supportsVectors()) {
            self::markTestSkipped('MySQL has no vector type (ADR 0017).');
        }
        parent::setUp();
        $this->metadataFactory = new MetadataFactory(new AttributeDriver([__DIR__ . '/VectorFixtures']), types: $this->connection->getTypeRegistry());
        $this->provider = new HashingEmbeddingProvider(16);
        $this->scheduled = new \ArrayObject();
    }

    private function updater(?EmbeddingProvider $provider = null, FilterCollection $filters = new FilterCollection()): EmbeddingUpdater
    {
        return new EmbeddingUpdater($this->connection, $this->metadataFactory, new EmbeddingProviderRegistry(['default' => $provider ?? $this->provider]), $filters);
    }

    // un scheduler qui note ce qu'il reçoit ; ou le synchrone, qui partage les filtres de l'EntityManager
    private function em(bool $synchronous = false, ?StackTenantContext $tenants = null): EntityManager
    {
        $filters = new FilterCollection();
        $scheduler = $synchronous ? new SynchronousEmbeddingScheduler($this->updater(filters: $filters)) : new class($this) implements EmbeddingScheduler {
            public function __construct(private readonly EmbeddingTest $test)
            {
            }

            public function schedule(StaleEmbeddings $stale): void
            {
                $this->test->record($stale);
            }
        };

        return new EntityManager($this->connection, $this->metadataFactory, tenantContext: $tenants, filters: $filters, embeddingScheduler: $scheduler);
    }

    /**
     * @internal
     */
    public function record(StaleEmbeddings $stale): void
    {
        $this->scheduled[] = [$stale, $this->connection->isTransactionActive()];
    }

    /**
     * @return array{StaleEmbeddings, bool} le premier lot reçu
     */
    private function firstScheduled(): array
    {
        return $this->scheduled[0] ?? self::fail('Nothing was scheduled.');
    }

    /**
     * @return array{bool, ?string, bool} périmé, modèle, vecteur présent
     */
    private function state(?int $id): array
    {
        $qb = $this->connection->createQueryBuilder();
        $row = $qb->select($qb->column('embedding_stale'), $qb->column('embedding_model'), $qb->column('embedding'))->from('page')
            ->where($qb->expr()->eq($qb->column('id'), $id))->executeQuery()->fetchAssociative();
        self::assertIsArray($row);
        $model = $row['embedding_model'];

        return [true === $this->connection->convertToPhp($row['embedding_stale'], 'boolean'), \is_string($model) ? $model : null, null !== $row['embedding']];
    }

    /**
     * @return list<float>
     */
    private function embedding(string $title, string $content): array
    {
        return $this->provider->embed([$title . "\n\n" . $content])[0];
    }

    // l'aller-retour RG-01.8 avec les deux colonnes d'état (booléen à défaut vrai)
    public function testStateColumnsRoundTrip(): void
    {
        self::assertTrue(new Comparator($this->connection->getPlatform())->compare($this->actual(), $this->target())->isEmpty());
        self::assertTrue($this->actual()->getTable('page')->getColumn('embedding_stale')->default);
    }

    // RG-09.7 : une ligne neuve est périmée, planifiée une fois la transaction validée
    public function testNewRowIsStaleAndScheduledAfterTheCommit(): void
    {
        $em = $this->em();
        $em->persist($a = new Page('Invoices', 'Number invoices per company.'));
        $em->persist($b = new Page('Payments', 'Record a partial payment.'));
        $em->flush();

        self::assertCount(1, $this->scheduled);
        [$stale, $inTransaction] = $this->firstScheduled();
        self::assertFalse($inTransaction);
        self::assertSame([Page::class, 'embedding', null], [$stale->className, $stale->field, $stale->tenant]);
        self::assertEqualsCanonicalizing([$a->id, $b->id], array_map(intval(...), $stale->ids));
        self::assertSame([true, null, false], $this->state($a->id));
    }

    // un vecteur fourni par l'application est gardé, à jour, sans modèle
    public function testVectorGivenByTheApplicationIsKept(): void
    {
        $em = $this->em();
        $em->persist($page = new Page('Invoices', 'Number invoices.'));
        $page->embedding = new Vector($this->embedding('Invoices', 'Number invoices.'));
        $em->flush();

        self::assertCount(0, $this->scheduled);
        self::assertSame([false, null, true], $this->state($page->id));
    }

    // RG-09.7 : seule une source rend le vecteur périmé ; il n'est pas touché
    public function testOnlyASourceChangeMakesTheVectorStale(): void
    {
        $em = $this->em();
        $em->persist($page = new Page('Invoices', 'Number invoices.'));
        $em->flush();
        self::assertSame(1, $this->updater()->compute(Page::class, 'embedding', [(int) $page->id]));
        $this->scheduled->exchangeArray([]);

        $page->section = 'reference';
        $em->flush();
        self::assertCount(0, $this->scheduled);
        self::assertSame([false, 'prism/hashing-16', true], $this->state($page->id));

        $page->title = 'Invoice numbering';
        $em->flush();
        self::assertCount(1, $this->scheduled);
        self::assertSame([true, 'prism/hashing-16', true], $this->state($page->id), 'the old vector stays until it is computed');
    }

    // un flush annulé ne planifie rien ; dans une transaction, la planification attend sa fin
    public function testNothingIsScheduledBeforeTheOuterCommitNorAfterARollback(): void
    {
        $em = $this->em();
        try {
            $em->wrapInTransaction(static function (EntityManagerInterface $em): void {
                $em->persist(new Page('Lost', 'Rolled back.'));
                $em->flush();

                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException) {
        }
        self::assertCount(0, $this->scheduled);

        $em->wrapInTransaction(static function (EntityManagerInterface $em): void {
            $em->persist(new Page('Kept', 'Committed.'));
            $em->flush();
        });
        self::assertCount(1, $this->scheduled);
        self::assertFalse($this->firstScheduled()[1], 'scheduled once the transaction is committed');
    }

    public function testSynchronousSchedulerComputesAfterTheFlush(): void
    {
        $em = $this->em(synchronous: true);
        $em->persist($page = new Page('Partial payment', 'Record the partial payment of an invoice.'));
        $em->persist(new Page('Stock levels', 'Count the products in stock.'));
        $em->flush();

        self::assertSame([false, 'prism/hashing-16', true], $this->state($page->id));
        $hits = $em->getRepository(Page::class)->nearest('embedding', $this->embedding('Partial payment', 'Record the partial payment of an invoice.'), limit: 1);
        self::assertSame($page->id, $hits[0]->entity->id);
        self::assertEqualsWithDelta(0.0, $hits[0]->distance, 1e-5);
    }

    // RG-09.8 : un vecteur périmé est exclu de nearest() et de la liste vectorielle de hybridSearch()
    public function testStaleVectorsAreLeftOutOfTheVectorSearch(): void
    {
        $em = $this->em(synchronous: true);
        $em->persist($payment = new Page('Partial payment', 'Record the partial payment of an invoice.'));
        $em->persist($stock = new Page('Stock levels', 'Count the products in stock.'));
        $em->flush();
        $em->clear();

        // modifiée hors de tout scheduler : reste périmée
        $deferred = $this->em();
        $page = $deferred->find(Page::class, $payment->id);
        self::assertNotNull($page);
        $page->content = 'Record the partial payment of an invoice, then send a receipt.';
        $deferred->flush();
        $deferred->clear();

        $query = $this->embedding('Partial payment', 'Record the partial payment of an invoice.');
        $pages = $deferred->getRepository(Page::class);
        self::assertSame([$stock->id], array_map(static fn ($hit): ?int => $hit->entity->id, $pages->nearest('embedding', $query)));
        self::assertSame([$payment->id, $stock->id], array_map(static fn ($hit): ?int => $hit->entity->id, $pages->nearest('embedding', $query, includeStale: true)));

        // le plein texte trouve toujours la page : un document périmé reste trouvable par ses mots
        $hybrid = $pages->hybridSearch('partial payment', $query, limit: 2);
        self::assertSame([$payment->id, 1, null], [$hybrid[0]->entity->id, $hybrid[0]->textRank, $hybrid[0]->vectorRank]);
        $hybrid = $pages->hybridSearch('partial payment', $query, limit: 2, options: new HybridOptions(includeStale: true));
        self::assertSame([$payment->id, 1, 1], [$hybrid[0]->entity->id, $hybrid[0]->textRank, $hybrid[0]->vectorRank]);
    }

    // RG-09.11 : le lot porte le tenant des lignes ; l'updater de l'EntityManager ne voit que le sien
    public function testTenantOfTheRowsIsCarriedAndFiltered(): void
    {
        $tenants = new StackTenantContext(7);
        $em = $this->em(tenants: $tenants);
        $em->persist($page = new TenantPage('Invoices of tenant 7.'));
        $em->flush();

        self::assertCount(1, $this->scheduled);
        self::assertSame([TenantPage::class, 7], [$this->firstScheduled()[0]->className, (int) $this->firstScheduled()[0]->tenant]);

        $filters = new FilterCollection();
        new EntityManager($this->connection, $this->metadataFactory, tenantContext: $tenants, filters: $filters);
        $updater = $this->updater(filters: $filters);
        $ids = [(int) $page->id];
        self::assertSame(0, $tenants->runAs(9, static fn (): int => $updater->compute(TenantPage::class, 'embedding', $ids)), 'another tenant does not see the row');
        self::assertSame(1, $updater->compute(TenantPage::class, 'embedding', $ids));
    }

    // ADR 0018 : une source modifiée pendant le calcul n'est pas écrasée ; la ligne reste périmée
    public function testConcurrentChangeKeepsTheRowStale(): void
    {
        $em = $this->em();
        $em->persist($page = new Page('Invoices', 'Number invoices.'));
        $em->flush();
        $connection = $this->connection;
        $provider = new class($this->provider, static function () use ($connection, $page): void {
            $qb = $connection->createQueryBuilder();
            $qb->update('page')->set('content', 'Changed meanwhile.')->where($qb->expr()->eq($qb->column('id'), $page->id))->executeStatement();
        }) implements EmbeddingProvider {
            public function __construct(private readonly EmbeddingProvider $inner, private readonly \Closure $meanwhile)
            {
            }

            public function model(): string
            {
                return $this->inner->model();
            }

            public function dimensions(): int
            {
                return $this->inner->dimensions();
            }

            public function embed(array $texts): array
            {
                ($this->meanwhile)();

                return $this->inner->embed($texts);
            }
        };

        self::assertSame(0, $this->updater($provider)->compute(Page::class, 'embedding', [(int) $page->id]));
        self::assertSame([true, null, false], $this->state($page->id));
    }

    // une erreur du fournisseur remonte ; les lignes restent périmées
    public function testProviderFailureLeavesTheRowsStale(): void
    {
        $em = $this->em();
        $em->persist($page = new Page('Invoices', 'Number invoices.'));
        $em->flush();
        $failing = new class implements EmbeddingProvider {
            public function model(): string
            {
                return 'test/failing';
            }

            public function dimensions(): int
            {
                return 16;
            }

            public function embed(array $texts): array
            {
                throw new \RuntimeException('quota exceeded');
            }
        };

        try {
            $this->updater($failing)->compute(Page::class, 'embedding', [(int) $page->id]);
            self::fail('EmbeddingException expected.');
        } catch (EmbeddingException $e) {
            self::assertSame('The embedding provider "test/failing" failed: quota exceeded', $e->getMessage());
        }
        self::assertSame([true, null, false], $this->state($page->id));

        $this->expectException(EmbeddingException::class);
        $this->expectExceptionMessage('The embedding provider "prism/hashing-8" returned an unusable response: ' . Page::class . '::$embedding expects a vector of 16 dimensions, got 8');
        $this->updater(new HashingEmbeddingProvider(8))->compute(Page::class, 'embedding', [(int) $page->id]);
    }

    // RG-09.10 : changer de modèle, c'est tout marquer périmé puis recalculer
    public function testMarkStaleComputeAllAndStatus(): void
    {
        $em = $this->em(synchronous: true);
        foreach (['Invoices', 'Payments', 'Stock'] as $title) {
            $em->persist(new Page($title, $title . ' guide.'));
        }
        $em->flush();
        $updater = $this->updater();
        $status = $updater->status(Page::class, 'embedding');
        self::assertSame([3, 0, ['prism/hashing-16' => 3]], [$status->fresh, $status->stale, $status->models]);

        self::assertSame(3, $em->getRepository(Page::class)->markEmbeddingsStale('embedding'));
        $status = $updater->status(Page::class, 'embedding');
        self::assertSame([0, 3], [$status->fresh, $status->stale]);

        $progress = [];
        self::assertSame(3, new EmbeddingUpdater($this->connection, $this->metadataFactory, new EmbeddingProviderRegistry(['default' => $this->provider]), batchSize: 2)->computeAll(Page::class, 'embedding', progress: static function (int $rows) use (&$progress): void {
            $progress[] = $rows;
        }));
        self::assertSame([2, 1], $progress);
        self::assertSame(0, $updater->computeAll(Page::class, 'embedding'), 'nothing left stale');
        self::assertSame(3, $updater->computeAll(Page::class, 'embedding', all: true));
    }

    public function testCommands(): void
    {
        $em = $this->em();
        $em->persist(new Page('Invoices', 'Number invoices.'));
        $em->persist(new Page('Payments', 'Record a payment.'));
        $em->flush();

        $status = new CommandTester(new EmbeddingsStatusCommand($this->connection, $this->metadataFactory));
        self::assertSame(Command::SUCCESS, $status->execute(['--class' => 'Page']));
        self::assertMatchesRegularExpression('/Page::\$embedding\s+0\s+2\s+\(none\): 2/', $status->getDisplay());

        $compute = new CommandTester(new EmbeddingsComputeCommand($this->connection, $this->metadataFactory, new EmbeddingProviderRegistry(['default' => $this->provider])));
        self::assertSame(Command::SUCCESS, $compute->execute(['--class' => Page::class, '--batch-size' => '1']));
        self::assertStringContainsString('2 vector(s) computed.', $compute->getDisplay());

        self::assertSame(Command::SUCCESS, $status->execute([]));
        self::assertMatchesRegularExpression('/Page::\$embedding\s+2\s+0\s+prism\/hashing-16: 2/', $status->getDisplay());
        self::assertSame(Command::INVALID, $compute->execute(['--class' => 'Unknown']));
        self::assertSame(Command::INVALID, $compute->execute(['--batch-size' => '0']));

        // sans fournisseur enregistré : échec, lignes inchangées
        $em->getRepository(Page::class)->markEmbeddingsStale('embedding');
        $bare = new CommandTester(new EmbeddingsComputeCommand($this->connection, $this->metadataFactory, new EmbeddingProviderRegistry()));
        self::assertSame(Command::FAILURE, $bare->execute([]));
        self::assertStringContainsString('Unknown embedding provider "default"', $bare->getDisplay());
    }
}
