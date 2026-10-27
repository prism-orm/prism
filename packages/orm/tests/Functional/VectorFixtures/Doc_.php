<?php

declare(strict_types=1);

// Généré par Prism (db:metamodel:generate) : ne pas modifier.

namespace Prism\Orm\Tests\Functional\VectorFixtures;

use Prism\Orm\Query\Metamodel as M;

/**
 * @extends M\Metamodel<\Prism\Orm\Tests\Functional\VectorFixtures\Doc>
 */
final class Doc_ extends M\Metamodel
{
    public readonly M\IntField $id;

    public readonly M\StringField $title;

    public readonly M\MixedField $embedding;

    public readonly M\MixedField $draft;

    public readonly M\MixedField $raw;

    public function __construct(string $alias = 'doc')
    {
        parent::__construct($alias);
        $this->id = new M\IntField($this, 'id');
        $this->title = new M\StringField($this, 'title');
        $this->embedding = new M\MixedField($this, 'embedding');
        $this->draft = new M\MixedField($this, 'draft');
        $this->raw = new M\MixedField($this, 'raw');
    }

    public static function as(string $alias): self
    {
        return new self($alias);
    }

    protected static function entityClass(): string
    {
        return \Prism\Orm\Tests\Functional\VectorFixtures\Doc::class;
    }
}
