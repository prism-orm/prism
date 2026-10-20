<?php

declare(strict_types=1);

// Généré par Prism (db:metamodel:generate) : ne pas modifier.

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch;

use Prism\Orm\Query\Metamodel as M;

/**
 * @extends M\Metamodel<\Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Order>
 */
final class Order_ extends M\Metamodel
{
    public readonly M\IntField $id;

    public readonly M\StringField $reference;

    /** @var M\Relation<\Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer_> */
    public readonly M\Relation $customer;

    /** @var M\Relation<\Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Seller_> */
    public readonly M\Relation $seller;

    /** @var M\Relation<\Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Depot_> */
    public readonly M\Relation $depot;

    public function __construct(string $alias = 'order')
    {
        parent::__construct($alias);
        $this->id = new M\IntField($this, 'id');
        $this->reference = new M\StringField($this, 'reference');
        $this->customer = new M\Relation($this, 'customer', \Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer_::class);
        $this->seller = new M\Relation($this, 'seller', \Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Seller_::class);
        $this->depot = new M\Relation($this, 'depot', \Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Depot_::class);
    }

    public static function as(string $alias): self
    {
        return new self($alias);
    }

    protected static function entityClass(): string
    {
        return \Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Order::class;
    }
}
