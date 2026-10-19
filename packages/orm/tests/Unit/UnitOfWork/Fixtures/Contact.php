<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures;

use Prism\Orm\Mapping as Prism;

// embeddable mutable, modifié en place
#[Prism\Embeddable]
final class Contact
{
    #[Prism\Column(length: 80)]
    public ?string $email = null;
}
