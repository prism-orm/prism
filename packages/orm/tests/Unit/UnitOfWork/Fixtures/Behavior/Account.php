<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior;

use Prism\Orm\Mapping as Prism;

// journal d'audit : iban masqué (RG-08.11), note jamais journalisée, conservé un jour
#[Prism\Entity]
#[Prism\Tenanted]
#[Prism\SoftDeletable]
#[Prism\Auditable(exclude: ['note'], retention: 'P1D')]
final class Account
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column(length: 34, sensitive: true)]
    public string $iban = '';

    #[Prism\Column(length: 80)]
    public string $note = '';

    #[Prism\Column]
    public ?\DateTimeImmutable $deletedAt = null;

    #[Prism\ManyToOne]
    public ?Page $page = null;

    public function __construct(
        #[Prism\Column(length: 40)]
        public string $owner = '',
    ) {
    }
}
