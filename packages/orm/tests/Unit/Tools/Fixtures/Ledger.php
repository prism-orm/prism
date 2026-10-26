<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tools\Fixtures;

use Prism\Orm\Mapping as Prism;

// colonne du tenant nommée et portée par une propriété ; un index qui la nomme reste tel quel
#[Prism\Entity]
#[Prism\Tenanted(column: 'company_id')]
#[Prism\Index(fields: ['code', 'companyId'])]
#[Prism\Index(fields: ['label'])]
final class Ledger
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column]
    public int $companyId;

    #[Prism\Column(length: 20)]
    public string $code = '';

    #[Prism\Column(length: 80)]
    public string $label = '';
}
