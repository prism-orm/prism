<?php

declare(strict_types=1);

namespace Prism\TestPack\Tests\Fixtures\Entity\Library;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Table('tp_book')]
final class Book
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    // écrite une fois, hors du constructeur
    #[Prism\Column(length: 40, nullable: true)]
    public readonly ?string $note;

    public function __construct(
        #[Prism\ManyToOne]
        public Author $author,
        #[Prism\Column(length: 120)]
        public string $title,
        #[Prism\Column(length: 13)]
        public readonly string $isbn = '0000000000000',
    ) {
    }
}
