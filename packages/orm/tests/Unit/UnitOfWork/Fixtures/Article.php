<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures;

use Prism\Orm\Mapping as Prism;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Address;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\FixtureStatus;

#[Prism\Entity]
final class Article
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column(length: 200)]
    public string $title = '';

    #[Prism\Column(type: 'decimal', precision: 10, scale: 2)]
    public string $price = '0.00';

    #[Prism\Column]
    public ?\DateTimeImmutable $publishedAt = null;

    #[Prism\Column]
    public FixtureStatus $status = FixtureStatus::Draft;

    #[Prism\Embedded(columnPrefix: 'place_')]
    public ?Address $place = null;

    #[Prism\Column(updatable: false)]
    public string $createdBy = '';

    #[Prism\Column(insertable: false, updatable: false)]
    public ?string $computed = null;

    public function __construct(
        #[Prism\Column(length: 40)]
        public readonly string $slug = '',
    ) {
    }
}
