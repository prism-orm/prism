<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

/**
 * Une relation dans une requête : as() rend le metamodel de la cible, lié à un nouvel alias,
 * pour la joindre.
 *
 * @template M of Metamodel<object>
 *
 * @api
 */
abstract class Association
{
    /**
     * @param Metamodel<object> $owner
     * @param class-string<M>   $target classe metamodel de la cible
     */
    public function __construct(
        public readonly Metamodel $owner,
        public readonly string $path,
        public readonly string $target,
    ) {
    }

    /**
     * @return M
     */
    final public function as(string $alias): Metamodel
    {
        $metamodel = new $this->target($alias);
        $metamodel->joinedBy($this);

        return $metamodel;
    }
}
