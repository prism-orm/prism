<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Dispatcher PSR-14 de test : écouteurs par classe d'événement, journal des événements reçus.
 *
 * @internal
 */
final class TestDispatcher implements EventDispatcherInterface
{
    /** @var array<class-string, list<\Closure(object): void>> */
    private array $listeners = [];

    /** @var list<object> */
    public array $dispatched = [];

    /**
     * @template E of object
     *
     * @param class-string<E>   $event
     * @param \Closure(E): void $listener
     */
    public function listen(string $event, \Closure $listener): void
    {
        /** @var \Closure(object): void $listener */
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(object $event): object
    {
        $this->dispatched[] = $event;
        foreach ($this->listeners[$event::class] ?? [] as $listener) {
            $listener($event);
        }

        return $event;
    }

    /**
     * @param class-string $event
     *
     * @return list<object>
     */
    public function ofType(string $event): array
    {
        return array_values(array_filter($this->dispatched, static fn (object $e): bool => $e instanceof $event));
    }
}
