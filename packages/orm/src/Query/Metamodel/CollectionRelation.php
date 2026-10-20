<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

/**
 * Relation de collection (OneToMany, ManyToMany) : as() rend le metamodel d'un élément.
 *
 * @template M of Metamodel<object>
 *
 * @extends Association<M>
 *
 * @api
 */
final class CollectionRelation extends Association
{
}
