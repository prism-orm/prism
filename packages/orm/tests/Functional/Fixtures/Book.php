<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\Fixtures;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Index(fields: ['author', 'title'])]
final class Book
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 200)]
    public string $title = '';

    #[Prism\Column(type: 'decimal', precision: 8, scale: 2, default: '0.00')]
    public string $price = '0.00';

    #[Prism\ManyToOne(inversedBy: 'books', onDelete: Prism\OnDelete::SetNull)]
    public ?Author $author = null;

    // OneToOne : colonne de jointure unique
    #[Prism\OneToOne(targetEntity: self::class, joinColumn: 'sequel_id', onDelete: Prism\OnDelete::SetNull)]
    public ?Book $sequel = null;

    /** @var iterable<int, Label> */
    #[Prism\ManyToMany(targetEntity: Label::class, inversedBy: 'books')]
    public iterable $labels = [];
}
