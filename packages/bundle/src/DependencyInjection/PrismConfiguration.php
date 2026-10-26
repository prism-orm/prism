<?php

declare(strict_types=1);

namespace Prism\Bundle\DependencyInjection;

use Prism\Bundle\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

/**
 * L'arbre de prism.yaml (ADR 0013) : forme courte (url, entity_dirs, migrations) convertie en
 * forme complète (connections, entity_managers). Une option inconnue est refusée.
 *
 * @internal
 */
final class PrismConfiguration
{
    public const string DEFAULT_URL = '%env(resolve:DATABASE_URL)%';

    private const array SHORT_KEYS = ['url', 'entity_dirs', 'migrations'];

    /**
     * @param ArrayNodeDefinition<TreeBuilder<'array'>> $root
     */
    public static function define(ArrayNodeDefinition $root): void
    {
        $root
            ->beforeNormalization()
                ->ifArray()
                ->then(self::expandShortForm(...))
            ->end()
            ->validate()
                ->always(self::resolve(...))
            ->end()
            ->children()
                ->scalarNode('default_entity_manager')
                    ->info('The entity manager behind EntityManagerInterface; the first declared one by default (RG-07.1).')
                    ->defaultNull()
                ->end()
                ->arrayNode('connections')
                    ->info('Named connections; the short form "url" declares the "default" one. Without either: url: %env(resolve:DATABASE_URL)%.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('url')
                                ->info('A Symfony-style database URL, with serverVersion (RG-00.2).')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('entity_managers')
                    ->info('Named entity managers; without any, a "default" one maps %kernel.project_dir%/src/Entity.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('connection')
                                ->info('The connection it uses; by default, the one with the same name.')
                                ->defaultNull()
                            ->end()
                            ->arrayNode('entity_dirs')
                                ->info('Directories of the entity classes.')
                                ->requiresAtLeastOneElement()
                                ->defaultValue(['%kernel.project_dir%/src/Entity'])
                                ->scalarPrototype()->cannotBeEmpty()->end()
                            ->end()
                            ->integerNode('batch_size')
                                ->info('Batch fetching: the most entities loaded by one IN query (RG-04.11).')
                                ->min(1)
                                ->defaultValue(100)
                            ->end()
                            ->arrayNode('migrations')
                                ->info('By default: migrations/ and App\Migrations for the default entity manager, migrations/<Name> and App\Migrations\<Name> for the others.')
                                ->addDefaultsIfNotSet()
                                ->children()
                                    ->scalarNode('dir')->defaultNull()->end()
                                    ->scalarNode('namespace')->defaultNull()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->integerNode('connection_idle_ttl')
                    ->info('Worker mode (RG-07.5): seconds of inactivity after which a connection is checked at the start of a request, and reconnected if it was lost; 0 checks it on every request.')
                    ->min(0)
                    ->defaultValue(60)
                ->end()
                ->arrayNode('tenancy')
                    ->info('Multi-tenancy (ADR 0014): the tenant column, the raw SQL guard and how a request finds its tenant.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('type')
                            ->info('Type of the tenant_id column of every #[Tenanted] entity (RG-08.1).')
                            ->values(['integer', 'bigint', 'uuid'])
                            ->defaultValue('integer')
                        ->end()
                        ->enumNode('guard')
                            ->info('Raw SQL on a tenanted table without tenant condition (RG-08.7): "exception", or "log" (critical); by default, exception in debug, log otherwise.')
                            ->values(['exception', 'log', null])
                            ->defaultNull()
                        ->end()
                        ->arrayNode('resolver')
                            ->info('How a request finds its tenant: one of header, subdomain, user; none: the application sets it on the TenantContext.')
                            ->children()
                                ->scalarNode('header')->info('Name of the header holding the tenant; only behind a gateway that sets it.')->cannotBeEmpty()->end()
                                ->scalarNode('subdomain')->info('Domain under which the subdomain names the tenant: acme.example.com under example.com.')->cannotBeEmpty()->end()
                                ->booleanNode('user')->info('The logged-in user, a TenantAwareUserInterface.')->end()
                            ->end()
                            ->validate()
                                ->ifTrue(static fn (array $resolver): bool => \count(array_filter($resolver, static fn (mixed $value): bool => null !== $value && false !== $value)) > 1)
                                ->thenInvalid('Choose one tenant resolver: header, subdomain or user.')
                            ->end()
                        ->end()
                        ->scalarNode('loader')
                            ->info('Id of a TenantLoader service turning the identifier read (header, subdomain) into the tenant id; without it, the identifier itself (an integer for integer and bigint).')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('metamodel')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('dir')
                            ->info('Directory of the generated metamodel (RG-05.2); each entity manager generates into <dir>/<name>.')
                            ->defaultValue('%kernel.cache_dir%/prism/metamodel')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * url, entity_dirs et migrations à la racine : la connexion et l'EntityManager « default ».
     *
     * @param array<mixed> $config
     *
     * @return array<mixed>
     */
    private static function expandShortForm(array $config): array
    {
        $short = array_intersect_key($config, array_flip(self::SHORT_KEYS));
        if ([] === $short) {
            return $config;
        }
        if (isset($config['connections']) && isset($short['url'])) {
            throw new InvalidConfigurationException('Use either "prism.url" or "prism.connections", not both.');
        }
        if (isset($config['entity_managers']) && (isset($short['entity_dirs']) || isset($short['migrations']))) {
            throw new InvalidConfigurationException('"prism.entity_dirs" and "prism.migrations" are the short form of one entity manager: with "prism.entity_managers", declare them in it.');
        }
        $config = array_diff_key($config, $short);
        if (isset($short['url'])) {
            $config['connections'] = ['default' => ['url' => $short['url']]];
        }
        if (isset($short['entity_dirs']) || isset($short['migrations'])) {
            $config['entity_managers'] = ['default' => array_intersect_key($short, ['entity_dirs' => true, 'migrations' => true])];
        }

        return $config;
    }

    /**
     * Un EntityManager par défaut s'il n'y en a aucun, ses connexions résolues, l'EntityManager
     * par défaut nommé.
     *
     * @param array{default_entity_manager: ?string, connections: array<string, array{url: string}>, entity_managers: array<string, array{connection: ?string, entity_dirs: list<string>, batch_size: int, migrations: array{dir: ?string, namespace: ?string}}>, metamodel: array{dir: string}, connection_idle_ttl: int, tenancy: array{type: string, guard: ?string, resolver: array{header?: string, subdomain?: string, user?: bool}, loader: ?string}} $config
     *
     * @return array{default_entity_manager: string, connections: array<string, array{url: string}>, entity_managers: array<string, array{connection: string, entity_dirs: list<string>, batch_size: int, migrations: array{dir: ?string, namespace: ?string}}>, metamodel: array{dir: string}, connection_idle_ttl: int, tenancy: array{type: string, guard: ?string, resolver: array{header?: string, subdomain?: string, user?: bool}, loader: ?string}}
     */
    private static function resolve(array $config): array
    {
        // sans url ni connections : DATABASE_URL, comme la recette Flex ; lue au premier usage seulement
        if ([] === $config['connections']) {
            $config['connections'] = ['default' => ['url' => self::DEFAULT_URL]];
        }
        $connections = array_keys($config['connections']);
        if ([] === $config['entity_managers']) {
            $config['entity_managers'] = ['default' => ['connection' => null, 'entity_dirs' => ['%kernel.project_dir%/src/Entity'], 'batch_size' => 100, 'migrations' => ['dir' => null, 'namespace' => null]]];
        }
        foreach ($config['entity_managers'] as $name => $em) {
            $connection = $em['connection'] ?? (\in_array($name, $connections, true) ? $name : (1 === \count($connections) ? $connections[0] : null));
            if (null === $connection || !\in_array($connection, $connections, true)) {
                throw new InvalidConfigurationException(\sprintf('The entity manager "%s" uses the connection "%s", which is not declared; declared: %s.', $name, $connection ?? $name, implode(', ', $connections)));
            }
            $config['entity_managers'][$name]['connection'] = $connection;
        }
        $names = array_keys($config['entity_managers']);
        $default = $config['default_entity_manager'] ?? $names[0];
        if (!\in_array($default, $names, true)) {
            throw new InvalidConfigurationException(\sprintf('The default entity manager "%s" is not declared; declared: %s.', $default, implode(', ', $names)));
        }
        $config['default_entity_manager'] = $default;

        return $config;
    }
}
