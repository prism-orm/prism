<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\Fixtures;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Author
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 100)]
    public string $name = '';

    /** @var iterable<int, Book> */
    #[Prism\OneToMany(targetEntity: Book::class, mappedBy: 'author')]
    public iterable $books = [];
}
