<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema;

use Prism\Dbal\Exception\InvalidSchemaException;

/**
 * Clé étrangère : colonnes locales → colonnes de la table cible, avec l'action ON DELETE.
 *
 * @api
 */
final class ForeignKey
{
    /** @var list<string> */
    public readonly array $localColumns;

    /** @var list<string> */
    public readonly array $foreignColumns;

    /**
     * @param list<string> $localColumns
     * @param list<string> $foreignColumns
     */
    public function __construct(
        public readonly string $name,
        array $localColumns,
        public readonly string $foreignTable,
        array $foreignColumns,
        public readonly ForeignKeyAction $onDelete = ForeignKeyAction::NoAction,
    ) {
        if ('' === trim($name)) {
            throw InvalidSchemaException::emptyName('foreign key');
        }
        if ('' === trim($foreignTable)) {
            throw InvalidSchemaException::invalidKey('foreign key', $name, 'the foreign table must not be empty.');
        }
        $this->localColumns = Keys::columns('foreign key', $name, $localColumns);
        $this->foreignColumns = Keys::columns('foreign key', $name, $foreignColumns);
        if (\count($this->localColumns) !== \count($this->foreignColumns)) {
            throw InvalidSchemaException::invalidKey('foreign key', $name, 'local and foreign column lists must have the same length.');
        }
    }
}
