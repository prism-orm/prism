<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\PersisterFixtures;

use Prism\Orm\Mapping as Prism;

// RG-01.3 : table et colonne aux noms réservés
#[Prism\Entity]
#[Prism\Table(name: 'order')]
final class ReservedOrder
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column(name: 'group', length: 20)]
    public string $group = '';

    #[Prism\Column(type: 'binary', length: 16, nullable: true)]
    public ?string $payload = null;
}
