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
     */
    public function __construct(
        public readonly string $name,
        array $columns,
        public readonly bool $unique = false,
    ) {
        if ('' === trim($name)) {
            throw InvalidSchemaException::emptyName('index');
        }
        $this->columns = Keys::columns('index', $name, $columns);
    }
}
