<?php

declare(strict_types=1);

namespace Prism\Orm\UnitOfWork;

/**
 * Entités hydratées par une même requête (RG-04.9).
 *
 * @internal
 */
final class Cohort
{
    /** @var array<int, object> */
    private array $members = [];

    public function add(object $member): void
    {
        $this->members[spl_object_id($member)] = $member;
    }

    public function remove(object $member): void
    {
        unset($this->members[spl_object_id($member)]);
    }

    /**
     * @return list<object>
     */
    public function members(): array
    {
        return array_values($this->members);
    }
}
