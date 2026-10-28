<?php

declare(strict_types=1);

namespace Prism\Bundle\Messenger;

use Prism\Orm\Vector\EmbeddingScheduler;
use Prism\Orm\Vector\StaleEmbeddings;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Publie un message ComputeEmbedding par lot d'identifiants (ADR 0018). L'UnitOfWork n'appelle
 * le scheduler qu'après le commit : un message ne désigne jamais une ligne annulée.
 *
 * @internal
 */
final readonly class MessengerEmbeddingScheduler implements EmbeddingScheduler
{
    /**
     * @param int<1, max> $batchSize
     */
    public function __construct(
        private MessageBusInterface $bus,
        private string $entityManager,
        private int $batchSize = 64,
    ) {
    }

    public function schedule(StaleEmbeddings $stale): void
    {
        foreach (array_chunk($stale->ids, $this->batchSize) as $ids) {
            $this->bus->dispatch(new ComputeEmbedding($this->entityManager, $stale->className, $stale->field, $ids, $stale->tenant));
        }
    }
}
