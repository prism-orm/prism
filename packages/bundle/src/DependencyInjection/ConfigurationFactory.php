<?php

declare(strict_types=1);

namespace Prism\Bundle\DependencyInjection;

use Prism\Dbal\Configuration;
use Prism\Dbal\Type\Type;
use Prism\Dbal\Type\TypeRegistry;

/**
 * La configuration DBAL des connexions : les types natifs et les services tagués prism.type.
 *
 * @internal
 */
final class ConfigurationFactory
{
    /**
     * @param iterable<Type> $types
     */
    public static function create(iterable $types): Configuration
    {
        return new Configuration(types: TypeRegistry::withDefaults()->register(...iterator_to_array($types, false)));
    }
}
