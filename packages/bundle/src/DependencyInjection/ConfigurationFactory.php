<?php

declare(strict_types=1);

namespace Prism\Bundle\DependencyInjection;

use Prism\Dbal\Configuration;
use Prism\Dbal\QueryGuard;
use Prism\Dbal\QueryObserver;
use Prism\Dbal\Type\Type;
use Prism\Dbal\Type\TypeRegistry;

/**
 * La configuration DBAL d'une connexion : les types natifs et les services tagués prism.type ;
 * en dev, les observateurs du profiler (ADR 0008, ADR 0013) ; les gardes du tenant (ADR 0014).
 *
 * @internal
 */
final class ConfigurationFactory
{
    /**
     * @param iterable<Type>      $types
     * @param list<QueryObserver> $observers
     * @param list<QueryGuard>    $guards
     */
    public static function create(iterable $types, array $observers = [], array $guards = []): Configuration
    {
        return new Configuration(types: TypeRegistry::withDefaults()->register(...iterator_to_array($types, false)), observers: $observers, guards: $guards);
    }

    /**
     * Les types d'une connexion, sans la créer : la metadata n'a pas besoin de l'URL, qui n'est
     * lue qu'au premier usage de la connexion (DATABASE_URL absente au cache:clear).
     */
    public static function types(Configuration $configuration): TypeRegistry
    {
        return $configuration->types;
    }
}
