<?php

declare(strict_types=1);

namespace Prism\Bundle\Security;

use Symfony\Bundle\SecurityBundle\DependencyInjection\Security\UserProvider\UserProviderFactoryInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Le type « prism » des providers de security.yaml :
 *
 *     providers:
 *         users:
 *             prism: { class: App\Entity\User, property: email }
 *
 * @internal
 */
final class EntityUserProviderFactory implements UserProviderFactoryInterface
{
    /**
     * @param array<mixed> $config class, property, entity_manager (addConfiguration())
     */
    public function create(ContainerBuilder $container, string $id, array $config): void
    {
        $container->setDefinition($id, new ChildDefinition('prism.security.user_provider'))
            ->replaceArgument(2, $config['class'])
            ->replaceArgument(3, $config['property'])
            ->replaceArgument(4, $config['entity_manager']);
    }

    public function getKey(): string
    {
        return 'prism';
    }

    public function addConfiguration(NodeDefinition $builder): void
    {
        \assert($builder instanceof ArrayNodeDefinition);
        $builder
            ->children()
                ->scalarNode('class')->isRequired()->cannotBeEmpty()->info('The user entity class.')->end()
                ->scalarNode('property')->defaultNull()->info('The field that holds the identifier; without it, the repository implements Prism\Bundle\Security\UserLoaderInterface.')->end()
                ->scalarNode('entity_manager')->defaultNull()->info('By default, the one that maps the class.')->end()
            ->end();
    }
}
