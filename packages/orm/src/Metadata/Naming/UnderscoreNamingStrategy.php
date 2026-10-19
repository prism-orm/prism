<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata\Naming;

/**
 * Stratégie par défaut : snake_case en minuscules (InvoiceLine → invoice_line, createdAt →
 * created_at, HTTPRequest → http_request). Les noms générés n'ont jamais besoin d'être cités
 * pour être lisibles, même si Prism les cite toujours.
 *
 * @api
 */
final class UnderscoreNamingStrategy implements NamingStrategy
{
    public function classToTableName(string $class): string
    {
        return self::snake(self::shortName($class));
    }

    public function propertyToColumnName(string $property): string
    {
        return self::snake($property);
    }

    public function embeddedColumnPrefix(string $property): string
    {
        return self::snake($property) . '_';
    }

    public function joinColumnName(string $property, string $referencedColumn = 'id'): string
    {
        return self::snake($property) . '_' . $referencedColumn;
    }

    public function joinTableName(string $owningClass, string $targetClass): string
    {
        return $this->classToTableName($owningClass) . '_' . $this->classToTableName($targetClass);
    }

    public function joinKeyColumnName(string $class, string $referencedColumn = 'id'): string
    {
        return $this->classToTableName($class) . '_' . $referencedColumn;
    }

    private static function snake(string $name): string
    {
        // « userID » → user_ID, « HTTPRequest » → HTTP_Request, « Invoice2Line » → Invoice2_Line
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $name));
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return false === $position ? $class : substr($class, $position + 1);
    }
}
