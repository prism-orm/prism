<?php

declare(strict_types=1);

namespace Prism\Orm\Vector;

use Prism\Orm\Exception\EmbeddingException;

/**
 * Calcule les vecteurs périmés tout de suite, après le commit, dans le même processus : pour les
 * scripts et les petites applications sans file de messages (ADR 0018). Une erreur du fournisseur
 * remonte du flush, déjà validé : les lignes restent périmées.
 *
 * @api
 */
final readonly class SynchronousEmbeddingScheduler implements EmbeddingScheduler
{
    public function __construct(private EmbeddingUpdater $updater)
    {
    }

    /**
     * @throws EmbeddingException
     */
    public function schedule(StaleEmbeddings $stale): void
    {
        $this->updater->compute($stale->className, $stale->field, $stale->ids);
    }
}
