<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Metadata\Fixtures;

use Prism\Orm\Mapping as Prism;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\FixtureStatus;

/**
 * Une propriété par règle de déduction (RG-02.1, RG-02.3, RG-02.6, RG-02.7).
 */
#[Prism\Entity]
final class Inferred
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column]
    public string $name = '';

    #[Prism\Column]
    public ?string $nickname = null;

    #[Prism\Column]
    public int $quantity = 0;

    #[Prism\Column]
    public bool $active = true;

    #[Prism\Column]
    public float $ratio = 1.0;

    #[Prism\Column]
    public \DateTimeImmutable $createdAt;

    /** @var list<string> */
    #[Prism\Column]
    public array $tags = [];

    #[Prism\Column]
    public FixtureStatus $status = FixtureStatus::Draft;

    #[Prism\Column(default: Priority::Low)]
    public Priority $priority = Priority::Low;

    #[Prism\Column(type: 'text', name: 'biography')]
    public string $bio = '';

    #[Prism\Column(type: 'decimal', precision: 10, scale: 2, unique: true, sensitive: true)]
    public string $amount = '0.00';

    #[Prism\Column(length: 3, insertable: false, updatable: false)]
    public string $generated = '';

    #[Prism\Version]
    public int $version = 1;

    // RG-02.7 : sans attribut, jamais touchée
    public string $cache = '';

    #[Prism\Column]
    public static string $ignoredStatic = '';

    public function __construct(
        #[Prism\Column(length: 12)]
        public readonly string $code = '',
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }
}
