<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Bundle\Exception\InvalidConfigurationException;
use Prism\Bundle\Messenger\ComputeEmbedding;
use Prism\Bundle\Messenger\ComputeEmbeddingHandler;
use Prism\Bundle\Tests\Fixtures\Embedding\KeywordProvider;
use Prism\Bundle\Tests\Fixtures\Entity\Docs\Note;
use Prism\Bundle\Tests\Support\TestKernel;
use Prism\Embedding\OpenAi\OpenAiCompatibleProvider;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Tenancy\TenantContext;
use Prism\Orm\Vector\EmbeddingProviderRegistry;
use Prism\Orm\Vector\EmbeddingUpdater;
use Prism\Orm\Vector\HashingEmbeddingProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Étape 8 de la phase 9 (ADR 0018) : prism.embeddings, fournisseurs par nom (configuration,
 * #[AsEmbeddingProvider], adaptateur OpenAI), scheduler selon la configuration, message
 * ComputeEmbedding traité par lots dans le tenant des lignes, commandes db:embeddings:*.
 * SQLite en mémoire.
 */
final class EmbeddingsTest extends TestCase
{
    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
            $kernel->removeDirectory();
        }
    }

    /**
     * @param array<string, mixed> $embeddings prism.embeddings
     * @param array<string, mixed> $framework  framework, en plus
     */
    private function container(array $embeddings, array $framework = [], int $dimensions = 8): ContainerInterface
    {
        $kernel = new TestKernel(
            ['url' => 'sqlite:///:memory:?serverVersion=3.46.0', 'entity_dirs' => ['%kernel.project_dir%/Fixtures/Entity/Docs'], 'embeddings' => $embeddings],
            services: static function (ContainerConfigurator $c) use ($framework, $dimensions): void {
                $c->extension('framework', ['http_client' => ['enabled' => true]] + $framework);
                $services = $c->services()->defaults()->autowire()->autoconfigure();
                $services->load('Prism\Bundle\Tests\Fixtures\Embedding\\', \dirname(__DIR__) . '/Fixtures/Embedding');
                $services->set('test.hashing', HashingEmbeddingProvider::class)->args([$dimensions])->autoconfigure(false);
            },
        );
        $this->kernels[] = $kernel;
        $kernel->boot();
        new CommandTester(new Application($kernel)->find('db:schema:create'))->execute([]);
        $container = $kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        return $container;
    }

    /**
     * @return array{EntityManager, TenantContext}
     */
    private static function services(ContainerInterface $container): array
    {
        $em = $container->get(EntityManagerInterface::class);
        $tenants = $container->get(TenantContext::class);
        \assert($em instanceof EntityManager && $tenants instanceof TenantContext);

        return [$em, $tenants];
    }

    /**
     * @return array{bool, ?string} périmé, modèle
     */
    private static function state(EntityManager $em, ?int $id): array
    {
        // SQL brut sur une table tenantée : en mode système, sinon la garde le refuse
        $row = $em->getTenantContext()->runAsSystem(static function () use ($em, $id): mixed {
            $qb = $em->getConnection()->createQueryBuilder();

            return $qb->select($qb->column('embedding_stale'), $qb->column('embedding_model'))->from('note')
                ->where($qb->expr()->eq($qb->column('id'), $id))->executeQuery()->fetchAssociative();
        });
        self::assertIsArray($row);

        return [true === $em->getConnection()->convertToPhp($row['embedding_stale'], 'boolean'), \is_string($row['embedding_model']) ? $row['embedding_model'] : null];
    }

    public function testProvidersByName(): void
    {
        $container = $this->container(['providers' => [
            'default' => ['service' => 'test.hashing'],
            'remote' => ['openai' => ['base_url' => 'http://ollama:11434/v1', 'model' => 'nomic-embed-text:v1.5', 'dimensions' => 768]],
        ]]);
        $registry = $container->get(EmbeddingProviderRegistry::class);
        \assert($registry instanceof EmbeddingProviderRegistry);

        self::assertEqualsCanonicalizing(['default', 'remote', 'keywords'], $registry->names());
        self::assertInstanceOf(HashingEmbeddingProvider::class, $registry->get('default'));
        self::assertInstanceOf(KeywordProvider::class, $registry->get('keywords'), '#[AsEmbeddingProvider]');
        self::assertInstanceOf(OpenAiCompatibleProvider::class, $remote = $registry->get('remote'));
        self::assertSame(['nomic-embed-text:v1.5', 768], [$remote->model(), $remote->dimensions()]);
        $commands = new Application($this->kernels[0]);
        self::assertTrue($commands->has('db:embeddings:compute') && $commands->has('db:embeddings:status'));
    }

    // ADR 0018 : le fournisseur d'un #[EmbeddedVector] est vérifié avec le mapping, au cache:warmup
    public function testProviderOfAnotherDimensionIsRefusedAtWarmup(): void
    {
        [$em] = self::services($this->container(['providers' => ['default' => ['service' => 'test.hashing']]], dimensions: 16));

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('the embedding provider "default" produces 16 dimensions, but the column has 8');
        $em->getMetadataFactory()->warmUp();
    }

    public function testSynchronousSchedulerComputesAfterTheFlush(): void
    {
        [$em, $tenants] = self::services($this->container(['scheduler' => 'synchronous', 'providers' => ['default' => ['service' => 'test.hashing']]]));
        $note = $tenants->runAs(5, static function () use ($em): Note {
            $em->persist($note = new Note('Partial payment of an invoice.'));
            $em->flush();

            return $note;
        });

        self::assertSame([false, 'prism/hashing-8'], self::state($em, $note->id));
    }

    // sans bus : « auto » laisse les lignes périmées à db:embeddings:compute
    public function testDeferredWithoutBusThenTheCommandComputes(): void
    {
        $container = $this->container(['providers' => ['default' => ['service' => 'test.hashing']]], ['messenger' => ['enabled' => false]]);
        [$em, $tenants] = self::services($container);
        $note = $tenants->runAs(5, static function () use ($em): Note {
            $em->persist($note = new Note('Stock levels.'));
            $em->flush();

            return $note;
        });
        self::assertSame([true, null], self::state($em, $note->id));

        $command = new CommandTester(new Application($this->kernels[0])->find('db:embeddings:compute'));
        self::assertSame(0, $command->execute([]));
        self::assertSame([false, 'prism/hashing-8'], self::state($em, $note->id));
    }

    // RG-09.7 : un message par lot après le commit ; le handler traite les messages par lots, dans le tenant des lignes
    public function testMessagesAreHandledInBatchesInTheTenantOfTheRows(): void
    {
        $container = $this->container(
            ['batch_size' => 2, 'providers' => ['default' => ['service' => 'test.hashing']]],
            ['messenger' => ['transports' => ['async' => 'in-memory://'], 'routing' => [ComputeEmbedding::class => 'async']]],
        );
        [$em, $tenants] = self::services($container);
        $notes = $tenants->runAs(5, static function () use ($em): array {
            $notes = [new Note('One.'), new Note('Two.'), new Note('Three.')];
            foreach ($notes as $note) {
                $em->persist($note);
            }
            $em->flush();

            return $notes;
        });
        $transport = $container->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $messages = array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent());

        self::assertCount(2, $messages, '3 ids by batches of 2');
        self::assertInstanceOf(ComputeEmbedding::class, $messages[0]);
        self::assertSame(['default', Note::class, 'embedding', 5], [$messages[0]->entityManager, $messages[0]->className, $messages[0]->field, $messages[0]->tenant]);
        self::assertSame([true, null], self::state($em, $notes[0]->id), 'nothing computed before the worker');

        $handler = $container->get('prism.embeddings.compute_handler');
        \assert($handler instanceof ComputeEmbeddingHandler);
        $acks = [];
        foreach ($messages as $message) {
            \assert($message instanceof ComputeEmbedding);
            $handler($message, $acks[] = new Acknowledger(ComputeEmbeddingHandler::class));
        }
        foreach ($acks as $ack) {
            self::assertTrue($ack->isAcknowledged());
            self::assertNull($ack->getError());
        }
        self::assertNull($tenants->getTenantId(), 'the tenant of the rows only while computing');
        foreach ($notes as $note) {
            self::assertSame([false, 'prism/hashing-8'], self::state($em, $note->id));
        }

        // critère de sortie de la phase 9 : contenu modifié → périmé → recalcul asynchrone → à jour
        $before = self::vector($em, $notes[0]->id);
        // sortir du tenant a vidé l'identity map (RG-08.8) : la note est relue dans le sien
        $tenants->runAs(5, static function () use ($em, $notes): void {
            $note = $em->find(Note::class, $notes[0]->id);
            \assert($note instanceof Note);
            $note->text = 'One, rewritten.';
            $em->flush();
        });
        self::assertSame([true, 'prism/hashing-8'], self::state($em, $notes[0]->id));
        $sent = $transport->getSent();
        $message = $sent[array_key_last($sent)]->getMessage();
        \assert($message instanceof ComputeEmbedding);
        self::assertSame([$notes[0]->id], $message->ids);
        $handler($message, $ack = new Acknowledger(ComputeEmbeddingHandler::class));
        $handler->flush(true);
        self::assertSame([false, 'prism/hashing-8'], self::state($em, $notes[0]->id));
        self::assertNotSame($before, self::vector($em, $notes[0]->id), 'the vector follows the new text');
    }

    private static function vector(EntityManager $em, ?int $id): mixed
    {
        return $em->getTenantContext()->runAsSystem(static function () use ($em, $id): mixed {
            $qb = $em->getConnection()->createQueryBuilder();

            return $qb->select($qb->column('embedding'))->from('note')->where($qb->expr()->eq($qb->column('id'), $id))->executeQuery()->fetchOne();
        });
    }

    // une erreur du fournisseur rejette le lot : Messenger réessaie, les lignes restent périmées
    public function testProviderFailureNacksTheBatch(): void
    {
        $container = $this->container(['scheduler' => 'deferred', 'providers' => ['default' => ['service' => 'test.hashing']]]);
        [$em, $tenants] = self::services($container);
        $note = $tenants->runAs(5, static function () use ($em): Note {
            $em->persist($note = new Note('Payments.'));
            $em->flush();

            return $note;
        });
        $handler = new ComputeEmbeddingHandler(new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('provider down');
            }

            public function has(string $id): bool
            {
                return true;
            }
        }, $tenants);
        $handler(new ComputeEmbedding('default', Note::class, 'embedding', [(int) $note->id], 5), $ack = new Acknowledger(ComputeEmbeddingHandler::class));
        $handler->flush(true);

        self::assertSame('provider down', $ack->getError()?->getMessage());
        self::assertSame([true, null], self::state($em, $note->id));
    }

    public function testMessengerSchedulerNeedsABus(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('prism.embeddings.scheduler is "messenger", but there is no message bus');
        $this->container(['scheduler' => 'messenger'], ['messenger' => ['enabled' => false]]);
    }

    public function testProviderIsEitherAServiceOrOpenAi(): void
    {
        $this->expectExceptionMessage('An embedding provider is either "service" or "openai".');
        $this->container(['providers' => ['default' => []]]);
    }

    // l'updater de chaque EntityManager partage ses filtres : celui du tenant compris
    public function testUpdaterSeesOnlyTheCurrentTenant(): void
    {
        $container = $this->container(['scheduler' => 'deferred', 'providers' => ['default' => ['service' => 'test.hashing']]]);
        [$em, $tenants] = self::services($container);
        $note = $tenants->runAs(5, static function () use ($em): Note {
            $em->persist($note = new Note('Invoices.'));
            $em->flush();

            return $note;
        });
        $updater = $container->get('prism.embedding_updater.default');
        \assert($updater instanceof EmbeddingUpdater);
        $ids = [(int) $note->id];

        self::assertSame(0, $tenants->runAs(9, static fn (): int => $updater->compute(Note::class, 'embedding', $ids)));
        self::assertSame(1, $tenants->runAs(5, static fn (): int => $updater->compute(Note::class, 'embedding', $ids)));
        self::assertInstanceOf(MessageBusInterface::class, $container->get('messenger.default_bus'));
    }
}
