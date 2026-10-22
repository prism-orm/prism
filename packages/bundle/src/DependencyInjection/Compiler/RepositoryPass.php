<?php

declare(strict_types=1);

namespace Prism\Bundle\DependencyInjection\Compiler;

use Prism\Orm\Mapping\Entity;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * RG-07.2 : chaque repositoryClass devient un service construit par
 * EntityManager::getRepository(), même instance que celle de l'EntityManager. La passe tourne
 * après services.yaml : une définition autowirée de l'application est remplacée, le constructeur
 * fixé par l'ADR 0006 ne s'autowirant pas. Seul #[Entity] est lu : une erreur de mapping ne casse
 * pas la compilation du conteneur.
 *
 * @internal
 */
final class RepositoryPass implements CompilerPassInterface
{
    public const string PARAMETER = 'prism.entity_dirs';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::PARAMETER)) {
            return;
        }
        /** @var array<string, list<string>> $dirs */
        $dirs = $container->getParameterBag()->resolveValue($container->getParameter(self::PARAMETER));

        foreach ($dirs as $entityManager => $directories) {
            foreach (new AttributeDriver($directories)->getAllClassNames() as $class) {
                $entity = (new \ReflectionClass($class)->getAttributes(Entity::class)[0] ?? null)?->newInstance();
                if (null === $entity || null === $entity->repositoryClass) {
                    continue;
                }
                $container->setDefinition($entity->repositoryClass, new Definition($entity->repositoryClass, [$class]))
                    ->setFactory([new Reference('prism.entity_manager.' . $entityManager), 'getRepository']);
            }
        }
    }
}
