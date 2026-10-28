<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Detection;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Bag
{
    #[Prism\Id]
    public int $id = 0;

    #[Prism\Column(type: 'tags')]
    public Tags $tags;

    #[Prism\Column]
    public \DateTimeImmutable $packedAt;

    #[Prism\Column(type: 'decimal', precision: 8, scale: 2)]
    public string $weight = '0.00';

    /** @var array<string, mixed> */
    #[Prism\Column(type: 'json')]
    public array $meta = [];
}
