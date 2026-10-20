<?php

declare(strict_types=1);

namespace Prism\Orm\Query;

use Prism\Orm\Metadata\AssociationMapping;
use Prism\Orm\Metadata\ClassMetadata;

/**
 * Un alias hydraté par une requête typée : sa racine ou une relation chargée par fetchJoin().
 * Sépare ses colonnes « alias__colonne » des lignes, puis garde ses entités par identifiant.
 *
 * @internal
 */
final class Node
{
    /** @var array<string, string> nom sélectionné → colonne */
    public array $columns = [];

    /** @var array<string, array<string, mixed>> sa ligne, par identifiant, dans l'ordre des lignes */
    public array $rows = [];

    /** @var array<string, object> par identifiant */
    public array $entities = [];

    /**
     * @param ClassMetadata<object> $metadata
     * @param string|null           $owner    alias du propriétaire de la relation
     */
    public function __construct(
        public readonly ClassMetadata $metadata,
        public readonly ?AssociationMapping $association,
        public readonly ?string $owner,
    ) {
    }

    /**
     * Retient la ligne de l'entité, et rend son identifiant (null sans ligne jointe).
     *
     * @param array<string, mixed> $row
     */
    public function read(array $row): ?string
    {
        $entityRow = [];
        foreach ($this->columns as $name => $column) {
            $entityRow[$column] = $row[$name] ?? null;
        }
        $id = $entityRow[$this->metadata->getIdField()->columnName] ?? null;
        if (!\is_int($id) && !\is_string($id)) {
            return null;
        }
        $this->rows[(string) $id] ??= $entityRow;

        return (string) $id;
    }
}
