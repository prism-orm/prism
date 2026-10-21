<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Fixtures\Entities\V2;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Table('df_client')]
final class Client
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 80)]
    public string $name = '';

    #[Prism\Column(length: 120)]
    public string $email = '';

    #[Prism\Column(length: 20)]
    public ?string $phone = null;
}
