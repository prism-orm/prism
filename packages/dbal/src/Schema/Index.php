<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema;

use Prism\Dbal\Exception\InvalidSchemaException;

/**
 * Index (unique ou non) sur des colonnes, dans l'ordre donné.
 *
 * Deux sortes propres au pilier 4 (ADR 0017) :
 * - Vector : une colonne vector, HNSW, avec sa distance, m et efConstruction (null : défaut de la
 *   plateforme) ;
 * - FullText : des colonnes de texte, avec la configuration de langue de PostgreSQL (null : simple).
 * Ni l'un ni l'autre n'est unique ni partiel.
 *
 * @api
 */
final class Index
{
    /** @var list<string> */
    public readonly array $columns;

    /**
     * @param list<string> $columns
     * @param string|null  $whereNull index partiel : seules les lignes où cette colonne est NULL
     *                                (suppression logique, RG-08.15, ADR 0015)
     */
    public function __construct(
        public readonly string $name,
        array $columns,
        public readonly bool $unique = false,
        public readonly ?string $whereNull = null,
        public readonly IndexKind $kind = IndexKind::Regular,
        public readonly ?VectorDistance $distance = null,
        public readonly ?int $m = null,
        public readonly ?int $efConstruction = null,
        public readonly ?string $language = null,
    ) {
        if ('' === trim($name)) {
            throw InvalidSchemaException::emptyName('index');
        }
        if (null !== $whereNull && '' === trim($whereNull)) {
            throw InvalidSchemaException::emptyName('partial index column');
        }
        $this->columns = Keys::columns('index', $name, $columns);

        if (IndexKind::Regular !== $kind && ($unique || null !== $whereNull)) {
            throw InvalidSchemaException::invalidKey('index', $name, 'a vector or full-text index can be neither unique nor partial.');
        }
        if (IndexKind::Vector === $kind && (1 !== \count($this->columns) || null === $distance)) {
            throw InvalidSchemaException::invalidKey('index', $name, 'a vector index covers one column and needs a distance.');
        }
        if (IndexKind::Vector !== $kind && (null !== $distance || null !== $m || null !== $efConstruction)) {
            throw InvalidSchemaException::invalidKey('index', $name, 'distance, m and efConstruction belong to a vector index.');
        }
        if (null !== $m && ($m < 2 || $m > 100)) {
            throw InvalidSchemaException::invalidKey('index', $name, \sprintf('m must be between 2 and 100, got %d.', $m));
        }
        if (null !== $efConstruction && $efConstruction < 2 * ($m ?? 2)) {
            throw InvalidSchemaException::invalidKey('index', $name, \sprintf('efConstruction must be at least 2 × m, got %d.', $efConstruction));
        }
        if (null !== $language && (IndexKind::FullText !== $kind || 1 !== preg_match('/^[a-z_]+$/', $language))) {
            throw InvalidSchemaException::invalidKey('index', $name, 'a language belongs to a full-text index and is a configuration name ([a-z_]+).');
        }
    }

    public function withDistance(?VectorDistance $distance): self
    {
        return new self($this->name, $this->columns, $this->unique, $this->whereNull, $this->kind, $distance, $this->m, $this->efConstruction, $this->language);
    }

    public function withVectorOptions(?int $m, ?int $efConstruction): self
    {
        return new self($this->name, $this->columns, $this->unique, $this->whereNull, $this->kind, $this->distance, $m, $efConstruction, $this->language);
    }

    public function withLanguage(?string $language): self
    {
        return new self($this->name, $this->columns, $this->unique, $this->whereNull, $this->kind, $this->distance, $this->m, $this->efConstruction, $language);
    }
}
