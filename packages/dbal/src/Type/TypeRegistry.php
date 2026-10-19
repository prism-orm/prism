<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

use Prism\Dbal\Exception\DuplicateTypeException;
use Prism\Dbal\Exception\UnknownTypeException;

/**
 * Types disponibles pour une configuration. Immuable : register() retourne une copie, et
 * aucun état statique n'est partagé entre connexions (mode worker).
 *
 * @api
 */
final class TypeRegistry
{
    /**
     * @param array<string, Type> $types
     */
    private function __construct(private readonly array $types)
    {
    }

    public static function withDefaults(): self
    {
        return new self([])->register(
            new StringType(),
            new TextType(),
            new IntegerType(),
            new SmallIntType(),
            new BigIntType(),
            new BooleanType(),
            new DecimalType(),
            new FloatType(),
            new DateTimeImmutableType(),
            new DateImmutableType(),
            new TimeImmutableType(),
            new JsonType(),
            new UuidType(),
            new BinaryType(),
        );
    }

    /**
     * @throws DuplicateTypeException un nom déjà enregistré
     */
    public function register(Type ...$types): self
    {
        $registered = $this->types;
        foreach ($types as $type) {
            $name = $type->getName();
            if (isset($registered[$name])) {
                throw DuplicateTypeException::named($name);
            }
            $registered[$name] = $type;
        }

        return new self($registered);
    }

    /**
     * @throws UnknownTypeException
     */
    public function get(string $name): Type
    {
        return $this->types[$name] ?? throw UnknownTypeException::named($name, $this->getNames());
    }

    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    /**
     * @return list<string>
     */
    public function getNames(): array
    {
        return array_keys($this->types);
    }
}
