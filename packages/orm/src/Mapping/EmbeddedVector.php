<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Recalcule automatiquement l'embedding quand un champ source change (pilier 4, RG-09.7).
 *
 * Ajoute les colonnes <champ>_stale et <champ>_model ; publie ComputeEmbedding après le commit.
 * model désigne un EmbeddingProvider configuré (« default » par défaut).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class EmbeddedVector implements MappingAttribute
{
    /** @var list<string> */
    public readonly array $source;

    public readonly string $model;

    /**
     * @param string|list<string> $source
     */
    public function __construct(string|array $source, string $model = 'default')
    {
        $this->source = AttributeGuard::fieldList('EmbeddedVector', 'source', \is_string($source) ? [$source] : $source);
        $this->model = (string) AttributeGuard::name('EmbeddedVector', 'model', $model);
    }
}
