<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

/**
 * Metamodel d'un embeddable (Address_) : ses champs appartiennent à l'entité qui l'embarque,
 * sous le chemin de l'embeddable (« billingAddress.city »).
 *
 * @api
 */
abstract class EmbeddableMetamodel
{
    /**
     * @param Metamodel<object> $owner
     */
    public function __construct(
        public readonly Metamodel $owner,
        public readonly string $path,
    ) {
    }
}
