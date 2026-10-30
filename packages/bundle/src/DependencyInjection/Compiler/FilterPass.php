<?php

declare(strict_types=1);

namespace Prism\Bundle\DependencyInjection\Compiler;

use Prism\Bundle\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Les services tagués prism.filter (EntityFilter autoconfigurés) dans les filtres de chaque
 * EntityManager visé, sous leur nom (ADR 0014) ; un tag nommé remplace l'autotag sans nom.
 * Le filtre du tenant reste verrouillé.
 *
 * @internal
 */
final class FilterPass implements CompilerPassInterface
{
    public const string TAG = 'prism.filter';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(ListenerPass::PARAMETER)) {
            return;
        }
        /** @var list<string> $entityManagers */
        $entityManagers = $container->getParameter(ListenerPass::PARAMETER);

        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            /** @var list<array<string, mixed>> $tags */
            $hasNamedTag = false;
            foreach ($tags as $tag) {
                if (\is_string($tag['name'] ?? null)) {
                    $hasNamedTag = true;
                    break;
                }
            }
            foreach ($tags as $tag) {
                if ($hasNamedTag && !\is_string($tag['name'] ?? null)) {
                    continue;
                }
                $name = \is_string($tag['name'] ?? null) ? $tag['name'] : $id;
                $targets = \is_string($tag['entity_manager'] ?? null) ? [$tag['entity_manager']] : $entityManagers;
                foreach ($targets as $target) {
                    if (!\in_array($target, $entityManagers, true)) {
                        throw new InvalidConfigurationException(\sprintf('The Prism filter "%s" applies to the entity manager "%s", which is not declared; declared: %s.', $id, $target, implode(', ', $entityManagers)));
                    }
                    $container->getDefinition('prism.filters.' . $target)->addMethodCall('add', [$name, new Reference($id)]);
                }
            }
        }
    }
}
