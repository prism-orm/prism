<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata\Naming;

/**
 * Noms repris tels quels : nom court de la classe, nom de la propriété.
 *
 * @api
 */
final class DefaultNamingStrategy implements NamingStrategy
{
    public function classToTableName(string $class): string
    {
        $position = strrpos($class, '\\');

        return false === $position ? $class : substr($class, $position + 1);
    }

    public function propertyToColumnName(string $property): string
    {
        return $property;
    }

    public function embeddedColumnPrefix(string $property): string
    {
        return $property . '_';
    }

    public function joinColumnName(string $property, string $referencedColumn = 'id'): string
    {
        return $property . '_' . $referencedColumn;
    }

    public function joinTableName(string $owningClass, string $targetClass): string
    {
        return $this->classToTableName($owningClass) . '_' . $this->classToTableName($targetClass);
    }

    public function joinKeyColumnName(string $class, string $referencedColumn = 'id'): string
    {
        return $this->classToTableName($class) . '_' . $referencedColumn;
    }
}
