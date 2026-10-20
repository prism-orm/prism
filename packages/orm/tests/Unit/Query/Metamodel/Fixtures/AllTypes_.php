<?php

declare(strict_types=1);

// Généré par Prism (db:metamodel:generate) : ne pas modifier.

namespace Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures;

use Prism\Orm\Query\Metamodel as M;

/**
 * @extends M\Metamodel<\Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\AllTypes>
 */
final class AllTypes_ extends M\Metamodel
{
    public readonly M\IntField $id;

    public readonly M\StringField $string;

    public readonly M\StringField $text;

    public readonly M\StringField $uuid;

    public readonly M\IntField $smallint;

    public readonly M\IntField $bigint;

    public readonly M\DecimalField $decimal;

    public readonly M\FloatField $float;

    public readonly M\BoolField $bool;

    public readonly M\DateTimeField $datetime;

    public readonly M\DateTimeField $date;

    public readonly M\DateTimeField $time;

    public readonly M\JsonField $json;

    public readonly M\BinaryField $binary;

    /** @var M\EnumField<\Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\Priority> */
    public readonly M\EnumField $priority;

    public function __construct(string $alias = 'allTypes')
    {
        parent::__construct($alias);
        $this->id = new M\IntField($this, 'id');
        $this->string = new M\StringField($this, 'string');
        $this->text = new M\StringField($this, 'text');
        $this->uuid = new M\StringField($this, 'uuid');
        $this->smallint = new M\IntField($this, 'smallint');
        $this->bigint = new M\IntField($this, 'bigint');
        $this->decimal = new M\DecimalField($this, 'decimal');
        $this->float = new M\FloatField($this, 'float');
        $this->bool = new M\BoolField($this, 'bool');
        $this->datetime = new M\DateTimeField($this, 'datetime');
        $this->date = new M\DateTimeField($this, 'date');
        $this->time = new M\DateTimeField($this, 'time');
        $this->json = new M\JsonField($this, 'json');
        $this->binary = new M\BinaryField($this, 'binary');
        $this->priority = new M\EnumField($this, 'priority', \Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\Priority::class);
    }

    public static function as(string $alias): self
    {
        return new self($alias);
    }

    protected static function entityClass(): string
    {
        return \Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\AllTypes::class;
    }
}
