<?php

declare(strict_types=1);

// Généré par Prism (db:metamodel:generate) : ne pas modifier.

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch;

use Prism\Orm\Query\Metamodel as M;

/**
 * @extends M\Metamodel<\Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Country>
 */
final class Country_ extends M\Metamodel
{
    public readonly M\IntField $id;

    public readonly M\StringField $name;

    public function __construct(string $alias = 'country')
    {
        parent::__construct($alias);
        $this->id = new M\IntField($this, 'id');
        $this->name = new M\StringField($this, 'name');
    }

    public static function as(string $alias): self
    {
        return new self($alias);
    }

    protected static function entityClass(): string
    {
        return \Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Country::class;
    }
}
