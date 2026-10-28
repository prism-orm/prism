<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Mapping\InvalidFixtures;

use Prism\Orm\Mapping as Prism;

/**
 * Entité au mapping invalide : son type n'est pas enregistré. Sert aux tests qui vérifient
 * qu'un mapping invalide est refusé (warm-up, SchemaTool, db:schema:validate).
 */
#[Prism\Entity]
final class UnknownTypeEntity
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(type: 'money')]
    public string $balance = '0';
}
