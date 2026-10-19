<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\Exception\MappingException;

/**
 * Une instance par classe de listener, construite sans argument au premier usage, ou
 * enregistrée d'avance par register() (listener avec dépendances, hors Symfony).
 *
 * @api
 */
final class DefaultEntityListenerResolver implements EntityListenerResolver
{
    /** @var array<class-string, object> */
    private array $instances = [];

    public function register(object $listener): void
    {
        $this->instances[$listener::class] = $listener;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     *
     * @throws MappingException constructeur avec arguments requis, sans instance enregistrée
     */
    public function resolve(string $class): object
    {
        if (!isset($this->instances[$class])) {
            if (0 < (new \ReflectionClass($class)->getConstructor()?->getNumberOfRequiredParameters() ?? 0)) {
                throw MappingException::invalidMetadata($class, 'the entity listener has required constructor arguments; register an instance with DefaultEntityListenerResolver::register(), or use an EntityListenerResolver that builds it.');
            }
            $this->instances[$class] = new $class();
        }

        /** @var T */
        return $this->instances[$class];
    }
}
