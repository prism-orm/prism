<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\IndexKind;
use Prism\Dbal\Schema\NameGenerator;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\VectorDistance;

/**
 * Crée un index ; nommé de façon déterministe (RG-01.7) sans nom explicite. Concurrently :
 * sans verrou des écritures sous PostgreSQL, hors transaction (RG-06.5) ; sans effet ailleurs.
 * whereNull : index partiel, sur les lignes où cette colonne est NULL (RG-08.15, ADR 0015).
 * kind : index vectoriel (distance, m, efConstruction) ou plein texte (language), ADR 0017.
 *
 * @internal créée par MigrationBuilder
 */
final class CreateIndex implements SchemaOperation
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public readonly string $table,
        public readonly array $columns,
        public readonly bool $unique = false,
        public readonly ?string $name = null,
        public readonly bool $concurrently = false,
        public readonly ?string $whereNull = null,
        public readonly IndexKind $kind = IndexKind::Regular,
        public readonly ?VectorDistance $distance = null,
        public readonly ?int $m = null,
        public readonly ?int $efConstruction = null,
        public readonly ?string $language = null,
    ) {
    }

    /**
     * L'opération qui crée cet index, sa sorte et ses options comprises.
     */
    public static function of(string $table, Index $index): self
    {
        return new self($table, $index->columns, $index->unique, $index->name, whereNull: $index->whereNull, kind: $index->kind, distance: $index->distance, m: $index->m, efConstruction: $index->efConstruction, language: $index->language);
    }

    public function describe(): array
    {
        // concurrently seulement s'il est demandé : les checksums d'avant restent valables
        return ['operation' => 'createIndex', 'table' => $this->table, 'columns' => $this->columns, 'unique' => $this->unique, 'name' => $this->name]
            + ($this->concurrently ? ['concurrently' => true] : [])
            + (null === $this->whereNull ? [] : ['whereNull' => $this->whereNull])
            // la sorte seulement hors index B-tree, pour la même raison
            + (IndexKind::Regular === $this->kind ? [] : ['kind' => $this->kind->name, 'distance' => $this->distance?->name, 'm' => $this->m, 'efConstruction' => $this->efConstruction, 'language' => $this->language]);
    }

    public function apply(Schema $schema): Schema
    {
        return $schema->withTable($schema->getTable($this->table)->withIndex($this->index()));
    }

    /**
     * L'index tel qu'il sera créé, nommé de façon déterministe (RG-01.7).
     */
    public function index(): Index
    {
        return new Index(
            $this->name ?? NameGenerator::index($this->table, $this->columns, $this->unique, $this->whereNull, $this->kind),
            $this->columns,
            $this->unique,
            $this->whereNull,
            $this->kind,
            $this->distance,
            $this->m,
            $this->efConstruction,
            $this->language,
        );
    }
}
