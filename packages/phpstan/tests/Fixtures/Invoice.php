<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Fixtures;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity(repositoryClass: InvoiceRepository::class)]
final class Invoice
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public int $id;

    #[Prism\Column(length: 20)]
    public string $reference;

    #[Prism\Column(type: 'decimal', precision: 12, scale: 2)]
    public string $total;

    #[Prism\Column]
    public ?float $discount;

    #[Prism\Column]
    public Status $status;

    #[Prism\Column]
    public \DateTimeImmutable $issuedAt;

    #[Prism\Column]
    public ?\DateTimeImmutable $paidAt;

    /** @var array<string, mixed> */
    #[Prism\Column]
    public array $meta;

    #[Prism\Embedded]
    public Address $billing;

    #[Prism\Embedded]
    public ?Address $shipping;

    #[Prism\ManyToOne(inversedBy: 'invoices')]
    public Client $client;
}
