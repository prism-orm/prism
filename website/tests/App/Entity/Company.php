<?php

declare(strict_types=1);

namespace App\Entity;

use Prism\Orm\Mapping as Prism;

/**
 * Une société cliente de l'ERP : le tenant (pilier 3). Non tenantée : c'est le registre des
 * tenants, lu par le CompanyLoader avant que la requête ait le sien.
 */
#[Prism\Entity]
final class Company
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    // le sous-domaine : acme.localhost
    #[Prism\Column(length: 40, unique: true)]
    public string $slug;

    #[Prism\Column(length: 120)]
    public string $name;
}
