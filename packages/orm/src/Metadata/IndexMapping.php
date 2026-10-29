<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata;

use Prism\Dbal\Schema\IndexKind;
use Prism\Orm\Vector\Distance;

/**
 * Index ou contrainte d'unicité déclarés sur des champs (« total », « billingAddress.city ») ;
 * les colonnes sont résolues au moment de produire le schéma. #[VectorIndex] et #[FullTextIndex]
 * donnent les sortes Vector (un champ, distance, m, efConstruction) et FullText (language).
 *
 * @api
 */
final class IndexMapping
{
    /**
     * @param list<string> $fields
     *
     * @internal construite par Prism (AttributeDriver, MetadataValidator) ; lisible, pas à construire (ADR 0020)
     */
    public function __construct(
        public readonly array $fields,
        public readonly bool $unique = false,
        public readonly ?string $name = null,
        public readonly ?string $where = null,
        public readonly IndexKind $kind = IndexKind::Regular,
        public readonly ?Distance $distance = null,
        public readonly ?int $m = null,
        public readonly ?int $efConstruction = null,
        public readonly ?string $language = null,
    ) {
    }

    public function describe(): string
    {
        return match ($this->kind) {
            IndexKind::Regular => $this->unique ? '#[UniqueConstraint]' : '#[Index]',
            IndexKind::Vector => '#[VectorIndex]',
            IndexKind::FullText => '#[FullTextIndex]',
        };
    }
}
