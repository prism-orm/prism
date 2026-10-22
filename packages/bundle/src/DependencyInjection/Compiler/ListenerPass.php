<?php

declare(strict_types=1);

namespace Prism\Bundle\DependencyInjection\Compiler;

use Prism\Bundle\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Les services tagués prism.listener (#[AsPrismListener]) sur le dispatcher de chaque
 * EntityManager visé, chargés seulement au premier événement (ADR 0013).
 *
 * @internal
 */
final class ListenerPass implements CompilerPassInterface
{
    public const string TAG = 'prism.listener';
    public const string PARAMETER = 'prism.entity_manager_names';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::PARAMETER)) {
            return;
        }
        /** @var list<string> $entityManagers */
        $entityManagers = $container->getParameter(self::PARAMETER);

        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            /** @var list<array<string, mixed>> $tags */
            foreach ($tags as $tag) {
                $method = \is_string($tag['method'] ?? null) ? $tag['method'] : '__invoke';
                $event = \is_string($tag['event'] ?? null) ? $tag['event'] : $this->eventOf($container, $id, $method);
                $targets = \is_string($tag['entityManager'] ?? null) ? [$tag['entityManager']] : $entityManagers;
                foreach ($targets as $target) {
                    if (!\in_array($target, $entityManagers, true)) {
                        throw new InvalidConfigurationException(\sprintf('The Prism listener "%s" listens to the entity manager "%s", which is not declared; declared: %s.', $id, $target, implode(', ', $entityManagers)));
                    }
                    $container->getDefinition('prism.event_dispatcher.' . $target)
                        ->addMethodCall('addListener', [$event, [new ServiceClosureArgument(new Reference($id)), $method], \is_int($tag['priority'] ?? null) ? $tag['priority'] : 0]);
                }
            }
        }
    }

    // l'événement : le type de classe du premier paramètre de la méthode
    private function eventOf(ContainerBuilder $container, string $id, string $method): string
    {
        $class = $container->getReflectionClass((string) $container->getDefinition($id)->getClass(), false);
        $type = null !== $class && $class->hasMethod($method) ? ($class->getMethod($method)->getParameters()[0] ?? null)?->getType() : null;
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            throw new InvalidConfigurationException(\sprintf('The Prism listener "%s::%s()" needs an event: give #[AsPrismListener(event: …)], or type its first parameter with the event class.', $id, $method));
        }

        return $type->getName();
    }
}
