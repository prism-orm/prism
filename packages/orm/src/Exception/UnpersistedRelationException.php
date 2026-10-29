<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * Une entité NEW est atteinte par une relation sans cascade: persist (RG-04.2) ; levée au flush,
 * avant toute écriture.
 *
 * @api
 */
final class UnpersistedRelationException extends \LogicException implements OrmException
{
    private function __construct(string $message, public readonly object $entity)
    {
        parent::__construct($message);
    }

    /**
     * @param string $path relations suivies depuis une entité gérée : « Order::$customer → Customer::$referrer »
     */
    public static function through(object $entity, string $path): self
    {
        return new self(\sprintf('A new entity of class %s was found through %s, which does not cascade persist. Persist it explicitly, or add cascade: [Cascade::Persist] to the relation.', $entity::class, $path), $entity);
    }
}
