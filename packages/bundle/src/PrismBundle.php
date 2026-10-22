<?php

declare(strict_types=1);

namespace Prism\Bundle;

use Prism\Bundle\Command\EntityManagerCommand;
use Prism\Bundle\DependencyInjection\PrismConfiguration;
use Prism\Dbal\Connection;
use Prism\Dbal\DriverManager;
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
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Intégration Symfony de Prism (ADR 0013) : connexions et EntityManagers nommés depuis
 * prism.yaml, autowiring, commandes db:* avec --em, réinitialisation par kernel.reset.
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

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{default_entity_manager: string, connections: array<string, array{url: string}>, entity_managers: array<string, array{connection: string, entity_dirs: list<string>, batch_size: int, migrations: array{dir: ?string, namespace: ?string}}>, metamodel: array{dir: string}} $config */
        $debug = (bool) $builder->getParameter('kernel.debug');
        $default = $config['default_entity_manager'];

        foreach ($config['connections'] as $name => $connection) {
            $builder->setDefinition('prism.connection.' . $name, new Definition(Connection::class, [$connection['url']]))
                ->setFactory([DriverManager::class, 'fromUrl']);
            $builder->registerAliasForArgument('prism.connection.' . $name, Connection::class, $name . 'Connection');
        }

        $commands = [];
        foreach ($config['entity_managers'] as $name => $em) {
            $connection = new Reference('prism.connection.' . $em['connection']);
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

            $builder->setDefinition('prism.entity_manager.' . $name, new Definition(EntityManager::class, [
                $connection,
                new Reference('prism.metadata_factory.' . $name),
                new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                null,
                $em['batch_size'],
            ]))->addTag('kernel.reset', ['method' => 'clear']); // RG-07.5
            $builder->registerAliasForArgument('prism.entity_manager.' . $name, EntityManagerInterface::class, $name . 'EntityManager');

            $builder->setDefinition('prism.migrations_configuration.' . $name, new Definition(MigrationsConfiguration::class, [
                $em['migrations']['dir'] ?? '%kernel.project_dir%/migrations' . ($isDefault ? '' : '/' . $suffix),
                $em['migrations']['namespace'] ?? 'App\Migrations' . ($isDefault ? '' : '\\' . $suffix),
                '%kernel.environment%',
            ]));

            $arguments = [
                'connection' => $connection,
                'metadata' => new Reference('prism.metadata_factory.' . $name),
                'entity_manager' => new Reference('prism.entity_manager.' . $name),
                'migrations' => new Reference('prism.migrations_configuration.' . $name),
                'environment' => '%kernel.environment%',
                'metamodel' => $config['metamodel']['dir'] . ($isDefault ? '' : '/' . $suffix),
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
