<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\PersisterFixtures;

use Prism\Orm\Mapping as Prism;

// stratégie Auto : SEQUENCE sous PostgreSQL, IDENTITY ailleurs (RG-02.4)
#[Prism\Entity]
final class Label
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    public function __construct(
        #[Prism\Column(length: 20, unique: true)]
        public string $code,
    ) {
    }
}
