<?php

declare(strict_types=1);

namespace Prism\Bundle\Event;

use Prism\Orm\Event\DefaultEntityListenerResolver;
use Prism\Orm\Event\EntityListenerResolver;
use Psr\Container\ContainerInterface;

/**
 * Les listeners d'entité marqués #[AsEntityListener] sont des services ; les autres sont
 * instanciés sans argument, comme hors Symfony.
 *
 * @internal
 */
final class ContainerEntityListenerResolver implements EntityListenerResolver
{
    private readonly DefaultEntityListenerResolver $fallback;

    public function __construct(private readonly ContainerInterface $listeners)
    {
        $this->fallback = new DefaultEntityListenerResolver();
    }

    public function resolve(string $class): object
    {
        if ($this->listeners->has($class)) {
            $listener = $this->listeners->get($class);
            \assert($listener instanceof $class);

            return $listener;
        }

        return $this->fallback->resolve($class);
    }
}
