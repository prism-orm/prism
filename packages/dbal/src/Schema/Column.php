<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema;

use Prism\Dbal\Exception\InvalidSchemaException;

/**
 * Colonne d'une table. Immuable : chaque with*() retourne une copie.
 *
 * Le type est un nom du TypeRegistry ('decimal', 'datetime_immutable'…) ; sa déclaration SQL
 * est produite par la plateforme. Un default null signifie « pas de valeur par défaut ».
 *
 * @api
 */
final class Column
{
    private const array INTEGER_TYPES = ['integer', 'smallint', 'bigint'];

    // ces colonnes ne peuvent pas porter de valeur par défaut littérale sous MySQL : règle portable
    private const array NO_DEFAULT_TYPES = ['text', 'json', 'binary'];

    /**
     * Faux pour les types sans défaut géré par Prism (texte, JSON, binaire) : non portables.
     */
    public static function acceptsDefault(string $type): bool
    {
        return !\in_array($type, self::NO_DEFAULT_TYPES, true);
    }

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable = false,
        public readonly string|int|float|bool|null $default = null,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly bool $autoincrement = false,
    ) {
        if ('' === trim($name)) {
            throw InvalidSchemaException::emptyName('column');
        }
        if ('' === trim($type)) {
            throw InvalidSchemaException::invalidColumn($name, 'the type must not be empty.');
        }
        if (null !== $length && $length < 1) {
            throw InvalidSchemaException::invalidColumn($name, \sprintf('the length must be positive, got %d.', $length));
        }
        if ('decimal' === $type && (null === $precision || null === $scale)) {
            throw InvalidSchemaException::invalidColumn($name, 'a decimal column needs a precision and a scale.');
        }
        if (null !== $precision && ($precision < 1 || $precision > 65)) {
            throw InvalidSchemaException::invalidColumn($name, \sprintf('the precision must be between 1 and 65, got %d.', $precision));
        }
        if (null !== $scale && ($scale < 0 || $scale > ($precision ?? 65))) {
            throw InvalidSchemaException::invalidColumn($name, \sprintf('the scale must be between 0 and the precision, got %d.', $scale));
        }
        if (null !== $default && \in_array($type, self::NO_DEFAULT_TYPES, true)) {
            throw InvalidSchemaException::invalidColumn($name, \sprintf('a %s column cannot have a default value.', $type));
        }
        if (\is_float($default) && !is_finite($default)) {
            throw InvalidSchemaException::invalidColumn($name, 'the default value must be finite.');
        }
        if ($autoincrement && !\in_array($type, self::INTEGER_TYPES, true)) {
            throw InvalidSchemaException::invalidColumn($name, 'only integer, smallint and bigint columns can be autoincrement.');
        }
        if ($autoincrement && ($nullable || null !== $default)) {
            throw InvalidSchemaException::invalidColumn($name, 'an autoincrement column is NOT NULL and has no default value.');
        }
    }

    public function withName(string $name): self
    {
        return new self($name, $this->type, $this->nullable, $this->default, $this->length, $this->precision, $this->scale, $this->autoincrement);
    }

    public function withType(string $type, ?int $length = null, ?int $precision = null, ?int $scale = null): self
    {
        return new self($this->name, $type, $this->nullable, $this->default, $length, $precision, $scale, $this->autoincrement);
    }

    public function withNullable(bool $nullable): self
    {
        return new self($this->name, $this->type, $nullable, $this->default, $this->length, $this->precision, $this->scale, $this->autoincrement);
    }

    public function withDefault(string|int|float|bool|null $default): self
    {
        return new self($this->name, $this->type, $this->nullable, $default, $this->length, $this->precision, $this->scale, $this->autoincrement);
    }

    public function withAutoincrement(bool $autoincrement): self
    {
        return new self($this->name, $this->type, $this->nullable, $this->default, $this->length, $this->precision, $this->scale, $autoincrement);
    }
}
