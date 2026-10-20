<?php

declare(strict_types=1);

// Généré par Prism (db:metamodel:generate) : ne pas modifier.

namespace Prism\PHPStan\Tests\Fixtures;

use Prism\Orm\Query\Metamodel as M;

final class Address_ extends M\EmbeddableMetamodel
{
    public readonly M\StringField $city;

    public readonly M\StringField $zip;

    /**
     * @param M\Metamodel<object> $owner
     */
    public function __construct(M\Metamodel $owner, string $path)
    {
        parent::__construct($owner, $path);
        $this->city = new M\StringField($owner, $path . '.city');
        $this->zip = new M\StringField($owner, $path . '.zip');
    }
}
