<?php

declare(strict_types=1);

// Généré par Prism (db:metamodel:generate) : ne pas modifier.

namespace Prism\Orm\Tests\Unit\Tenancy\Fixtures;

use Prism\Orm\Query\Metamodel as M;

/**
 * @extends M\Metamodel<\Prism\Orm\Tests\Unit\Tenancy\Fixtures\Currency>
 */
final class Currency_ extends M\Metamodel
{
    public readonly M\IntField $id;

    public readonly M\StringField $code;

    public function __construct(string $alias = 'currency')
    {
        parent::__construct($alias);
        $this->id = new M\IntField($this, 'id');
        $this->code = new M\StringField($this, 'code');
    }

    public static function as(string $alias): self
    {
        return new self($alias);
    }

    protected static function entityClass(): string
    {
        return \Prism\Orm\Tests\Unit\Tenancy\Fixtures\Currency::class;
    }
}
