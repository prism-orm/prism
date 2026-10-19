<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Hydrator\Fixtures;

use Prism\Orm\Mapping as Prism;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Address;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\FixtureStatus;

#[Prism\Entity]
#[Prism\HasLifecycleCallbacks]
final class Product
{
    public static int $constructed = 0;

    #[Prism\Id]
    public int $id = 0;

    #[Prism\Column(length: 80)]
    public string $name = '';

    #[Prism\Column(type: 'decimal', precision: 10, scale: 2)]
    public string $price = '0.00';

    #[Prism\Column]
    public FixtureStatus $status = FixtureStatus::Draft;

    #[Prism\Column]
    public ?\DateTimeImmutable $releasedAt = null;

    #[Prism\Column(type: 'binary', nullable: true)]
    public ?string $thumbnail = null;

    #[Prism\Embedded(columnPrefix: 'stock_')]
    public ?Address $warehouse = null;

    // état dérivé, non mappé (RG-02.7), initialisé par #[PostLoad] (RG-03.12)
    public string $label = '';

    public int $postLoads = 0;

    public function __construct(
        #[Prism\Column(length: 20)]
        public readonly string $sku = '',
    ) {
        ++self::$constructed;
    }

    #[Prism\PostLoad]
    public function deriveLabel(): void
    {
        ++$this->postLoads;
        $this->label = \sprintf('%s (%s)', $this->name, $this->sku);
    }
}
