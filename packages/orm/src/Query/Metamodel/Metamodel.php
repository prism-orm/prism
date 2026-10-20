<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

/**
 * Metamodel d'une entité, lié à un alias de requête (ADR 0009) : la classe générée Order_
 * expose chaque champ et chaque relation d'Order comme une propriété typée, et
 * Order_::as('o') la crée pour l'alias o.
 *
 * @template T of object
 *
 * @api
 */
abstract class Metamodel
{
    /** @var Association<Metamodel<object>>|null */
    private ?Association $via = null;

    /** @var class-string<T> */
    public readonly string $entity;

    public function __construct(public readonly string $alias)
    {
        $this->entity = static::entityClass();
    }

    /**
     * L'entité décrite.
     *
     * @return class-string<T>
     */
    abstract protected static function entityClass(): string;

    /**
     * La relation par laquelle ce metamodel est joint, null pour la racine d'une requête.
     *
     * @return Association<Metamodel<object>>|null
     */
    public function via(): ?Association
    {
        return $this->via;
    }

    /**
     * @param Association<Metamodel<object>> $relation
     *
     * @internal posé par Association::as()
     */
    public function joinedBy(Association $relation): void
    {
        $this->via = $relation;
    }
}
