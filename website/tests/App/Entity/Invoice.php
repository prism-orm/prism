<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvoiceRepository;
use Prism\Bundle\Validator\UniqueEntity;
use Prism\Orm\Mapping as Prism;

// le journal d'audit garde chaque émission et chaque paiement (RG-08.10)
#[Prism\Entity(repositoryClass: InvoiceRepository::class)]
#[Prism\Tenanted]
#[Prism\Auditable]
#[UniqueEntity(fields: ['number'])]
final class Invoice
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 20, unique: true)]
    public string $number;

    #[Prism\Column]
    public \DateTimeImmutable $issuedAt;

    #[Prism\Column(type: 'decimal', precision: 12, scale: 2)]
    public string $total;

    #[Prism\Column]
    public bool $paid;

    #[Prism\OneToOne(inversedBy: 'invoice')]
    public Order $order;
}
