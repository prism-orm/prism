<?php

declare(strict_types=1);

namespace Prism\Orm\Hydrator;

use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Mapping\LifecycleEvent;
use Prism\Orm\Metadata\ClassMetadata;

/**
 * Transforme une ligne de la base en entité : conversion par les Type du DBAL, instanciation
 * sans constructeur (RG-03.12), puis méthodes #[PostLoad].
 *
 * L'identity map n'est pas son affaire : l'UnitOfWork décide de réutiliser une instance ou d'en
 * hydrater une nouvelle (RG-03.3).
 *
 * @internal
 */
final class ObjectHydrator
{
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
        $entity = new \ReflectionClass($metadata->className)->newInstanceWithoutConstructor();
        $metadata->hydrateRow($entity, $this->toPhpRow($metadata, $row));
        $this->postLoad($metadata, $entity);

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
        $this->postLoad($metadata, $entity);
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
        $converted = [];
        foreach ($metadata->getColumnTypes() as $column => $type) {
            if (\array_key_exists($column, $row)) {
                $converted[$column] = $this->types->get($type)->toPhp($row[$column], $this->conversion);
            }
        }

        return $converted;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function postLoad(ClassMetadata $metadata, object $entity): void
    {
        foreach ($metadata->getLifecycleCallbacks(LifecycleEvent::PostLoad) as $method) {
            new \ReflectionMethod($entity, $method)->invoke($entity);
        }
    }

    /**
     * @param class-string $class
     */
    private static function isInitialized(string $class, string $property, object $entity): bool
    {
        return new \ReflectionProperty($class, $property)->isInitialized($entity);
    }
}
