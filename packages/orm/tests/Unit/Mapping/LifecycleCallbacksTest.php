<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Orm\Mapping;
use Prism\Orm\Mapping\LifecycleCallbackAttribute;
use Prism\Orm\Mapping\LifecycleEvent;

final class LifecycleCallbacksTest extends TestCase
{
    /**
     * @return iterable<string, array{LifecycleCallbackAttribute, LifecycleEvent}>
     */
    public static function callbacks(): iterable
    {
        yield 'prePersist' => [new Mapping\PrePersist(), LifecycleEvent::PrePersist];
        yield 'postPersist' => [new Mapping\PostPersist(), LifecycleEvent::PostPersist];
        yield 'preUpdate' => [new Mapping\PreUpdate(), LifecycleEvent::PreUpdate];
        yield 'postUpdate' => [new Mapping\PostUpdate(), LifecycleEvent::PostUpdate];
        yield 'preRemove' => [new Mapping\PreRemove(), LifecycleEvent::PreRemove];
        yield 'postRemove' => [new Mapping\PostRemove(), LifecycleEvent::PostRemove];
        yield 'postLoad' => [new Mapping\PostLoad(), LifecycleEvent::PostLoad];
    }

    #[DataProvider('callbacks')]
    public function testCallbackMapsToItsEvent(LifecycleCallbackAttribute $attribute, LifecycleEvent $event): void
    {
        self::assertSame($event, $attribute->getEvent());
    }

    public function testEveryLifecycleEventHasAnAttribute(): void
    {
        $covered = array_map(static fn (array $row): LifecycleEvent => $row[1], iterator_to_array(self::callbacks(), false));

        self::assertCount(\count(LifecycleEvent::cases()), $covered);
    }
}
