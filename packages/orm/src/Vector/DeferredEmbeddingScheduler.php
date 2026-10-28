<?php

declare(strict_types=1);

namespace Prism\Orm\Vector;

/**
 * Le défaut du cœur : ne fait rien. Les lignes restent périmées, exclues de nearest(), jusqu'à
 * db:embeddings:compute (ADR 0018).
 *
 * @api
 */
final class DeferredEmbeddingScheduler implements EmbeddingScheduler
{
    public function schedule(StaleEmbeddings $stale): void
    {
    }
}
