<?php

declare(strict_types=1);

// Généré par Prism (db:metamodel:generate) : ne pas modifier.

namespace Prism\Orm\Tests\Unit\Tenancy\Fixtures;

use Prism\Orm\Query\Metamodel as M;

/**
 * @extends M\Metamodel<\Prism\Orm\Tests\Unit\Tenancy\Fixtures\Invoice>
 */
final class Invoice_ extends M\Metamodel
{
    public readonly M\IntField $id;

    public readonly M\StringField $number;

    public readonly M\IntField $amount;

    /** @var M\CollectionRelation<\Prism\Orm\Tests\Unit\Tenancy\Fixtures\Line_> */
    public readonly M\CollectionRelation $lines;

    /** @var M\CollectionRelation<\Prism\Orm\Tests\Unit\Tenancy\Fixtures\Tag_> */
    public readonly M\CollectionRelation $tags;

    /** @var M\Relation<\Prism\Orm\Tests\Unit\Tenancy\Fixtures\Client_> */
    public readonly M\Relation $client;

    /** @var M\Relation<\Prism\Orm\Tests\Unit\Tenancy\Fixtures\Currency_> */
    public readonly M\Relation $currency;

    public function __construct(string $alias = 'invoice')
    {
        parent::__construct($alias);
        $this->id = new M\IntField($this, 'id');
        $this->number = new M\StringField($this, 'number');
        $this->amount = new M\IntField($this, 'amount');
        $this->lines = new M\CollectionRelation($this, 'lines', \Prism\Orm\Tests\Unit\Tenancy\Fixtures\Line_::class);
        $this->tags = new M\CollectionRelation($this, 'tags', \Prism\Orm\Tests\Unit\Tenancy\Fixtures\Tag_::class);
        $this->client = new M\Relation($this, 'client', \Prism\Orm\Tests\Unit\Tenancy\Fixtures\Client_::class);
        $this->currency = new M\Relation($this, 'currency', \Prism\Orm\Tests\Unit\Tenancy\Fixtures\Currency_::class);
    }

    public static function as(string $alias): self
    {
        return new self($alias);
    }

    protected static function entityClass(): string
    {
        return \Prism\Orm\Tests\Unit\Tenancy\Fixtures\Invoice::class;
    }
}
