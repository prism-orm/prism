<?php

declare(strict_types=1);

namespace Prism\Orm\UnitOfWork;

use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Exception\InvalidEntityStateException;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Persister\PersisterProvider;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Suit l'état des entités, détecte leurs changements et les écrit en une transaction.
 *
 * Phase 3, étape 1 : signature seule. Les scénarios de référence (tests/Unit/UnitOfWork)
 * décrivent le comportement attendu ; l'implémentation arrive à l'étape 5.
 *
 * @api
 */
final class UnitOfWork
{
    // étape 1 : dépendances déclarées, lues par l'implémentation de l'étape 5
    public function __construct(
        private readonly MetadataFactory $metadata,
        private readonly PersisterProvider $persisters,
        private readonly ConversionContext $conversion,
        private readonly TypeRegistry $types,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
    }

    /**
     * NEW → MANAGED, REMOVED → MANAGED (RG-03.2) ; sans effet sur une entité MANAGED.
     *
     * @throws InvalidEntityStateException entité DETACHED
     */
    public function persist(object $entity): void
    {
        throw self::notYet();
    }

    /**
     * MANAGED → REMOVED ; une entité persistée mais jamais écrite redevient NEW.
     *
     * @throws InvalidEntityStateException entité NEW ou DETACHED
     */
    public function remove(object $entity): void
    {
        throw self::notYet();
    }

    /**
     * MANAGED → DETACHED.
     *
     * @throws InvalidEntityStateException entité NEW ou REMOVED
     */
    public function detach(object $entity): void
    {
        throw self::notYet();
    }

    /**
     * Toutes les entités suivies deviennent DETACHED ; libère toutes les références (mode worker).
     */
    public function clear(): void
    {
        throw self::notYet();
    }

    /**
     * Vrai pour une entité MANAGED.
     */
    public function contains(object $entity): bool
    {
        throw self::notYet();
    }

    public function getEntityState(object $entity): EntityState
    {
        throw self::notYet();
    }

    /**
     * Entité gérée par son identifiant : la même instance tant que clear() n'est pas appelé
     * (RG-03.3) ; null si la ligne n'existe pas.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function find(string $class, mixed $id): ?object
    {
        throw self::notYet();
    }

    /**
     * Écrit tout en une transaction (RG-03.6) : INSERT dans l'ordre de persist(), UPDATE des
     * seules colonnes modifiées, DELETE en ordre inverse. En cas d'échec, rollback et l'état
     * d'avant le flush est rétabli, identifiants générés compris.
     *
     * Lève ReentrantFlushException depuis un listener (RG-03.7), OptimisticLockException sur
     * une version périmée (RG-03.9).
     */
    public function commit(): void
    {
        throw self::notYet();
    }

    private static function notYet(): \LogicException
    {
        return new \LogicException('UnitOfWork is implemented in phase 3, step 5; see the reference scenarios in tests/Unit/UnitOfWork.');
    }
}
