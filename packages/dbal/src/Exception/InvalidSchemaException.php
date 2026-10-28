<?php

declare(strict_types=1);

namespace Prism\Dbal\Exception;

/**
 * Objet de schéma incohérent, ou impossible à traduire en DDL sur la plateforme.
 *
 * @api
 */
final class InvalidSchemaException extends \InvalidArgumentException implements DbalException
{
    public static function emptyName(string $object): self
    {
        return new self(\sprintf('The %s name must not be empty.', $object));
    }

    public static function invalidColumn(string $column, string $reason): self
    {
        return new self(\sprintf('Invalid column "%s": %s', $column, $reason));
    }

    public static function duplicate(string $object, string $name, string $table): self
    {
        return new self(\sprintf('Duplicate %s "%s" in table "%s" (names are compared case-insensitively, as MySQL does).', $object, $name, $table));
    }

    public static function unknownColumn(string $table, string $column, string $usedBy): self
    {
        return new self(\sprintf('Table "%s" has no column "%s" (used by %s).', $table, $column, $usedBy));
    }

    public static function columnInUse(string $table, string $column, string $usedBy): self
    {
        return new self(\sprintf('Cannot remove column "%s" from table "%s": it is used by %s.', $column, $table, $usedBy));
    }

    public static function nullablePrimaryKey(string $table, string $column): self
    {
        return new self(\sprintf('Column "%s" of table "%s" is part of the primary key and cannot be nullable.', $column, $table));
    }

    public static function invalidAutoincrement(string $table, string $column, string $reason): self
    {
        return new self(\sprintf('Column "%s" of table "%s" cannot be autoincrement: %s', $column, $table, $reason));
    }

    public static function invalidKey(string $object, string $name, string $reason): self
    {
        return new self(\sprintf('Invalid %s "%s": %s', $object, $name, $reason));
    }

    public static function duplicateTable(string $table): self
    {
        return new self(\sprintf('Table "%s" already exists in the schema (names are compared case-insensitively).', $table));
    }

    public static function unknownTable(string $table): self
    {
        return new self(\sprintf('The schema has no table "%s".', $table));
    }

    public static function unknownForeignTable(string $table, string $foreignKey, string $foreignTable): self
    {
        return new self(\sprintf('Foreign key "%s" of table "%s" references table "%s", which is not in the target schema.', $foreignKey, $table, $foreignTable));
    }

    public static function foreignKeyViolation(string $table, string $parent): self
    {
        return new self(\sprintf('The migration was rolled back: rows of table "%s" reference missing rows of table "%s".', $table, $parent));
    }

    public static function migrationInsideTransaction(string $platform): self
    {
        return new self(\sprintf('Cannot migrate a %s schema inside an open transaction: foreign keys cannot be disabled during a transaction, and rebuilding a table would cascade deletes to its children.', $platform));
    }

    public static function foreignKeysStillEnabled(string $platform): self
    {
        return new self(\sprintf('Cannot migrate the %s schema: foreign keys could not be disabled, rebuilding a table would cascade deletes to its children.', $platform));
    }

    public static function unsupportedType(string $platform, string $table, string $column, string $type): self
    {
        return new self(\sprintf('%s cannot declare column "%s.%s" of type "%s": the type has no column declaration on this platform.', $platform, $table, $column, $type));
    }

    public static function unsupportedDatabaseType(string $platform, string $table, string $column, string $declaration): self
    {
        return new self(\sprintf('Cannot introspect column "%s.%s": the %s type "%s" has no Prism type. Register a custom Type or change the column.', $table, $column, $platform, $declaration));
    }

    public static function unsupportedVector(string $platform): self
    {
        return new self(\sprintf('%s does not support the vector type: it has neither a vector distance nor a vector index. Use MariaDB 11.8+ or PostgreSQL with pgvector 0.8+.', $platform));
    }

    public static function unsupportedFeature(string $platform, string $feature): self
    {
        return new self(\sprintf('%s does not support %s.', $platform, $feature));
    }
}
