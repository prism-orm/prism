<?php

declare(strict_types=1);

// Généré par Prism (db:metamodel:generate) : ne pas modifier.

namespace Prism\PHPStan\Tests\Fixtures;

use Prism\Orm\Query\Metamodel as M;

/**
 * @extends M\Metamodel<\Prism\PHPStan\Tests\Fixtures\Contract>
 */
final class Contract_ extends M\Metamodel
{
    public readonly M\IntField $id;

    /** @var M\Relation<\Prism\PHPStan\Tests\Fixtures\Client_> */
    public readonly M\Relation $client;

    public function __construct(string $alias = 'contract')
    {
        parent::__construct($alias);
        $this->id = new M\IntField($this, 'id');
        $this->client = new M\Relation($this, 'client', \Prism\PHPStan\Tests\Fixtures\Client_::class);
    }

    public static function as(string $alias): self
    {
        return new self($alias);
    }

    protected static function entityClass(): string
    {
        return \Prism\PHPStan\Tests\Fixtures\Contract::class;
    }
}
