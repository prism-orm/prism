<?php

declare(strict_types=1);

namespace Prism\Bundle\Messenger;

use Prism\Orm\Tenancy\TenantContext;
use Prism\Orm\Vector\EmbeddingUpdater;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;

/**
 * Traite les ComputeEmbedding par lots de messages (ADR 0018) : les messages d'un même
 * EntityManager, d'une même entité, d'un même champ et d'un même tenant forment un seul appel à
 * l'EmbeddingUpdater, qui ne recalcule que les lignes encore périmées. Chaque groupe travaille
 * dans le tenant de ses lignes, ou en mode système pour une entité sans tenant. Un groupe en
 * échec est rejeté (nack) : Messenger le réessaie, les lignes restent périmées.
 *
 * @internal
 */
final class ComputeEmbeddingHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    /**
     * @param ContainerInterface $updaters  EmbeddingUpdater par nom d'EntityManager
     * @param int<1, max>        $batchSize messages par lot
     */
    public function __construct(
        private readonly ContainerInterface $updaters,
        private readonly TenantContext $tenants,
        private readonly int $batchSize = 64,
    ) {
    }

    public function __invoke(ComputeEmbedding $message, ?Acknowledger $ack = null): mixed
    {
        return $this->handle($message, $ack);
    }

    /**
     * @param list<array{0: object, 1: Acknowledger}> $jobs
     */
    private function process(array $jobs): void
    {
        /** @var array<string, list<array{ComputeEmbedding, Acknowledger}>> $groups */
        $groups = [];
        foreach ($jobs as [$message, $ack]) {
            \assert($message instanceof ComputeEmbedding);
            $groups[implode("\0", [$message->entityManager, $message->className, $message->field, (string) $message->tenant])][] = [$message, $ack];
        }
        foreach ($groups as $group) {
            $first = $group[0][0];
            $ids = array_values(array_unique(array_merge(...array_map(static fn (array $job): array => $job[0]->ids, $group))));
            try {
                $updater = $this->updaters->get($first->entityManager);
                \assert($updater instanceof EmbeddingUpdater);
                $compute = static fn (): int => $updater->compute($first->className, $first->field, $ids);
                $written = null === $first->tenant ? $this->tenants->runAsSystem($compute) : $this->tenants->runAs($first->tenant, $compute);
            } catch (\Throwable $e) {
                foreach ($group as [, $ack]) {
                    $ack->nack($e);
                }
                continue;
            }
            foreach ($group as [, $ack]) {
                $ack->ack($written);
            }
        }
    }

    private function getBatchSize(): int
    {
        return $this->batchSize;
    }
}
