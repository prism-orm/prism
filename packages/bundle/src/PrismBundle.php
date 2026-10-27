<?php

declare(strict_types=1);

namespace Prism\Bundle;

use Prism\Bundle\Attribute\AsEmbeddingProvider;
use Prism\Bundle\Attribute\AsEntityListener;
use Prism\Bundle\Attribute\AsPrismListener;
use Prism\Bundle\Audit\ContextActorProvider;
use Prism\Bundle\Audit\RequestStackRequestIdProvider;
use Prism\Bundle\Command\EntityManagerCommand;
use Prism\Bundle\Debug\AuditDataCollector;
use Prism\Bundle\Debug\AuditRecorder;
use Prism\Bundle\Debug\FlushRecorder;
use Prism\Bundle\Debug\PrismDataCollector;
use Prism\Bundle\Debug\QueryRecorder;
use Prism\Bundle\Debug\TenantDataCollector;
use Prism\Bundle\Debug\TenantRecorder;
use Prism\Bundle\DependencyInjection\Compiler\EmbeddingPass;
use Prism\Bundle\DependencyInjection\Compiler\FilterPass;
use Prism\Bundle\DependencyInjection\Compiler\ListenerPass;
use Prism\Bundle\DependencyInjection\Compiler\MakerPass;
use Prism\Bundle\DependencyInjection\Compiler\RepositoryPass;
use Prism\Bundle\DependencyInjection\ConfigurationFactory;
use Prism\Bundle\DependencyInjection\PrismConfiguration;
use Prism\Bundle\Event\ContainerEntityListenerResolver;
use Prism\Bundle\Exception\InvalidConfigurationException;
use Prism\Bundle\Maker\MakeEntity;
use Prism\Bundle\Maker\MakeMigration;
use Prism\Bundle\Maker\MakePrismEntity;
use Prism\Bundle\Maker\MakePrismMigration;
use Prism\Bundle\Messenger\ComputeEmbedding;
use Prism\Bundle\Messenger\ComputeEmbeddingHandler;
use Prism\Bundle\Messenger\ResetMiddleware;
use Prism\Bundle\Metamodel\MetamodelRefresher;
use Prism\Bundle\Metamodel\PrismCacheWarmer;
use Prism\Bundle\Security\EntityUserProvider;
use Prism\Bundle\Security\EntityUserProviderFactory;
use Prism\Bundle\Tenancy\TenantResolver;
use Prism\Bundle\Validator\UniqueEntityValidator;
use Prism\Bundle\ValueResolver\EntityValueResolver;
use Prism\Bundle\Worker\ConnectionKeeper;
use Prism\Dbal\Configuration;
use Prism\Dbal\Connection;
use Prism\Dbal\DriverManager;
use Prism\Dbal\Type\Type;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Migrations\Console\Command as Migrations;
use Prism\Migrations\MigrationsConfiguration;
use Prism\Orm\Audit\RequestIdProvider;
use Prism\Orm\Behavior\ActorProvider;
use Prism\Orm\Console\Command as Orm;
use Prism\Orm\Debug\N1Detector;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Event\AuditRecordedEvent;
use Prism\Orm\Event\BatchLoadedEvent;
use Prism\Orm\Event\PostFlushEvent;
use Prism\Orm\Event\PostPersistEvent;
use Prism\Orm\Event\PostRemoveEvent;
use Prism\Orm\Event\PreUpdateEvent;
use Prism\Orm\Filter\EntityFilter;
use Prism\Orm\Filter\FilterCollection;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tenancy\StackTenantContext;
use Prism\Orm\Tenancy\TenantContext;
use Prism\Orm\Tenancy\TenantGuard;
use Prism\Orm\Tenancy\TenantGuardMode;
use Prism\Orm\Vector\DeferredEmbeddingScheduler;
use Prism\Orm\Vector\EmbeddingProvider;
use Prism\Orm\Vector\EmbeddingProviderRegistry;
use Prism\Orm\Vector\EmbeddingUpdater;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;
use Symfony\Bundle\SecurityBundle\DependencyInjection\SecurityExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Intégration Symfony de Prism (ADR 0013) : connexions et EntityManagers nommés depuis
 * prism.yaml, autowiring (repositories compris), types et listeners par tags, commandes db:*
 * avec --em, metamodel et cache warmers, réinitialisation par kernel.reset ; multi-tenancy :
 * contexte, résolution par requête, garde du SQL brut, filtres tagués prism.filter (ADR 0014) ;
 * embeddings : fournisseurs par nom, scheduler et handler de ComputeEmbedding (ADR 0018).
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
        'db:audit:purge' => [Orm\AuditPurgeCommand::class, ['connection', 'metadata', 'clock']],
        'db:embeddings:compute' => [Orm\EmbeddingsComputeCommand::class, ['connection', 'metadata', 'embedding_providers']],
        'db:embeddings:status' => [Orm\EmbeddingsStatusCommand::class, ['connection', 'metadata']],
        'db:metamodel:generate' => [Orm\MetamodelGenerateCommand::class, ['metadata', 'metamodel']],
        'db:migrations:generate' => [Migrations\GenerateCommand::class, ['migrations']],
        'db:migrations:diff' => [Migrations\DiffCommand::class, ['connection', 'metadata', 'migrations']],
        'db:migrations:migrate' => [Migrations\MigrateCommand::class, ['connection', 'migrations', 'entity_manager']],
        'db:migrations:execute' => [Migrations\ExecuteCommand::class, ['connection', 'migrations', 'entity_manager']],
        'db:migrations:status' => [Migrations\StatusCommand::class, ['connection', 'migrations']],
        'db:migrations:list' => [Migrations\ListCommand::class, ['connection', 'migrations']],
        'db:migrations:lint' => [Migrations\LintCommand::class, ['connection', 'migrations', 'metadata']],
    ];

    // prism-orm/embedding-openai, facultatif : nommé sans dépendance de paquet
    private const string OPENAI_PROVIDER = 'Prism\\Embedding\\OpenAi\\OpenAiCompatibleProvider';

    // RG-07.4 : au plus 1 000 requêtes enregistrées par requête HTTP, toutes comptées
    private const int RECORDED_QUERIES = 1000;

    /**
     * Ce que le profiler écoute sur le dispatcher de chaque EntityManager.
     */
    private const array RECORDED_EVENTS = [
        PostPersistEvent::class => 'onPostPersist',
        PreUpdateEvent::class => 'onPreUpdate',
        PostRemoveEvent::class => 'onPostRemove',
        PostFlushEvent::class => 'onPostFlush',
        BatchLoadedEvent::class => 'onBatchLoaded',
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        PrismConfiguration::define($definition->rootNode());
    }

    // vu dans build() : loadExtension() reçoit un conteneur sans les extensions des autres bundles
    private bool $doctrine = false;

    private bool $security = false;

    public function build(ContainerBuilder $container): void
    {
        $this->doctrine = $container->hasExtension('doctrine');
        $this->security = $container->hasExtension('security');
        $container->setParameter(MakerPass::PARAMETER, !$this->doctrine);
        $container->addCompilerPass(new RepositoryPass());
        $container->addCompilerPass(new ListenerPass());
        $container->addCompilerPass(new FilterPass());
        $container->addCompilerPass(new EmbeddingPass());
        // avant MakeCommandRegistrationPass de MakerBundle (priorité 10)
        $container->addCompilerPass(new MakerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 20);
        // le type « prism » des providers de security.yaml, si SecurityBundle est là
        if ($container->hasExtension('security')) {
            $security = $container->getExtension('security');
            \assert($security instanceof SecurityExtension);
            $security->addUserProviderFactory(new EntityUserProviderFactory());
        }
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
        /** @var array{default_entity_manager: string, connections: array<string, array{url: string}>, entity_managers: array<string, array{connection: string, entity_dirs: list<string>, batch_size: int, migrations: array{dir: ?string, namespace: ?string}}>, metamodel: array{dir: string}, connection_idle_ttl: int, tenancy: array{type: string, guard: ?string, resolver: array{header?: string, subdomain?: string, user?: bool}, loader: ?string}, embeddings: array{scheduler: string, batch_size: int<1, max>, providers: array<string, array{service: ?string, openai?: array{base_url: string, model: string, dimensions: int, api_key: ?string, send_dimensions: bool}}>}} $config */
        $debug = (bool) $builder->getParameter('kernel.debug');
        $default = $config['default_entity_manager'];

        $builder->registerForAutoconfiguration(Type::class)->addTag('prism.type');
        $builder->registerForAutoconfiguration(EntityFilter::class)->addTag(FilterPass::TAG);

        // ADR 0018 : les fournisseurs par nom, complétés par EmbeddingPass ; un scheduler par EntityManager
        $embeddings = $config['embeddings'];
        $builder->registerForAutoconfiguration(EmbeddingProvider::class)->addTag(EmbeddingPass::TAG);
        $builder->registerAttributeForAutoconfiguration(AsEmbeddingProvider::class, static function (ChildDefinition $definition, AsEmbeddingProvider $provider): void {
            $definition->addTag(EmbeddingPass::TAG, ['name' => $provider->name]);
        });
        $providers = [];
        foreach ($embeddings['providers'] as $providerName => $provider) {
            if (null !== $provider['service']) {
                $providers[$providerName] = $provider['service'];
                continue;
            }
            $openai = $provider['openai'] ?? throw new InvalidConfigurationException(\sprintf('The embedding provider "%s" needs "service" or "openai".', $providerName));
            if (!class_exists(self::OPENAI_PROVIDER)) {
                throw new InvalidConfigurationException(\sprintf('The embedding provider "%s" uses "openai": run composer require prism-orm/embedding-openai symfony/http-client.', $providerName));
            }
            $builder->setDefinition('prism.embedding_provider.' . $providerName, new Definition(self::OPENAI_PROVIDER, [
                new Reference('http_client'),
                $openai['base_url'],
                $openai['model'],
                $openai['dimensions'],
                $openai['api_key'],
                $embeddings['batch_size'],
                $openai['send_dimensions'],
            ]));
            $providers[$providerName] = 'prism.embedding_provider.' . $providerName;
        }
        $builder->setParameter(EmbeddingPass::PARAMETER, $providers);
        $builder->setParameter(EmbeddingPass::SCHEDULER, [$embeddings['scheduler'], $embeddings['batch_size']]);
        $builder->setDefinition('prism.embedding_providers', new Definition(EmbeddingProviderRegistry::class, [[]]));
        $builder->setAlias(EmbeddingProviderRegistry::class, 'prism.embedding_providers');

        // ADR 0014 : un tenant par requête, partagé par les EntityManagers, oublié à kernel.reset
        $tenancy = $config['tenancy'];
        $builder->setDefinition('prism.tenant_context', new Definition(StackTenantContext::class))->addTag('kernel.reset', ['method' => 'reset']);
        $builder->setAlias(TenantContext::class, 'prism.tenant_context');
        $builder->setAlias(StackTenantContext::class, 'prism.tenant_context');
        // RG-08.7 : exception en dev et en test, log critique en prod, sauf choix explicite
        $guardMode = 'log' === ($tenancy['guard'] ?? ($debug ? 'exception' : 'log')) ? TenantGuardMode::Log : TenantGuardMode::Exception;
        // ADR 0015 : auteur et requête des comportements et de l'audit ; une application les remplace par leur alias
        $builder->setDefinition('prism.actor_provider', new Definition(ContextActorProvider::class, [new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE)]))
            ->addTag('kernel.event_listener', ['event' => 'console.command', 'method' => 'onConsoleCommand'])
            ->addTag('kernel.reset', ['method' => 'reset']);
        $builder->setAlias(ActorProvider::class, 'prism.actor_provider');
        $builder->setDefinition('prism.request_id_provider', new Definition(RequestStackRequestIdProvider::class, [new Reference('request_stack')]));
        $builder->setAlias(RequestIdProvider::class, 'prism.request_id_provider');
        $clock = new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE);

        $guards = [];
        foreach ($config['entity_managers'] as $name => $em) {
            $guards[$em['connection']][] = new Definition(TenantGuard::class, [new Reference('prism.metadata_factory.' . $name), new Reference('prism.tenant_context'), $guardMode, new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)]);
        }
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
        $profiled = [];
        $configurations = [];
        foreach ($config['connections'] as $name => $connection) {
            $observers = [];
            // RG-07.4 et RG-04.16 : enregistreur et détecteur N+1 en dev seulement, absents en prod
            if ($debug) {
                $builder->setDefinition('prism.debug.queries.' . $name, new Definition(QueryRecorder::class, [self::RECORDED_QUERIES]))
                    ->addTag('kernel.reset', ['method' => 'reset']);
                $builder->setDefinition('prism.debug.n1_detector.' . $name, new Definition(N1Detector::class, [new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE)]))
                    ->addTag('kernel.reset', ['method' => 'reset']);
                $observers = $profiled[$name] = [new Reference('prism.debug.queries.' . $name), new Reference('prism.debug.n1_detector.' . $name)];
            }
            $builder->setDefinition('prism.dbal_configuration.' . $name, new Definition(Configuration::class, [new TaggedIteratorArgument('prism.type'), $observers, $guards[$name] ?? []]))
                ->setFactory([ConfigurationFactory::class, 'create']);
            $configuration = new Reference('prism.dbal_configuration.' . $name);
            $configurations[$name] = $configuration;
            $builder->setDefinition('prism.connection.' . $name, new Definition(Connection::class, [$connection['url'], $configuration]))
                ->setFactory([DriverManager::class, 'fromUrl']);
            $builder->registerAliasForArgument('prism.connection.' . $name, Connection::class, $name . 'Connection');
        }

        // les listeners d'entité marqués #[AsEntityListener], par classe
        $builder->setDefinition('prism.entity_listener_resolver', new Definition(ContainerEntityListenerResolver::class, [
            new ServiceLocatorArgument(new TaggedIteratorArgument('prism.entity_listener', needsIndexes: true)),
        ]));

        $commands = [];
        $metamodels = [];
        $flushRecorders = [];
        $auditRecorders = [];
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
            // les types de la configuration de base : celle de la connexion porte des gardes qui lisent cette metadata
            $builder->setDefinition('prism.metadata_factory.' . $name, new Definition(MetadataFactory::class, [
                new Definition(AttributeDriver::class, ['$directories' => $em['entity_dirs'], '$tenantType' => $tenancy['type']]),
                $cache,
                new Definition(TypeRegistry::class, [new Reference('prism.dbal_configuration')])->setFactory([ConfigurationFactory::class, 'types']),
                null, // fournisseurs d'embeddings, posés par EmbeddingPass
            ]));
            $builder->setDefinition('prism.filters.' . $name, new Definition(FilterCollection::class));
            // les filtres de l'EntityManager : celui du tenant compris (ADR 0018)
            $builder->setDefinition('prism.embedding_updater.' . $name, new Definition(EmbeddingUpdater::class, [
                $connection,
                $metadata,
                new Reference('prism.embedding_providers'),
                new Reference('prism.filters.' . $name),
                $embeddings['batch_size'],
            ]));
            $builder->setDefinition('prism.embedding_scheduler.' . $name, new Definition(DeferredEmbeddingScheduler::class));

            // un dispatcher par EntityManager : ses événements ne disent pas qui les émet (ADR 0013)
            $dispatcher = $builder->setDefinition('prism.event_dispatcher.' . $name, new Definition(EventDispatcher::class));
            if ($debug) {
                $recorder = 'prism.debug.flushes.' . $name;
                $builder->setDefinition($recorder, new Definition(FlushRecorder::class, [$metadata]))->addTag('kernel.reset', ['method' => 'reset']);
                foreach (self::RECORDED_EVENTS as $event => $method) {
                    $dispatcher->addMethodCall('addListener', [$event, [new ServiceClosureArgument(new Reference($recorder)), $method]]);
                }
                $flushRecorders[$name] = new Reference($recorder);
                $builder->setDefinition('prism.debug.audit.' . $name, new Definition(AuditRecorder::class, [$name]))->addTag('kernel.reset', ['method' => 'reset']);
                $dispatcher->addMethodCall('addListener', [AuditRecordedEvent::class, [new ServiceClosureArgument(new Reference('prism.debug.audit.' . $name)), 'onAuditRecorded']]);
                $auditRecorders[] = new Reference('prism.debug.audit.' . $name);
            }
            $builder->setDefinition('prism.entity_manager.' . $name, new Definition(EntityManager::class, [
                $connection,
                $metadata,
                new Reference('prism.event_dispatcher.' . $name),
                new Reference('prism.entity_listener_resolver'),
                $em['batch_size'],
                new Reference('prism.tenant_context'),
                new Reference('prism.filters.' . $name),
                $clock,
                new Reference(ActorProvider::class),
                new Reference(RequestIdProvider::class),
                new Reference('prism.embedding_scheduler.' . $name),
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
                'clock' => $clock,
                'embedding_providers' => new Reference('prism.embedding_providers'),
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
        $builder->setParameter('prism.connection_names', array_keys($config['connections']));

        // RG-05.1 : chargé au démarrage du bundle, généré au cache:warmup
        $builder->setDefinition('prism.metamodel_refresher', new Definition(MetamodelRefresher::class, [$metamodels]))->setPublic(true);
        $builder->setDefinition('prism.cache_warmer', new Definition(PrismCacheWarmer::class, [
            array_values(array_map(static fn (array $m): Reference => $m[0], $metamodels)),
            new Reference('prism.metamodel_refresher'),
            $debug,
        ]))->addTag('kernel.cache_warmer');

        // RG-08.5 : le tenant de chaque requête principale, avant le firewall, ou après lui pour l'utilisateur
        $resolver = null;
        foreach ([TenantResolver::HEADER, TenantResolver::SUBDOMAIN, TenantResolver::USER] as $strategy) {
            $source = $tenancy['resolver'][$strategy] ?? null;
            if (null === $source || false === $source) {
                continue;
            }
            if (TenantResolver::USER === $strategy && !$this->security) {
                throw new InvalidConfigurationException('The "user" tenant resolver reads the logged-in user: it needs SecurityBundle.');
            }
            $resolver = new Reference('prism.tenant_resolver');
            $builder->setDefinition('prism.tenant_resolver', new Definition(TenantResolver::class, [
                new Reference('prism.tenant_context'),
                $strategy,
                \is_string($source) ? $source : '',
                $tenancy['type'],
                null === $tenancy['loader'] ? null : new Reference($tenancy['loader']),
                TenantResolver::USER === $strategy ? new Reference('security.token_storage') : null,
            ]))
                ->addTag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => TenantResolver::USER === $strategy ? 7 : 64])
                ->addTag('kernel.reset', ['method' => 'reset']);
        }

        // les panneaux du profiler (dev seulement) ; retirés par le conteneur si le profiler est absent
        if ($debug) {
            $builder->setDefinition('prism.data_collector', new Definition(PrismDataCollector::class, [$profiled, $flushRecorders, self::RECORDED_QUERIES]))
                ->addTag('data_collector', ['template' => PrismDataCollector::getTemplate(), 'id' => 'prism', 'priority' => 250]);
            $builder->setDefinition('prism.audit_data_collector', new Definition(AuditDataCollector::class, [$auditRecorders]))
                ->addTag('data_collector', ['template' => AuditDataCollector::getTemplate(), 'id' => 'prism_audit', 'priority' => 248]);
            $builder->setDefinition('prism.debug.tenant', new Definition(TenantRecorder::class))->addTag('kernel.reset', ['method' => 'reset']);
            $builder->getDefinition('prism.tenant_context')->addMethodCall('addSwitchListener', [new Reference('prism.debug.tenant')]);
            $builder->setDefinition('prism.tenant_data_collector', new Definition(TenantDataCollector::class, [new Reference('prism.tenant_context'), new Reference('prism.debug.tenant'), $resolver]))
                ->addTag('data_collector', ['template' => TenantDataCollector::getTemplate(), 'id' => 'prism_tenant', 'priority' => 249]);
        }

        $entityManagers = array_combine(array_keys($config['entity_managers']), array_map(static fn (string $name): Reference => new Reference('prism.entity_manager.' . $name), array_keys($config['entity_managers'])));

        // RG-07.5 : en mode worker, aucune transaction ne survit à une requête, une connexion perdue se reconnecte
        $builder->setDefinition('prism.connection_keeper', new Definition(ConnectionKeeper::class, [
            array_combine(array_keys($config['connections']), array_map(static fn (string $name): Reference => new Reference('prism.connection.' . $name), array_keys($config['connections']))),
            $config['connection_idle_ttl'],
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))
            ->addTag('kernel.reset', ['method' => 'reset'])
            // avant le firewall, qui peut recharger l'utilisateur depuis la base
            ->addTag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 256]);

        if (interface_exists(MiddlewareInterface::class)) {
            // utilisé dans la config d'un bus : middleware: [prism_reset]
            $builder->setDefinition('messenger.middleware.prism_reset', new Definition(ResetMiddleware::class, [
                new Reference('prism.connection_keeper'),
                new IteratorArgument(array_values($entityManagers)),
            ]));
        }
        if (interface_exists(BatchHandlerInterface::class)) {
            // ADR 0018 : par lots de messages, dans le tenant des lignes
            $builder->setDefinition('prism.embeddings.compute_handler', new Definition(ComputeEmbeddingHandler::class, [
                new ServiceLocatorArgument(array_combine(array_keys($config['entity_managers']), array_map(static fn (string $name): Reference => new Reference('prism.embedding_updater.' . $name), array_keys($config['entity_managers'])))),
                new Reference('prism.tenant_context'),
                $embeddings['batch_size'],
            ]))->addTag('messenger.message_handler', ['handles' => ComputeEmbedding::class]);
        }
        if (class_exists(ConstraintValidator::class)) {
            $builder->setDefinition(UniqueEntityValidator::class, new Definition(UniqueEntityValidator::class, [
                new ServiceLocatorArgument($entityManagers),
                array_keys($config['entity_managers']),
            ]))->addTag('validator.constraint_validator');
        }
        if (interface_exists(UserProviderInterface::class)) {
            // gabarit des providers « prism », complété par EntityUserProviderFactory
            $builder->setDefinition('prism.security.user_provider', new Definition(EntityUserProvider::class, [
                new ServiceLocatorArgument($entityManagers),
                array_keys($config['entity_managers']),
                null,
                null,
                null,
            ]))->setAbstract(true);
        }

        // make:entity et make:migration (RG-07.7) ; à côté de DoctrineBundle : make:prism:*
        if (class_exists(AbstractMaker::class)) {
            $doctrine = $this->doctrine;
            $builder->setDefinition('prism.maker.entity', new Definition($doctrine ? MakePrismEntity::class : MakeEntity::class, [
                new Reference('maker.file_manager'),
                new Reference('prism.metamodel_refresher'),
            ]))->addTag('maker.command');
            $builder->setDefinition('prism.maker.migration', new Definition($doctrine ? MakePrismMigration::class : MakeMigration::class, [
                new ServiceLocatorArgument(array_combine(array_keys($entityManagers), array_map(static fn (string $name): Reference => new Reference(\sprintf('prism.command.%s.db:migrations:diff', $name)), array_keys($entityManagers)))),
                array_keys($config['entity_managers']),
                $default,
            ]))->addTag('maker.command');
        }

        // RG-07.3 : {id} → entité dans les contrôleurs, avant les resolvers de Symfony (comme Doctrine)
        $builder->setDefinition('prism.entity_value_resolver', new Definition(EntityValueResolver::class, [
            new ServiceLocatorArgument($entityManagers),
            array_keys($config['entity_managers']),
        ]))->addTag('controller.argument_value_resolver', ['priority' => 110, 'name' => EntityValueResolver::class]);

        // une commande de bin/console par nom, qui délègue à l'EntityManager de --em
        foreach ($commands as $command => $byEntityManager) {
            $builder->setDefinition('prism.command.' . $command, new Definition(EntityManagerCommand::class, [
                new ServiceLocatorArgument($byEntityManager),
                $default,
                array_keys($config['entity_managers']),
                new Reference('prism.tenant_context'),
            ]))->addTag('console.command', ['command' => $command]);
        }
    }
}
