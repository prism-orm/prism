<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

/**
 * Un champ d'une entité dans une requête : son metamodel (donc son alias) et son chemin
 * (« total », « billingAddress.city »). La colonne est résolue par la ClassMetadata à la
 * compilation, jamais générée (ADR 0009).
 *
 * @api
 */
abstract class Field
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
