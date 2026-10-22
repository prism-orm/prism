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
}
