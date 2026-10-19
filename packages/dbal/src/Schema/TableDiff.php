<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema;

/**
 * Différences d'une table présente des deux côtés. Un index ou une clé étrangère modifiés
 * apparaissent en suppression + ajout ; une colonne renommée aussi (RG-01.9).
 *
 * @api
 */
final class TableDiff
{
    /**
     * @param list<Column>      $addedColumns
     * @param list<Column>      $droppedColumns
     * @param list<ColumnDiff>  $changedColumns
     * @param list<string>|null $fromPrimaryKey
     * @param list<string>|null $toPrimaryKey
     * @param list<Index>       $addedIndexes
     * @param list<Index>       $droppedIndexes
     * @param list<ForeignKey>  $addedForeignKeys
     * @param list<ForeignKey>  $droppedForeignKeys
     */
    public function __construct(
        public readonly string $name,
        public readonly Table $from,
        public readonly Table $to,
        public readonly array $addedColumns = [],
        public readonly array $droppedColumns = [],
        public readonly array $changedColumns = [],
        public readonly ?array $fromPrimaryKey = null,
        public readonly ?array $toPrimaryKey = null,
        public readonly array $addedIndexes = [],
        public readonly array $droppedIndexes = [],
        public readonly array $addedForeignKeys = [],
        public readonly array $droppedForeignKeys = [],
    ) {
    }

    public function primaryKeyChanged(): bool
    {
        return Comparator::normalizedList($this->fromPrimaryKey) !== Comparator::normalizedList($this->toPrimaryKey);
    }

    public function isEmpty(): bool
    {
        return [] === $this->addedColumns
            && [] === $this->droppedColumns
            && [] === $this->changedColumns
            && !$this->primaryKeyChanged()
            && [] === $this->addedIndexes
            && [] === $this->droppedIndexes
            && [] === $this->addedForeignKeys
            && [] === $this->droppedForeignKeys;
    }
}
