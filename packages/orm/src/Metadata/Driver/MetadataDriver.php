<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata\Driver;

use Prism\Orm\Exception\MappingException;
use Prism\Orm\Metadata\ClassMetadata;

/**
 * Source des metadata. Seuls les attributs sont supportés (ADR 0002) ; l'interface permet à la
 * MetadataFactory de mesurer, par un driver espion, qu'aucune lecture n'a lieu après échauffement.
 *
 * @api
 */
interface MetadataDriver
{
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return ClassMetadata<T>
     *
     * @throws MappingException
     */
    public function loadMetadata(string $class): ClassMetadata;

    /**
     * @param class-string $class
     */
    public function isEntity(string $class): bool;

    /**
     * @return list<class-string> entités trouvées dans les sources configurées
     */
    public function getAllClassNames(): array;
}
