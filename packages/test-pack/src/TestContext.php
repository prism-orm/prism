<?php

declare(strict_types=1);

namespace Prism\TestPack;

use Prism\Orm\EntityManager;
use Prism\TestPack\Exception\TestPackException;
use Symfony\Component\DependencyInjection\Container;

/**
 * Le kernel de test en cours, pour les factories et les assertions : posé par ResetDatabase au
 * début de chaque test, retiré à la fin.
 *
 * @internal
 */
final class TestContext
{
    private static ?Container $container = null;

    public static function use(?Container $container): void
    {
        self::$container = $container;
    }

    public static function container(): Container
    {
        return self::$container ?? throw new TestPackException('No test kernel: use the Prism\TestPack\ResetDatabase trait in a KernelTestCase (or WebTestCase) before using a factory.');
    }

    /**
     * @return list<string> les EntityManagers, dans l'ordre de prism.yaml
     */
    public static function entityManagerNames(): array
    {
        /** @var list<string> */
        return self::container()->getParameter('prism.entity_manager_names');
    }

    public static function entityManager(string $name): EntityManager
    {
        if (!\in_array($name, self::entityManagerNames(), true)) {
            throw new TestPackException(\sprintf('Unknown entity manager "%s"; declared: %s.', $name, implode(', ', self::entityManagerNames())));
        }
        $em = self::container()->get('prism.entity_manager.' . $name);
        \assert($em instanceof EntityManager);

        return $em;
    }

    /**
     * L'EntityManager dont les entity_dirs contiennent la classe (isEntity() accepte toute classe #[Entity]).
     *
     * @param class-string $class
     */
    public static function entityManagerFor(string $class): EntityManager
    {
        foreach (self::entityManagerNames() as $name) {
            $em = self::entityManager($name);
            if (\in_array($class, $em->getMetadataFactory()->getAllClassNames(), true)) {
                return $em;
            }
        }

        throw new TestPackException(\sprintf('No entity manager maps %s; check its entity_dirs in prism.yaml.', $class));
    }
}
