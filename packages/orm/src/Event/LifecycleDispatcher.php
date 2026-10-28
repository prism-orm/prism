<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\Mapping\LifecycleEvent;
use Prism\Orm\Metadata\ClassMetadata;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Point d'appel unique des événements : pour une entité, ses callbacks, puis ses listeners
 * d'entité, puis le dispatcher PSR-14 ; les événements de flush ne vont qu'au dispatcher.
 *
 * Un callback reçoit l'événement, une méthode de listener l'entité puis l'événement ; PHP ignore
 * les arguments qu'une méthode ne déclare pas, et le MetadataValidator vérifie les autres.
 *
 * @internal
 */
final class LifecycleDispatcher
{
    /** @var array<class-string, array<string, \ReflectionMethod>> */
    private array $methods = [];

    public function __construct(
        private readonly EntityListenerResolver $listeners,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param \Closure(): object    $event    construit seulement si quelqu'un l'attend
     *
     * @return bool faux si aucun callback, listener ni dispatcher n'a reçu l'événement : l'entité n'a
     *              pas pu changer
     */
    public function lifecycle(ClassMetadata $metadata, LifecycleEvent $type, object $entity, \Closure $event): bool
    {
        $callbacks = $metadata->getLifecycleCallbacks($type);
        $listeners = $metadata->getEntityListenerCallbacks($type);
        if ([] === $callbacks && [] === $listeners && null === $this->events) {
            return false;
        }

        $object = $event();
        foreach ($callbacks as $method) {
            $this->method($metadata->className, $method)->invoke($entity, $object);
        }
        foreach ($listeners as [$class, $method]) {
            $this->method($class, $method)->invoke($this->listeners->resolve($class), $entity, $object);
        }
        $this->events?->dispatch($object);

        return true;
    }

    public function dispatch(object $event): void
    {
        $this->events?->dispatch($event);
    }

    /**
     * @param class-string $class
     */
    private function method(string $class, string $method): \ReflectionMethod
    {
        return $this->methods[$class][$method] ??= new \ReflectionMethod($class, $method);
    }
}
