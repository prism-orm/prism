<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

use Prism\Orm\Exception\MappingException;
use Prism\Orm\Vector\Distance;

/**
 * Index HNSW sur un champ de type vector (pilier 4).
 *
 * - m : connexions par nœud (2 à 100) ;
 * - efConstruction : largeur de recherche à la construction, au moins 2 × m (contrainte pgvector).
 *
 * Sous MariaDB, la colonne indexée doit être NOT NULL (vérifié par le MetadataValidator).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class VectorIndex implements MappingAttribute
{
    public readonly int $m;

    public readonly int $efConstruction;

    public function __construct(
        public readonly Distance $distance = Distance::Cosine,
        int $m = 16,
        int $efConstruction = 64,
    ) {
        $this->m = (int) AttributeGuard::positiveInt('VectorIndex', 'm', $m, 2, 100);
        $this->efConstruction = (int) AttributeGuard::positiveInt('VectorIndex', 'efConstruction', $efConstruction, 4, 1000);

        if ($this->efConstruction < 2 * $this->m) {
            throw MappingException::invalidAttributeArgument('VectorIndex', 'efConstruction', \sprintf('must be at least 2 × m (%d), got %d.', 2 * $this->m, $this->efConstruction));
        }
    }
}
