<?php

declare(strict_types=1);

namespace Prism\TestPack\Tests\Fixtures\Entity\Library;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Table('tp_author')]
final class Author
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 120, nullable: true)]
    public ?string $email = null;

    // écrite par le constructeur : une factory ne peut pas la remplacer
    #[Prism\Column(length: 80)]
    public readonly string $slug;

    public function __construct(
        #[Prism\Column(length: 80)]
        public string $name,
    ) {
        $this->slug = strtolower(str_replace(' ', '-', $name));
    }
}
