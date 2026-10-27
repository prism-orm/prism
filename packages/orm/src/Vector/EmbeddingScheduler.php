<?php

declare(strict_types=1);

namespace Prism\Orm\Vector;

/**
 * Reçoit les vecteurs devenus périmés, après le commit du flush, jamais pendant la transaction
 * (RG-09.7, ADR 0018) : un flush annulé ne planifie rien. Implémentations : DeferredEmbeddingScheduler
 * (défaut : db:embeddings:compute s'en charge), SynchronousEmbeddingScheduler, et celle du bundle
 * qui publie ComputeEmbedding.
 *
 * @api
 */
interface EmbeddingScheduler
{
    public function schedule(StaleEmbeddings $stale): void;
}
