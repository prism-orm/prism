<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Metadata\Fixtures;

use Prism\Orm\Mapping as Prism;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Address;

#[Prism\Entity]
#[Prism\Table(name: 'people')]
final class Person
{
    #[Prism\Id]
    public string $uuid = '';

    // préfixe déduit (home_), non nullable
    #[Prism\Embedded]
    public Address $home;

    // sans préfixe, nullable : colonnes forcément nullables (RG-02.5)
    #[Prism\Embedded(columnPrefix: false)]
    public ?Address $work = null;

    public function __construct(Address $home)
    {
        $this->home = $home;
    }
}
