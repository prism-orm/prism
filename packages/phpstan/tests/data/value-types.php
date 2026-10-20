<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Data\ValueTypes;

use Prism\PHPStan\Tests\Fixtures\Invoice_;
use Prism\PHPStan\Tests\Fixtures\Status;

// RG-05.3 : une expression n'accepte que des valeurs du type de son champ, sans extension
function conditions(): void
{
    $i = Invoice_::as('i');

    $i->status->eq(Status::Paid);
    $i->status->eq('paid');
    $i->total->gte('100.00');
    $i->total->gte(100.0);
    $i->issuedAt->lt(new \DateTimeImmutable());
    $i->issuedAt->lt(new \DateTime());
    $i->id->in([1, 2]);
    $i->id->in(['1']);
    $i->total->sum()->gt('10.00');
    $i->id->count()->gt('2');
    $i->meta->path('n')->asInt()->eq(3);
    $i->meta->path('n')->asString()->eq(3);
}
