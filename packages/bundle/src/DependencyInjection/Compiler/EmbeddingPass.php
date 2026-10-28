<?php

declare(strict_types=1);

namespace Prism\Bundle\DependencyInjection\Compiler;

use Prism\Bundle\Exception\InvalidConfigurationException;
use Prism\Bundle\Messenger\MessengerEmbeddingScheduler;
use Prism\Orm\Vector\DeferredEmbeddingScheduler;
use Prism\Orm\Vector\SynchronousEmbeddingScheduler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Les fournisseurs d'embeddings par nom (ADR 0018) : ceux de prism.embeddings.providers, puis les
 * services tagués prism.embedding_provider (nom de #[AsEmbeddingProvider], sinon id du service).
 * Avec au moins un fournisseur, la metadata de chaque EntityManager les vérifie au chargement.
 * Puis le scheduler de chaque EntityManager, selon prism.embeddings.scheduler.
 *
 * @internal
 */
final class EmbeddingPass implements CompilerPassInterface
{
    public const string TAG = 'prism.embedding_provider';

    /** nom → id de service, posé par le bundle */
    public const string PARAMETER = 'prism.embeddings.providers';

    public const string SCHEDULER = 'prism.embeddings.scheduler';

    public const string BUS = 'messenger.default_bus';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::PARAMETER) || !$container->hasParameter(ListenerPass::PARAMETER)) {
            return;
        }
        /** @var array<string, string> $providers */
        $providers = $container->getParameter(self::PARAMETER);
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            /** @var list<array<string, mixed>> $tags */
            $names = array_values(array_filter(array_map(static fn (array $tag): mixed => $tag['name'] ?? null, $tags), \is_string(...)));
            foreach ([] === $names ? [$id] : array_unique($names) as $name) {
                if (isset($providers[$name]) && $providers[$name] !== $id) {
                    throw new InvalidConfigurationException(\sprintf('Two embedding providers are named "%s": the services "%s" and "%s".', $name, $providers[$name], $id));
                }
                $providers[$name] = $id;
            }
        }
        $container->getDefinition('prism.embedding_providers')->setArgument(0, array_map(static fn (string $id): Reference => new Reference($id), $providers));

        /** @var list<string> $entityManagers */
        $entityManagers = $container->getParameter(ListenerPass::PARAMETER);
        /** @var array{string, int<1, max>} $scheduler mode et taille de lot */
        $scheduler = $container->getParameter(self::SCHEDULER);
        [$mode, $batchSize] = $scheduler;
        if ('messenger' === $mode && !$container->has(self::BUS)) {
            throw new InvalidConfigurationException('prism.embeddings.scheduler is "messenger", but there is no message bus: configure framework.messenger, or choose "synchronous" or "deferred".');
        }
        if ('auto' === $mode) {
            $mode = $container->has(self::BUS) ? 'messenger' : 'deferred';
        }
        foreach ($entityManagers as $name) {
            if ([] !== $providers) {
                $container->getDefinition('prism.metadata_factory.' . $name)->setArgument(3, new Reference('prism.embedding_providers'));
            }
            $container->setDefinition('prism.embedding_scheduler.' . $name, match ($mode) {
                'messenger' => new Definition(MessengerEmbeddingScheduler::class, [new Reference(self::BUS), $name, $batchSize]),
                'synchronous' => new Definition(SynchronousEmbeddingScheduler::class, [new Reference('prism.embedding_updater.' . $name)]),
                default => new Definition(DeferredEmbeddingScheduler::class),
            });
        }
    }
}
