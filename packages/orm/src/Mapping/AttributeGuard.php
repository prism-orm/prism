<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

use Prism\Dbal\Query\Order;
use Prism\Orm\Exception\MappingException;

/**
 * Validations communes exécutées à l'instanciation des attributs.
 *
 * Seules les vérifications locales à un attribut vivent ici ; les vérifications
 * croisées (cible existante, mappedBy cohérent…) relèvent du MetadataValidator.
 *
 * @internal
 */
final class AttributeGuard
{
    private const string FIELD_PATTERN = '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(\.[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)?$/';

    /**
     * Liste non vide de noms de champs uniques (« champ » ou « embedded.champ »).
     *
     * @param array<mixed> $fields
     *
     * @return list<string>
     */
    public static function fieldList(string $attribute, string $argument, array $fields): array
    {
        if ([] === $fields || !array_is_list($fields)) {
            throw MappingException::invalidAttributeArgument($attribute, $argument, 'expected a non-empty list of field names.');
        }

        $names = [];
        foreach ($fields as $field) {
            $names[] = self::fieldName($attribute, $argument, $field);
        }

        if (\count($names) !== \count(array_unique($names))) {
            throw MappingException::invalidAttributeArgument($attribute, $argument, 'duplicate field names.');
        }

        return $names;
    }

    public static function fieldName(string $attribute, string $argument, mixed $field): string
    {
        if (!\is_string($field) || 1 !== preg_match(self::FIELD_PATTERN, $field)) {
            throw MappingException::invalidAttributeArgument($attribute, $argument, \sprintf('"%s" is not a valid field name.', \is_string($field) ? $field : get_debug_type($field)));
        }

        return $field;
    }

    public static function nullableFieldName(string $attribute, string $argument, ?string $field): ?string
    {
        return null === $field ? null : self::fieldName($attribute, $argument, $field);
    }

    public static function name(string $attribute, string $argument, ?string $name): ?string
    {
        if (null !== $name && '' === trim($name)) {
            throw MappingException::invalidAttributeArgument($attribute, $argument, 'must not be empty.');
        }

        return $name;
    }

    public static function positiveInt(string $attribute, string $argument, ?int $value, int $min = 1, ?int $max = null): ?int
    {
        if (null === $value) {
            return null;
        }
        if ($value < $min || (null !== $max && $value > $max)) {
            $range = null === $max ? \sprintf('>= %d', $min) : \sprintf('between %d and %d', $min, $max);
            throw MappingException::invalidAttributeArgument($attribute, $argument, \sprintf('must be %s, got %d.', $range, $value));
        }

        return $value;
    }

    /**
     * @param array<mixed> $cascade
     *
     * @return list<Cascade>
     */
    public static function cascade(string $attribute, array $cascade): array
    {
        if (!array_is_list($cascade)) {
            throw MappingException::invalidAttributeArgument($attribute, 'cascade', 'expected a list of Cascade cases.');
        }
        $unique = [];
        foreach ($cascade as $item) {
            if (!$item instanceof Cascade) {
                throw MappingException::invalidAttributeArgument($attribute, 'cascade', \sprintf('expected Cascade, got %s.', get_debug_type($item)));
            }
            if (!\in_array($item, $unique, true)) {
                $unique[] = $item;
            }
        }

        return $unique;
    }

    /**
     * L'existence des classes est vérifiée par le MetadataValidator.
     *
     * @param array<mixed> $classes
     *
     * @return list<class-string>
     */
    public static function classList(string $attribute, string $argument, array $classes): array
    {
        if ([] === $classes || !array_is_list($classes)) {
            throw MappingException::invalidAttributeArgument($attribute, $argument, 'expected a non-empty list of class names.');
        }

        foreach ($classes as $class) {
            if (!\is_string($class) || '' === trim($class)) {
                throw MappingException::invalidAttributeArgument($attribute, $argument, \sprintf('expected a class name, got %s.', get_debug_type($class)));
            }
        }

        /** @var list<class-string> $classes */
        return $classes;
    }

    /**
     * Le PHPDoc class-string<\BackedEnum> n'est pas vérifié quand PHP instancie l'attribut.
     *
     * @return class-string<\BackedEnum>|null
     */
    public static function backedEnum(string $attribute, string $argument, ?string $class): ?string
    {
        if (null !== $class && !is_a($class, \BackedEnum::class, true)) {
            throw MappingException::invalidAttributeArgument($attribute, $argument, \sprintf('"%s" must be a backed enum.', $class));
        }

        return $class;
    }

    /**
     * Carte non vide « champ => Order ».
     *
     * @param array<mixed> $fields
     *
     * @return array<string, Order>
     */
    public static function orderMap(string $attribute, string $argument, array $fields): array
    {
        if ([] === $fields) {
            throw MappingException::invalidAttributeArgument($attribute, $argument, 'expected at least one field.');
        }

        $map = [];
        foreach ($fields as $field => $order) {
            $field = self::fieldName($attribute, $argument, $field);
            if (!$order instanceof Order) {
                throw MappingException::invalidAttributeArgument($attribute, $argument, \sprintf('direction for "%s" must be Order::Asc or Order::Desc.', $field));
            }
            $map[$field] = $order;
        }

        return $map;
    }

    public static function exclusive(string $attribute, string $a, mixed $valueA, string $b, mixed $valueB): void
    {
        if (null !== $valueA && null !== $valueB) {
            throw MappingException::invalidAttributeArgument($attribute, $a, \sprintf('cannot be combined with "%s".', $b));
        }
    }
}
