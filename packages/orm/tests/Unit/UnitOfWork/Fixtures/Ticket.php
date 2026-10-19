<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures;

use Prism\Orm\Mapping as Prism;

// SEQUENCE : identifiant obtenu avant l'INSERT
#[Prism\Entity]
final class Ticket
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Sequence)]
    public ?int $id = null;

    #[Prism\Column(length: 40)]
    public string $subject = '';

    #[Prism\Embedded(columnPrefix: 'contact_')]
    public ?Contact $contact = null;
}
