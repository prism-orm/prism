<?php

declare(strict_types=1);

// Généré par Prism (db:metamodel:generate) : ne pas modifier.

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch;

use Prism\Orm\Query\Metamodel as M;

/**
 * @extends M\Metamodel<\Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Account>
 */
final class Account_ extends M\Metamodel
{
    public readonly M\IntField $id;

    public readonly M\IntField $balance;

    /** @var M\Relation<\Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer_> */
    public readonly M\Relation $customer;

    public function __construct(string $alias = 'account')
    {
        parent::__construct($alias);
        $this->id = new M\IntField($this, 'id');
        $this->balance = new M\IntField($this, 'balance');
        $this->customer = new M\Relation($this, 'customer', \Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer_::class);
    }

    public static function as(string $alias): self
    {
        return new self($alias);
    }

    protected static function entityClass(): string
    {
        return \Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Account::class;
    }
}
