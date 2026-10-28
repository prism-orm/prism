<?php

declare(strict_types=1);

namespace Prism\Orm\Hydrator;

use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\Type;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Metadata\ClassMetadata;

/**
 * Transforme une ligne de la base en entité : conversion par les Type du DBAL, instanciation
 * sans constructeur (RG-03.12).
 *
 * L'identity map et les événements ne sont pas son affaire : l'UnitOfWork décide de réutiliser
 * une instance ou d'en hydrater une nouvelle (RG-03.3), puis publie PostLoad (callbacks
 * #[PostLoad] compris).
 *
 * @internal
 */
final class ObjectHydrator
{
    /** @var array<class-string, \ReflectionClass<object>> */
    private array $classes = [];

    /** @var array<class-string, array<string, Type>> colonne → Type, par classe */
    private array $columnTypes = [];

    public function __construct(
        private readonly ConversionContext $conversion,
        private readonly TypeRegistry $types,
    ) {
    }

    /**
     * @template T of object
     *
     * @param ClassMetadata<T>     $metadata
     * @param array<string, mixed> $row      ligne telle que la base la renvoie
     *
     * @return T
     */
    public function hydrate(ClassMetadata $metadata, array $row): object
    {
        return $this->fromPhpRow($metadata, $this->toPhpRow($metadata, $row));
    }

    /**
     * Entité à partir d'une ligne déjà convertie par toPhpRow() : l'UnitOfWork réutilise ces
     * valeurs pour son snapshot.
     *
     * @template T of object
     *
     * @param ClassMetadata<T>     $metadata
     * @param array<string, mixed> $row      valeurs PHP, par colonne
     *
     * @return T
     */
    public function fromPhpRow(ClassMetadata $metadata, array $row): object
    {
        /** @var T $entity */
        $entity = ($this->classes[$metadata->className] ??= new \ReflectionClass($metadata->className))->newInstanceWithoutConstructor();
        $metadata->hydrateRow($entity, $row);

        return $entity;
    }

    /**
     * Réécrit une entité existante avec une ligne relue. Une propriété readonly déjà écrite n'est
     * pas touchée (RG-02.6) : sa valeur ne change jamais, et PHP refuserait de la réécrire.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $row
     */
    public function refresh(object $entity, ClassMetadata $metadata, array $row): void
    {
        $row = $this->toPhpRow($metadata, $row);

        foreach ($metadata->getFields() as $field) {
            if ($field->readonly && self::isInitialized($metadata->className, $field->fieldName, $entity)) {
                unset($row[$field->columnName]);
            }
        }
        foreach ($metadata->getEmbedded() as $embedded) {
            $property = new \ReflectionProperty($metadata->className, $embedded->fieldName);
            if ($property->isReadOnly() && $property->isInitialized($entity)) {
                foreach ($embedded->getFields() as $field) {
                    unset($row[$field->columnName]);
                }
            }
        }

        $metadata->hydrateRow($entity, $row);
    }

    /**
     * Valeur PHP d'une valeur de la base, par le Type du DBAL.
     */
    public function toPhpValue(string $type, mixed $value): mixed
    {
        return $this->types->get($type)->toPhp($value, $this->conversion);
    }

    /**
     * Valeurs PHP des colonnes connues de la ligne ; les autres sont ignorées.
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $row
     *
     * @return array<string, mixed>
     */
    public function toPhpRow(ClassMetadata $metadata, array $row): array
    {
        $types = $this->columnTypes[$metadata->className] ??= array_map($this->types->get(...), $metadata->getColumnTypes());
        $converted = [];
        foreach ($types as $column => $type) {
            if (\array_key_exists($column, $row)) {
                $converted[$column] = $type->toPhp($row[$column], $this->conversion);
            }
        }

        return $converted;
    }

    /**
     * @param class-string $class
     */
    private static function isInitialized(string $class, string $property, object $entity): bool
    {
        return new \ReflectionProperty($class, $property)->isInitialized($entity);
    }
}
