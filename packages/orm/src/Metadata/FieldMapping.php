<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata;

use Prism\Orm\Exception\MappingException;

/**
 * Champ scalaire mappé sur une colonne. Le type est un nom du TypeRegistry du DBAL.
 * Pour un champ d'embeddable, fieldName est relatif à l'embeddable et columnName est déjà préfixé.
 * vectorObject : la propriété est typée Prism\Orm\Vector\Vector, pas list<float> (RG-09.2).
 *
 * @api
 */
final class FieldMapping
{
    /**
     * @param class-string<\BackedEnum>|null $enumType
     */
    public function __construct(
        public readonly string $fieldName,
        public readonly string $columnName,
        public readonly string $type,
        public readonly bool $nullable = false,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly bool $unique = false,
        public readonly string|int|float|bool|null $default = null,
        public readonly ?string $enumType = null,
        public readonly bool $readonly = false,
        public readonly bool $insertable = true,
        public readonly bool $updatable = true,
        public readonly bool $sensitive = false,
        public readonly bool $lazy = false,
        public readonly bool $fixed = false,
        public readonly bool $vectorObject = false,
    ) {
        foreach (['fieldName' => $fieldName, 'columnName' => $columnName, 'type' => $type] as $name => $value) {
            if ('' === trim($value)) {
                throw MappingException::invalidMetadata(\sprintf('field "%s"', $fieldName), \sprintf('%s must not be empty.', $name));
            }
        }
    }

    public function withColumnName(string $columnName): self
    {
        return new self($this->fieldName, $columnName, $this->type, $this->nullable, $this->length, $this->precision, $this->scale, $this->unique, $this->default, $this->enumType, $this->readonly, $this->insertable, $this->updatable, $this->sensitive, $this->lazy, $this->fixed, $this->vectorObject);
    }

    public function withNullable(bool $nullable): self
    {
        return new self($this->fieldName, $this->columnName, $this->type, $nullable, $this->length, $this->precision, $this->scale, $this->unique, $this->default, $this->enumType, $this->readonly, $this->insertable, $this->updatable, $this->sensitive, $this->lazy, $this->fixed, $this->vectorObject);
    }
}
