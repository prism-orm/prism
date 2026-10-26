<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema;

use Prism\Dbal\Exception\InvalidSchemaException;

/**
 * Index (unique ou non) sur des colonnes, dans l'ordre donné.
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
    ) {
        if ('' === trim($name)) {
            throw InvalidSchemaException::emptyName('index');
        }
        if (null !== $whereNull && '' === trim($whereNull)) {
            throw InvalidSchemaException::emptyName('partial index column');
        }
        $this->columns = Keys::columns('index', $name, $columns);
    }
}
