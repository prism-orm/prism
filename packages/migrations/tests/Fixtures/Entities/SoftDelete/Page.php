<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Fixtures\Entities\SoftDelete;

use Prism\Orm\Mapping as Prism;

// RG-08.15 : l'unicité du slug ne porte que sur les pages non supprimées ; auditée, donc avec prism_audit_log
#[Prism\Entity]
#[Prism\Table(name: 'df_page')]
#[Prism\SoftDeletable]
#[Prism\Auditable]
final class Page
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 40, unique: true)]
    public string $slug = '';

    #[Prism\Column]
    public ?\DateTimeImmutable $deletedAt = null;
}
