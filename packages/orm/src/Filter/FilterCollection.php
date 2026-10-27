<?php

declare(strict_types=1);

namespace Prism\Orm\Filter;

use Prism\Dbal\Query\Expression;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Metadata\ClassMetadata;

/**
 * Filtres d'un EntityManager, appliqués dans leur ordre d'ajout. Un filtre verrouillé (le
 * TenantFilter) ne se désactive pas : seul runAsSystem() l'écarte (ADR 0014).
 *
 * @api
 */
final class FilterCollection
{
    /** @var array<string, EntityFilter> */
    private array $filters = [];

    /** @var array<string, true> */
    private array $locked = [];

    /** @var array<string, true> */
    private array $disabled = [];

    public function add(string $name, EntityFilter $filter, bool $locked = false): void
    {
        if (isset($this->filters[$name])) {
            throw InvalidArgumentException::duplicateFilter($name);
        }
        $this->filters[$name] = $filter;
        if ($locked) {
            $this->locked[$name] = true;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->filters[$name]);
    }

    public function isEnabled(string $name): bool
    {
        return isset($this->filters[$name]) && !isset($this->disabled[$name]);
    }

    public function enable(string $name): void
    {
        $this->assertKnown($name);
        unset($this->disabled[$name]);
    }

    public function disable(string $name): void
    {
        $this->assertKnown($name);
        if (isset($this->locked[$name])) {
            throw InvalidArgumentException::lockedFilter($name);
        }
        $this->disabled[$name] = true;
    }

    /**
     * Conditions des filtres actifs pour cette table, réunies par AND ; null : aucune. $except
     * écarte des filtres non verrouillés pour une requête (->withDeleted()).
     *
     * @param ClassMetadata<object> $metadata
     * @param list<string>          $except
     */
    public function condition(ClassMetadata $metadata, FilterTarget $target, array $except = []): ?Expression
    {
        $conditions = [];
        foreach ($this->filters as $name => $filter) {
            if (\in_array($name, $except, true) && !isset($this->locked[$name])) {
                continue;
            }
            if (!isset($this->disabled[$name]) && null !== $condition = $filter->condition($metadata, $target)) {
                $conditions[] = $condition;
            }
        }

        return match (\count($conditions)) {
            0 => null,
            1 => $conditions[0],
            default => $target->expr()->and(...$conditions),
        };
    }

    private function assertKnown(string $name): void
    {
        if (!isset($this->filters[$name])) {
            throw InvalidArgumentException::unknownFilter($name, array_keys($this->filters));
        }
    }
}
