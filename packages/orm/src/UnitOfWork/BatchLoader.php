<?php

declare(strict_types=1);

namespace Prism\Orm\UnitOfWork;

use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Metadata\AssociationMapping;

/**
 * Pilier 1, anti-N+1 (RG-04.9 à RG-04.14) : décide ce qui se charge ensemble. Quand une relation
 * d'un membre de cohorte se charge, la même relation se charge pour les autres membres, par lot
 * de batchSize ; l'UnitOfWork exécute les requêtes.
 *
 * - Cible ToOne : chaque référence (ghost) garde la cohorte et la relation qui l'ont créée ; la
 *   dernière gagne, c'est le parcours en cours.
 * - Collection : les membres de la cohorte du propriétaire dont la collection n'est pas chargée.
 * - batch: false sur la relation : rien n'est regroupé (RG-04.13).
 *
 * forget() retire une entité détachée de sa cohorte : une page d'iterate() est libérée avec elle
 * (RG-04.14).
 *
 * @internal
 */
final class BatchLoader
{
    /** @var array<int, Cohort> cohorte de chaque membre */
    private array $cohorts = [];

    /** @var array<int, array{Cohort, AssociationMapping}> cohorte et relation qui ont créé chaque référence */
    private array $origins = [];

    /**
     * @throws InvalidArgumentException taille inférieure à 1
     */
    public function __construct(public readonly int $batchSize = 100)
    {
        if ($batchSize < 1) {
            throw InvalidArgumentException::batchSize($batchSize);
        }
    }

    public function join(Cohort $cohort, object $member): void
    {
        ($this->cohorts[spl_object_id($member)] ?? null)?->remove($member);
        $cohort->add($member);
        $this->cohorts[spl_object_id($member)] = $cohort;
    }

    /**
     * $target, encore à charger, est la cible de $association pour $owner.
     */
    public function referenced(object $target, object $owner, AssociationMapping $association): void
    {
        $cohort = $this->cohorts[spl_object_id($owner)] ?? null;
        if (null !== $cohort) {
            $this->origins[spl_object_id($target)] = [$cohort, $association];
        }
    }

    /**
     * Les cibles à charger avec $target : celles de la même relation pour les autres membres de
     * sa cohorte, encore à charger, dans la limite du lot ; $target d'abord.
     *
     * @param \Closure(object, AssociationMapping): mixed $targetOf cible de la relation pour un membre
     * @param \Closure(object): bool                      $pending  vrai pour une cible encore à charger
     *
     * @return non-empty-list<object>
     */
    public function targetsWith(object $target, \Closure $targetOf, \Closure $pending): array
    {
        [$cohort, $association] = $this->origins[spl_object_id($target)] ?? [null, null];
        if (null === $cohort || null === $association || !$association->batch) {
            return [$target];
        }

        $size = $association->batchSize ?? $this->batchSize;
        $batch = [spl_object_id($target) => $target];
        foreach ($cohort->members() as $member) {
            if (\count($batch) >= $size) {
                break;
            }
            $other = $targetOf($member, $association);
            if (\is_object($other) && !isset($batch[spl_object_id($other)]) && $pending($other)) {
                $batch[spl_object_id($other)] = $other;
            }
        }

        return array_values($batch);
    }

    /**
     * Les propriétaires dont la collection $association se charge avec celle de $owner : les
     * membres de sa cohorte qui ne l'ont pas encore chargée, dans la limite du lot ; $owner d'abord.
     *
     * @param \Closure(object): bool $pending vrai pour un membre dont la collection est à charger
     *
     * @return non-empty-list<object>
     */
    public function ownersWith(object $owner, AssociationMapping $association, \Closure $pending): array
    {
        $cohort = $this->cohorts[spl_object_id($owner)] ?? null;
        if (null === $cohort || !$association->batch) {
            return [$owner];
        }

        $size = $association->batchSize ?? $this->batchSize;
        $batch = [spl_object_id($owner) => $owner];
        foreach ($cohort->members() as $member) {
            if (\count($batch) >= $size) {
                break;
            }
            if (!isset($batch[spl_object_id($member)]) && $pending($member)) {
                $batch[spl_object_id($member)] = $member;
            }
        }

        return array_values($batch);
    }

    public function forget(object $entity): void
    {
        $oid = spl_object_id($entity);
        ($this->cohorts[$oid] ?? null)?->remove($entity);
        unset($this->cohorts[$oid], $this->origins[$oid]);
    }

    public function clear(): void
    {
        $this->cohorts = [];
        $this->origins = [];
    }
}
