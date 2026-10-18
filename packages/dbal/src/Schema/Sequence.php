<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema;

use Prism\Dbal\Exception\InvalidSchemaException;

/**
 * Séquence (PostgreSQL, MariaDB) : stratégie SEQUENCE des identifiants (RG-02.4).
 *
 * @api
 */
final class Sequence
{
    public function __construct(
        public readonly string $name,
        public readonly int $start = 1,
        public readonly int $increment = 1,
    ) {
        if ('' === trim($name)) {
            throw InvalidSchemaException::emptyName('sequence');
        }
        if (0 === $increment) {
            throw InvalidSchemaException::invalidKey('sequence', $name, 'the increment must not be zero.');
        }
    }
}
