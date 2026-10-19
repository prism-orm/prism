<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata;

/**
 * ReflectionProperty créées au premier usage. Se sérialise vide : une metadata relue du cache
 * reconstruit sa Reflection à la demande (les ReflectionProperty ne sont pas sérialisables).
 *
 * @internal
 */
final class ReflectionCache
{
    /** @var array<class-string, array<string, \ReflectionProperty>> sans clé concaténée : appelé pour chaque propriété hydratée */
    private array $properties = [];

    /**
     * @param class-string $class
     */
    public function property(string $class, string $property): \ReflectionProperty
    {
        return $this->properties[$class][$property] ??= new \ReflectionProperty($class, $property);
    }

    public function count(): int
    {
        return array_sum(array_map(\count(...), $this->properties));
    }

    /**
     * @return array{}
     */
    public function __serialize(): array
    {
        return [];
    }

    /**
     * @param array<mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->properties = [];
    }
}
