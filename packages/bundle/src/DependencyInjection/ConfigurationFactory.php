<?php

declare(strict_types=1);

namespace Prism\Bundle\DependencyInjection;

use Prism\Dbal\Configuration;
use Prism\Dbal\QueryObserver;
use Prism\Dbal\Type\Type;
use Prism\Dbal\Type\TypeRegistry;

/**
 * La configuration DBAL d'une connexion : les types natifs et les services tagués prism.type ;
 * en dev, les observateurs du profiler (ADR 0008, ADR 0013).
 *
 * @internal
 */
final class ConfigurationFactory
{
    /**
     * @param iterable<Type>      $types
     * @param list<QueryObserver> $observers
     */
    public static function create(iterable $types, array $observers = []): Configuration
    {
        return new Configuration(types: TypeRegistry::withDefaults()->register(...iterator_to_array($types, false)), observers: $observers);
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
