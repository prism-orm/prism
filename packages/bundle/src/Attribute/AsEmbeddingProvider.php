<?php

declare(strict_types=1);

namespace Prism\Bundle\Attribute;

/**
 * Enregistre un EmbeddingProvider sous un nom, celui que donne #[EmbeddedVector(model:)]
 * (ADR 0018). Sans cet attribut, un EmbeddingProvider autoconfiguré l'est sous l'id de son service.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsEmbeddingProvider
{
    public function __construct(public string $name = 'default')
    {
    }
}
