<?php

declare(strict_types=1);

// Généré par Prism (db:metamodel:generate) : ne pas modifier.

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch;

use Prism\Orm\Query\Metamodel as M;

/**
 * @extends M\Metamodel<\Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer>
 */
final class Customer_ extends M\Metamodel
{
    public readonly M\IntField $id;

    public readonly M\StringField $name;

    /** @var M\Relation<\Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Country_> */
    public readonly M\Relation $country;

    /** @var M\CollectionRelation<\Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Order_> */
    public readonly M\CollectionRelation $orders;

    /** @var M\CollectionRelation<\Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Group_> */
    public readonly M\CollectionRelation $groups;

    /** @var M\Relation<\Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Account_> */
    public readonly M\Relation $account;

    public function __construct(string $alias = 'customer')
    {
        parent::__construct($alias);
        $this->id = new M\IntField($this, 'id');
        $this->name = new M\StringField($this, 'name');
        $this->country = new M\Relation($this, 'country', \Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Country_::class);
        $this->orders = new M\CollectionRelation($this, 'orders', \Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Order_::class);
        $this->groups = new M\CollectionRelation($this, 'groups', \Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Group_::class);
        $this->account = new M\Relation($this, 'account', \Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Account_::class);
    }

    public static function as(string $alias): self
    {
        return new self($alias);
    }

    protected static function entityClass(): string
    {
        return \Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer::class;
    }
}
