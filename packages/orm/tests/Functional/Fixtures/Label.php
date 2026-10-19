<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\Fixtures;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Label
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 40, unique: true)]
    public string $name = '';

    /** @var iterable<int, Book> */
    #[Prism\ManyToMany(targetEntity: Book::class, mappedBy: 'labels')]
    public iterable $books = [];
}
