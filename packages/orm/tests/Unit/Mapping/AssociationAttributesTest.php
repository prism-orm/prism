<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Mapping\Cascade;
use Prism\Orm\Mapping\Fetch;
use Prism\Orm\Mapping\JoinTable;
use Prism\Orm\Mapping\ManyToMany;
use Prism\Orm\Mapping\ManyToOne;
use Prism\Orm\Mapping\OnDelete;
use Prism\Orm\Mapping\OneToMany;
use Prism\Orm\Mapping\OneToOne;
use Prism\Orm\Mapping\Order;
use Prism\Orm\Mapping\OrderBy;

final class AssociationAttributesTest extends TestCase
{
    public function testManyToOneDefaults(): void
    {
        $relation = new ManyToOne();

        self::assertNull($relation->getTargetEntity());
        self::assertSame([], $relation->getCascade());
        self::assertSame(Fetch::Lazy, $relation->getFetch());
        self::assertSame(OnDelete::Restrict, $relation->onDelete);
        self::assertTrue($relation->batch);
        self::assertNull($relation->batchSize);
    }

    public function testCascadeIsDeduplicated(): void
    {
        $relation = new ManyToOne(cascade: [Cascade::Persist, Cascade::Persist, Cascade::Remove]);

        self::assertSame([Cascade::Persist, Cascade::Remove], $relation->getCascade());
    }

    public function testCascadeRejectsStrings(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('expected Cascade, got string');

        /* @phpstan-ignore argument.type (cas testé volontairement) */
        new ManyToOne(cascade: ['persist']);
    }

    public function testBatchSizeIsBounded(): void
    {
        $this->expectException(MappingException::class);

        new ManyToOne(batchSize: 5000);
    }

    public function testOneToOneCannotBeBothSides(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('cannot be combined with "inversedBy"');

        new OneToOne(mappedBy: 'profile', inversedBy: 'user');
    }

    public function testOneToOneInverseSideCannotDeclareJoinColumn(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('inverse side');

        new OneToOne(mappedBy: 'profile', joinColumn: 'profile_id');
    }

    public function testOneToOneOwningSide(): void
    {
        self::assertTrue((new OneToOne(inversedBy: 'user'))->isOwningSide());
        self::assertFalse((new OneToOne(mappedBy: 'profile'))->isOwningSide());
    }

    public function testOneToManyRequiresValidMappedBy(): void
    {
        $this->expectException(MappingException::class);

        new OneToMany(\stdClass::class, mappedBy: '');
    }

    public function testOneToManyOptions(): void
    {
        $relation = new OneToMany(\stdClass::class, mappedBy: 'invoice', orphanRemoval: true, indexBy: 'sku', fetch: Fetch::ExtraLazy);

        self::assertSame('invoice', $relation->mappedBy);
        self::assertTrue($relation->orphanRemoval);
        self::assertSame('sku', $relation->indexBy);
        self::assertSame(Fetch::ExtraLazy, $relation->getFetch());
    }

    public function testManyToManySides(): void
    {
        self::assertTrue((new ManyToMany(\stdClass::class, inversedBy: 'articles'))->isOwningSide());
        self::assertTrue((new ManyToMany(\stdClass::class))->isOwningSide());
        self::assertFalse((new ManyToMany(\stdClass::class, mappedBy: 'tags'))->isOwningSide());
    }

    public function testJoinTableDefaultsToCascade(): void
    {
        self::assertSame(OnDelete::Cascade, (new JoinTable())->onDelete);
    }

    public function testOrderByRequiresOrderEnum(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Order::Asc or Order::Desc');

        /* @phpstan-ignore argument.type (cas testé volontairement) */
        new OrderBy(['position' => 'ASC']);
    }

    public function testOrderByKeepsFieldOrder(): void
    {
        $orderBy = new OrderBy(['position' => Order::Asc, 'id' => Order::Desc]);

        self::assertSame(['position', 'id'], array_keys($orderBy->fields));
    }
}
