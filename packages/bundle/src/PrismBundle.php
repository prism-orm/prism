<?php

declare(strict_types=1);

namespace Prism\Bundle;

use Prism\Bundle\Attribute\AsEntityListener;
use Prism\Bundle\Attribute\AsPrismListener;
use Prism\Bundle\Command\EntityManagerCommand;
use Prism\Bundle\DependencyInjection\Compiler\ListenerPass;
use Prism\Bundle\DependencyInjection\Compiler\RepositoryPass;
use Prism\Bundle\DependencyInjection\ConfigurationFactory;
use Prism\Bundle\DependencyInjection\PrismConfiguration;
use Prism\Bundle\Event\ContainerEntityListenerResolver;
use Prism\Bundle\Metamodel\MetamodelRefresher;
use Prism\Bundle\Metamodel\PrismCacheWarmer;
use Prism\Dbal\Configuration;
use Prism\Dbal\Connection;
use Prism\Dbal\DriverManager;
use Prism\Dbal\Type\Type;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Migrations\Console\Command as Migrations;
use Prism\Migrations\MigrationsConfiguration;
use Prism\Orm\Console\Command as Orm;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Intégration Symfony de Prism (ADR 0013) : connexions et EntityManagers nommés depuis
 * prism.yaml, autowiring (repositories compris), types et listeners par tags, commandes db:*
 * avec --em, metamodel et cache warmers, réinitialisation par kernel.reset.
 *
 * @api
 */
final class PrismBundle extends AbstractBundle
{
    /**
     * Les commandes db:* et ce que leur constructeur reçoit, dans l'ordre (comme bin/prism).
     */
    private const array COMMANDS = [
        'db:database:create' => [Orm\DatabaseCreateCommand::class, ['connection']],
        'db:database:drop' => [Orm\DatabaseDropCommand::class, ['connection', 'environment']],
        'db:schema:create' => [Orm\SchemaCreateCommand::class, ['connection', 'metadata']],
        'db:schema:update' => [Orm\SchemaUpdateCommand::class, ['connection', 'metadata']],
        'db:schema:drop' => [Orm\SchemaDropCommand::class, ['connection', 'metadata']],
        'db:schema:validate' => [Orm\SchemaValidateCommand::class, ['connection', 'metadata']],
        'db:mapping:info' => [Orm\MappingInfoCommand::class, ['metadata']],
        'db:query:sql' => [Orm\QuerySqlCommand::class, ['connection']],
        'db:import' => [Orm\ImportCommand::class, ['connection']],
        'db:cache:clear-metadata' => [Orm\CacheClearMetadataCommand::class, ['metadata']],
        'db:metamodel:generate' => [Orm\MetamodelGenerateCommand::class, ['metadata', 'metamodel']],
        'db:migrations:generate' => [Migrations\GenerateCommand::class, ['migrations']],
        'db:migrations:diff' => [Migrations\DiffCommand::class, ['connection', 'metadata', 'migrations']],
        'db:migrations:migrate' => [Migrations\MigrateCommand::class, ['connection', 'migrations', 'entity_manager']],
        'db:migrations:execute' => [Migrations\ExecuteCommand::class, ['connection', 'migrations', 'entity_manager']],
        'db:migrations:status' => [Migrations\StatusCommand::class, ['connection', 'migrations']],
        'db:migrations:list' => [Migrations\ListCommand::class, ['connection', 'migrations']],
        'db:migrations:lint' => [Migrations\LintCommand::class, ['connection', 'migrations', 'metadata']],
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        PrismConfiguration::define($definition->rootNode());
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new RepositoryPass());
        $container->addCompilerPass(new ListenerPass());
    }

    // RG-05.1 : le metamodel se charge depuis son dossier ; en dev, il suit les entités
    public function boot(): void
    {
        if (null === $this->container || !$this->container->has('prism.metamodel_refresher')) {
            return;
        }
        $refresher = $this->container->get('prism.metamodel_refresher');
        \assert($refresher instanceof MetamodelRefresher);
        $refresher->registerAutoloaders();
        if (true === $this->container->getParameter('kernel.debug')) {
            $refresher->refreshIfStale();
        }
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{default_entity_manager: string, connections: array<string, array{url: string}>, entity_managers: array<string, array{connection: string, entity_dirs: list<string>, batch_size: int, migrations: array{dir: ?string, namespace: ?string}}>, metamodel: array{dir: string}} $config */
        $debug = (bool) $builder->getParameter('kernel.debug');
        $default = $config['default_entity_manager'];

        $builder->registerForAutoconfiguration(Type::class)->addTag('prism.type');
        $builder->registerAttributeForAutoconfiguration(AsEntityListener::class, static function (ChildDefinition $definition): void {
            $definition->addTag('prism.entity_listener');
        });
        $builder->registerAttributeForAutoconfiguration(AsPrismListener::class, static function (ChildDefinition $definition, AsPrismListener $listener, \Reflector $reflector): void {
            $definition->addTag(ListenerPass::TAG, array_filter([
                'event' => $listener->event,
                'method' => $listener->method ?? ($reflector instanceof \ReflectionMethod ? $reflector->getName() : null),
                'entityManager' => $listener->entityManager,
                'priority' => $listener->priority,
            ], static fn (mixed $value): bool => null !== $value));
        });

        // les types natifs et les services tagués prism.type, sur chaque connexion
        $builder->setDefinition('prism.dbal_configuration', new Definition(Configuration::class, [new TaggedIteratorArgument('prism.type')]))
            ->setFactory([ConfigurationFactory::class, 'create']);
        foreach ($config['connections'] as $name => $connection) {
            $builder->setDefinition('prism.connection.' . $name, new Definition(Connection::class, [$connection['url'], new Reference('prism.dbal_configuration')]))
                ->setFactory([DriverManager::class, 'fromUrl']);
            $builder->registerAliasForArgument('prism.connection.' . $name, Connection::class, $name . 'Connection');
        }

        // les listeners d'entité marqués #[AsEntityListener], par classe
        $builder->setDefinition('prism.entity_listener_resolver', new Definition(ContainerEntityListenerResolver::class, [
            new ServiceLocatorArgument(new TaggedIteratorArgument('prism.entity_listener', needsIndexes: true)),
        ]));

        $commands = [];
        $metamodels = [];
        foreach ($config['entity_managers'] as $name => $em) {
            $connection = new Reference('prism.connection.' . $em['connection']);
            $metadata = new Reference('prism.metadata_factory.' . $name);
            $isDefault = $name === $default;
            $suffix = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $name)));

            // en dev, les attributs sont relus à chaque requête ; en prod, mis en cache (phase 2)
            $cache = null;
            if (!$debug) {
                $builder->setDefinition('prism.metadata_cache.' . $name, new ChildDefinition('cache.adapter.system'))->addTag('cache.pool');
                $cache = new Reference('prism.metadata_cache.' . $name);
            }
            $builder->setDefinition('prism.metadata_factory.' . $name, new Definition(MetadataFactory::class, [
                new Definition(AttributeDriver::class, [$em['entity_dirs']]),
                $cache,
                new Definition(TypeRegistry::class)->setFactory([$connection, 'getTypeRegistry']),
            ]));

            // un dispatcher par EntityManager : ses événements ne disent pas qui les émet (ADR 0013)
            $builder->setDefinition('prism.event_dispatcher.' . $name, new Definition(EventDispatcher::class));
            $builder->setDefinition('prism.entity_manager.' . $name, new Definition(EntityManager::class, [
                $connection,
                $metadata,
                new Reference('prism.event_dispatcher.' . $name),
                new Reference('prism.entity_listener_resolver'),
                $em['batch_size'],
            ]))->addTag('kernel.reset', ['method' => 'clear']); // RG-07.5
            $builder->registerAliasForArgument('prism.entity_manager.' . $name, EntityManagerInterface::class, $name . 'EntityManager');

            $builder->setDefinition('prism.migrations_configuration.' . $name, new Definition(MigrationsConfiguration::class, [
                $em['migrations']['dir'] ?? '%kernel.project_dir%/migrations' . ($isDefault ? '' : '/' . $suffix),
                $em['migrations']['namespace'] ?? 'App\Migrations' . ($isDefault ? '' : '\\' . $suffix),
                '%kernel.environment%',
            ]));

            // un dossier par EntityManager, jamais imbriqués : le générateur supprime ce qu'il n'a pas produit dans le sien
            $metamodel = $config['metamodel']['dir'] . '/' . $name;
            $metamodels[$name] = [$metadata, $metamodel, $em['entity_dirs']];

            $arguments = [
                'connection' => $connection,
                'metadata' => $metadata,
                'entity_manager' => new Reference('prism.entity_manager.' . $name),
                'migrations' => new Reference('prism.migrations_configuration.' . $name),
                'environment' => '%kernel.environment%',
                'metamodel' => $metamodel,
            ];
            foreach (self::COMMANDS as $command => [$class, $needs]) {
                $id = \sprintf('prism.command.%s.%s', $name, $command);
                $builder->setDefinition($id, new Definition($class, array_map(static fn (string $need): mixed => $arguments[$need], $needs)));
                $commands[$command][$name] = new Reference($id);
            }
        }

        $builder->setAlias(Connection::class, 'prism.connection.' . $config['entity_managers'][$default]['connection']);
        $builder->setAlias(EntityManagerInterface::class, 'prism.entity_manager.' . $default);
        $builder->setAlias('prism.entity_manager', 'prism.entity_manager.' . $default);
        $builder->setParameter(RepositoryPass::PARAMETER, array_map(static fn (array $em): array => $em['entity_dirs'], $config['entity_managers']));
        $builder->setParameter(ListenerPass::PARAMETER, array_keys($config['entity_managers']));

        // RG-05.1 : chargé au démarrage du bundle, généré au cache:warmup
        $builder->setDefinition('prism.metamodel_refresher', new Definition(MetamodelRefresher::class, [$metamodels]))->setPublic(true);
        $builder->setDefinition('prism.cache_warmer', new Definition(PrismCacheWarmer::class, [
            array_values(array_map(static fn (array $m): Reference => $m[0], $metamodels)),
            new Reference('prism.metamodel_refresher'),
            $debug,
        ]))->addTag('kernel.cache_warmer');

        // une commande de bin/console par nom, qui délègue à l'EntityManager de --em
        foreach ($commands as $command => $byEntityManager) {
            $builder->setDefinition('prism.command.' . $command, new Definition(EntityManagerCommand::class, [
                new ServiceLocatorArgument($byEntityManager),
                $default,
                array_keys($config['entity_managers']),
            ]))->addTag('console.command', ['command' => $command]);
        }
    }
}
