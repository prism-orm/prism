<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Bundle\DependencyInjection\PrismConfiguration;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * prism.yaml (ADR 0013) : forme courte et forme complète, valeurs par défaut documentées, aucune
 * option inconnue acceptée (règles Symfony de la phase 7).
 */
final class PrismConfigurationTest extends TestCase
{
    /**
     * @param array<mixed> ...$configs
     *
     * @return array{default_entity_manager: string, connections: array<string, array{url: string}>, entity_managers: array<string, array{connection: string, entity_dirs: list<string>, batch_size: int, migrations: array{dir: ?string, namespace: ?string}}>, metamodel: array{dir: string}, connection_idle_ttl: int}
     */
    private static function process(array ...$configs): array
    {
        $tree = new TreeBuilder('prism', 'array');
        PrismConfiguration::define($tree->getRootNode());

        /** @var array{default_entity_manager: string, connections: array<string, array{url: string}>, entity_managers: array<string, array{connection: string, entity_dirs: list<string>, batch_size: int, migrations: array{dir: ?string, namespace: ?string}}>, metamodel: array{dir: string}, connection_idle_ttl: int} */
        return new Processor()->process($tree->buildTree(), $configs);
    }

    public function testShortFormIsOneConnectionAndOneEntityManager(): void
    {
        $config = self::process(['url' => 'sqlite:///:memory:?serverVersion=3.46.0']);

        self::assertSame('default', $config['default_entity_manager']);
        self::assertSame(['default' => ['url' => 'sqlite:///:memory:?serverVersion=3.46.0']], $config['connections']);
        self::assertSame([
            'default' => [
                'connection' => 'default',
                'entity_dirs' => ['%kernel.project_dir%/src/Entity'],
                'batch_size' => 100,
                'migrations' => ['dir' => null, 'namespace' => null],
            ],
        ], $config['entity_managers']);
        self::assertSame(['dir' => '%kernel.cache_dir%/prism/metamodel'], $config['metamodel']);
        self::assertSame(60, $config['connection_idle_ttl'], 'RG-07.5: a connection idle for a minute is checked');
    }

    public function testShortFormWithEntityDirsAndMigrations(): void
    {
        $config = self::process(['url' => 'sqlite:///:memory:', 'entity_dirs' => ['src/Model'], 'migrations' => ['dir' => 'db/migrations', 'namespace' => 'Db\Migrations']]);

        self::assertSame(['src/Model'], $config['entity_managers']['default']['entity_dirs']);
        self::assertSame(['dir' => 'db/migrations', 'namespace' => 'Db\Migrations'], $config['entity_managers']['default']['migrations']);
    }

    // RG-07.1 : le premier déclaré est celui par défaut
    public function testFullFormFirstEntityManagerIsTheDefault(): void
    {
        $config = self::process([
            'connections' => ['main' => ['url' => 'sqlite:///:memory:'], 'billing' => ['url' => 'sqlite:///:memory:']],
            'entity_managers' => [
                'main' => ['entity_dirs' => ['src/Entity']],
                'billing' => ['entity_dirs' => ['src/Billing'], 'batch_size' => 50],
                'archive' => ['connection' => 'billing', 'entity_dirs' => ['src/Archive']],
            ],
        ]);

        self::assertSame('main', $config['default_entity_manager']);
        // par défaut, la connexion du même nom
        self::assertSame(['main', 'billing', 'billing'], array_column($config['entity_managers'], 'connection'));
        self::assertSame(50, $config['entity_managers']['billing']['batch_size']);
    }

    public function testExplicitDefaultEntityManager(): void
    {
        $config = self::process([
            'default_entity_manager' => 'billing',
            'connections' => ['main' => ['url' => 'a'], 'billing' => ['url' => 'b']],
            'entity_managers' => ['main' => [], 'billing' => []],
        ]);

        self::assertSame('billing', $config['default_entity_manager']);
    }

    public function testOneConnectionServesEveryEntityManager(): void
    {
        $config = self::process(['connections' => ['db' => ['url' => 'a']], 'entity_managers' => ['main' => [], 'audit' => []]]);

        self::assertSame(['db', 'db'], array_column($config['entity_managers'], 'connection'));
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'nothing' => [[], 'The child config "connections" under "prism" must be configured'];
        yield 'unknown option' => [['url' => 'a', 'urls' => 'b'], 'Unrecognized option "urls" under "prism"'];
        yield 'unknown entity manager option' => [['connections' => ['a' => ['url' => 'a']], 'entity_managers' => ['a' => ['dirs' => []]]], 'Unrecognized option "dirs" under "prism.entity_managers.a"'];
        yield 'url and connections' => [['url' => 'a', 'connections' => ['b' => ['url' => 'b']]], 'Use either "prism.url" or "prism.connections", not both.'];
        yield 'entity_dirs and entity_managers' => [['url' => 'a', 'entity_dirs' => ['x'], 'entity_managers' => ['b' => []]], 'declare them in it'];
        yield 'empty url' => [['url' => ''], 'The path "prism.connections.default.url" cannot contain an empty value'];
        yield 'unknown connection' => [['connections' => ['a' => ['url' => 'a']], 'entity_managers' => ['b' => ['connection' => 'c']]], 'The entity manager "b" uses the connection "c", which is not declared; declared: a.'];
        yield 'ambiguous connection' => [['connections' => ['a' => ['url' => 'a'], 'b' => ['url' => 'b']], 'entity_managers' => ['c' => []]], 'The entity manager "c" uses the connection "c", which is not declared; declared: a, b.'];
        yield 'unknown default' => [['url' => 'a', 'default_entity_manager' => 'billing'], 'The default entity manager "billing" is not declared; declared: default.'];
        yield 'batch size zero' => [['connections' => ['a' => ['url' => 'a']], 'entity_managers' => ['a' => ['batch_size' => 0]]], 'Should be greater than or equal to 1'];
        yield 'negative idle ttl' => [['url' => 'a', 'connection_idle_ttl' => -1], 'Should be greater than or equal to 0'];
        yield 'no entity dirs' => [['connections' => ['a' => ['url' => 'a']], 'entity_managers' => ['a' => ['entity_dirs' => []]]], 'should have at least 1 element(s) defined'];
    }

    /**
     * @param array<mixed> $config
     */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationIsRefused(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        self::process($config);
    }
}
