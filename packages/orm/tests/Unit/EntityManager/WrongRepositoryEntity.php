<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\EntityManager;

use Prism\Orm\Mapping as Prism;

// repositoryClass qui n'étend pas EntityRepository (ADR 0006)
#[Prism\Entity(repositoryClass: \ArrayObject::class)]
final class WrongRepositoryEntity
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;
}
