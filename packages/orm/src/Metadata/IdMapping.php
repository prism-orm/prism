<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata;

use Prism\Orm\Mapping\GenerationStrategy;

/**
 * Identifiant unique de l'entité (pas de clé composite en 1.0, RG-02.4). La stratégie Auto est
 * résolue par plateforme au moment de produire le schéma.
 *
 * @api
 */
final class IdMapping
{
    /**
     * @internal construite par Prism (AttributeDriver, MetadataValidator) ; lisible, pas à construire (ADR 0020)
     */
    public function __construct(
        public readonly string $fieldName,
        public readonly ?GenerationStrategy $strategy = null,
        public readonly ?string $sequenceName = null,
    ) {
    }

    public function isGenerated(): bool
    {
        return null !== $this->strategy;
    }
}
