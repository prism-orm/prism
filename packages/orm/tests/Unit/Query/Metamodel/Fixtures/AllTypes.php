<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures;

use Prism\Orm\Mapping as Prism;

// un champ par famille de types (ADR 0009)
#[Prism\Entity]
final class AllTypes
{
    #[Prism\Id]
    public int $id = 0;

    #[Prism\Column(length: 10)]
    public string $string = '';

    #[Prism\Column(type: 'text')]
    public string $text = '';

    #[Prism\Column(type: 'uuid')]
    public string $uuid = '';

    #[Prism\Column(type: 'smallint')]
    public int $smallint = 0;

    #[Prism\Column(type: 'bigint')]
    public int $bigint = 0;

    #[Prism\Column(type: 'decimal', precision: 10, scale: 2)]
    public string $decimal = '0.00';

    #[Prism\Column]
    public float $float = 0.0;

    #[Prism\Column]
    public bool $bool = false;

    #[Prism\Column]
    public \DateTimeImmutable $datetime;

    #[Prism\Column(type: 'date_immutable')]
    public \DateTimeImmutable $date;

    #[Prism\Column(type: 'time_immutable')]
    public \DateTimeImmutable $time;

    /** @var array<string, mixed> */
    #[Prism\Column]
    public array $json = [];

    #[Prism\Column(type: 'binary', length: 16)]
    public string $binary = '';

    #[Prism\Column]
    public Priority $priority = Priority::Low;

    public function __construct()
    {
        $this->datetime = $this->date = $this->time = new \DateTimeImmutable('2026-10-20');
    }
}
