<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema;

/**
 * Passage d'un schéma à un autre ; les tables modifiées ne contiennent que des TableDiff non vides.
 *
 * @internal
 */
final class SchemaDiff
{
    /**
     * @param list<Table>     $createdTables
     * @param list<Table>     $droppedTables
     * @param list<TableDiff> $alteredTables
     * @param list<Sequence>  $createdSequences
     * @param list<Sequence>  $droppedSequences
     * @param list<Sequence>  $alteredSequences nouvelle définition
     */
    public function __construct(
        public readonly array $createdTables = [],
        public readonly array $droppedTables = [],
        public readonly array $alteredTables = [],
        public readonly array $createdSequences = [],
        public readonly array $droppedSequences = [],
        public readonly array $alteredSequences = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->createdTables
            && [] === $this->droppedTables
            && [] === $this->alteredTables
            && [] === $this->createdSequences
            && [] === $this->droppedSequences
            && [] === $this->alteredSequences;
    }
}
