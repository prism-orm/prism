<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Une ligne du rapport « chiffre d'affaires par client » : projection selectInto(), hors de
 * l'UnitOfWork (RG-05.6).
 */
final readonly class CustomerRevenue
{
    public function __construct(
        public string $customer,
        public int $invoices,
        public string $total,
        public ?\DateTimeImmutable $lastInvoice,
    ) {
    }
}
