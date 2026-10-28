<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata;

use Prism\Orm\Exception\MappingException;

/**
 * Embeddable : ses champs sont des colonnes de la table de l'entité, préfixées. Une propriété
 * nullable (?Address) vaut null quand toutes ses colonnes sont NULL (RG-02.5).
 *
 * @api
 */
final class EmbeddedMapping
{
    /** @var array<string, FieldMapping> */
    private readonly array $fields;

    /**
     * @param class-string       $class
     * @param list<FieldMapping> $fields
     *
     * @internal construite par Prism (AttributeDriver, MetadataValidator) ; lisible, pas à construire (ADR 0020)
     */
    public function __construct(
        public readonly string $fieldName,
        public readonly string $class,
        public readonly string $columnPrefix,
        public readonly bool $nullable,
        array $fields,
    ) {
        if ([] === $fields) {
            throw MappingException::invalidField($class, $fieldName, 'an embeddable must map at least one field.');
        }
        $byName = [];
        foreach ($fields as $field) {
            $byName[$field->fieldName] = $field;
        }
        $this->fields = $byName;
    }

    /**
     * @return list<FieldMapping>
     */
    public function getFields(): array
    {
        return array_values($this->fields);
    }

    public function hasField(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    public function getField(string $name): FieldMapping
    {
        return $this->fields[$name] ?? throw MappingException::unknownField($this->class, $name);
    }
}
