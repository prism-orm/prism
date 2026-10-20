<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\Fixtures;

use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Label
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 40, unique: true)]
    public string $name = '';

    /** @var Collection<int, Book> */
    #[Prism\ManyToMany(targetEntity: Book::class, mappedBy: 'labels')]
    public Collection $books;

    public function __construct()
    {
        $this->books = new ArrayCollection();
    }
}
