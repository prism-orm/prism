<?php

declare(strict_types=1);

namespace Prism\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Sans DoctrineBundle, make:entity et make:migration sont ceux de Prism (ADR 0013) : ceux de
 * MakerBundle, écrits pour Doctrine et inutilisables sans lui, sont retirés avant que MakerBundle
 * n'enregistre ses commandes.
 *
 * @internal
 */
final class MakerPass implements CompilerPassInterface
{
    public const string PARAMETER = 'prism.maker.replaces_doctrine';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::PARAMETER) || true !== $container->getParameter(self::PARAMETER)) {
            return;
        }
        foreach (['maker.maker.make_entity', 'maker.maker.make_migration'] as $id) {
            $container->removeDefinition($id);
        }
    }
}
