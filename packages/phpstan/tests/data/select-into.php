<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Data\SelectInto;

use Prism\Orm\EntityManagerInterface;
use Prism\PHPStan\Tests\Fixtures\Dto\Loose;
use Prism\PHPStan\Tests\Fixtures\Dto\Summary;
use Prism\PHPStan\Tests\Fixtures\Dto\Typed;
use Prism\PHPStan\Tests\Fixtures\Invoice_;

function valid(EntityManagerInterface $em): void
{
    $i = Invoice_::as('i');
    $q = $em->query($i);

    $q->selectInto(Summary::class, reference: $i->reference, total: $i->total, count: $i->id->count());
    $q->groupBy($i->reference, $i->total)->selectInto(Summary::class, reference: $i->reference, total: $i->total, count: $i->id->count(), average: $i->total->avg());
    $q->selectInto(Typed::class, status: $i->status, paidAt: $i->paidAt, issuedAt: $i->issuedAt, discount: $i->discount->sum(), city: $i->billing->city, zip: $i->shipping->zip, meta: $i->meta);
    $q->selectInto(Loose::class, anything: $i->id, other: $i->meta->path('a')->asInt());
    $q->selectInto(dto: Summary::class, reference: $i->reference, total: $i->total, count: $i->id->countDistinct());
}

function names(EntityManagerInterface $em): void
{
    $i = Invoice_::as('i');
    $q = $em->query($i);

    $q->selectInto(Summary::class, $i->reference, total: $i->total, count: $i->id->count());
    $q->selectInto(Summary::class, reference: $i->reference, total: $i->total, count: $i->id->count(), avg: $i->total->avg());
    $q->selectInto(Summary::class, reference: $i->reference, count: $i->id->count());
}

function types(EntityManagerInterface $em, Invoice_ $j): void
{
    $i = Invoice_::as('i');
    $q = $em->query($i);

    $q->selectInto(Summary::class, reference: $i->id, total: $i->total, count: $i->id->count());
    $q->selectInto(Summary::class, reference: $i->reference, total: $i->total->sum(), count: $i->id->count());
    $q->selectInto(Summary::class, reference: $i->reference, total: $i->total, count: $i->id->max());
    $q->selectInto(Summary::class, reference: $i->reference, total: $i->discount, count: $i->id->count());
    $q->selectInto(Typed::class, status: $i->reference, paidAt: $i->paidAt, issuedAt: $i->paidAt, discount: $i->discount, city: $i->shipping->city, zip: $i->shipping->zip, meta: $i->meta);
    $q->selectInto(Summary::class, reference: $i->meta->path('ref')->asString(), total: $i->meta->path('total')->asDecimal(), count: $i->meta->path('n')->asInt());
    $count = $i->id->max();
    $q->selectInto(Summary::class, reference: $i->reference, total: $i->total, count: $count);
}
