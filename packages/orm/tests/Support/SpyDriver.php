<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Support;

use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\Driver\MetadataDriver;

/**
 * Compte les appels au driver : zéro appel signifie zéro lecture d'attributs.
 *
 * @internal
 */
final class SpyDriver implements MetadataDriver
{
    public int $calls = 0;

    public function __construct(private readonly MetadataDriver $driver)
    {
    }

    public function loadMetadata(string $class): ClassMetadata
    {
        ++$this->calls;

        return $this->driver->loadMetadata($class);
    }

    public function isEntity(string $class): bool
    {
        ++$this->calls;

        return $this->driver->isEntity($class);
    }

    public function getAllClassNames(): array
    {
        ++$this->calls;

        return $this->driver->getAllClassNames();
    }
}
