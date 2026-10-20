<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Data\Generics;

use Prism\Orm\EntityManagerInterface;
use Prism\PHPStan\Tests\Fixtures\Dto\Summary;
use Prism\PHPStan\Tests\Fixtures\Invoice;
use Prism\PHPStan\Tests\Fixtures\Invoice_;

use function PHPStan\Testing\assertType;

// RG-05.6 : résultats typés par les seuls génériques
function results(EntityManagerInterface $em): void
{
    $i = Invoice_::as('i');
    $q = $em->query($i);

    assertType('list<' . Invoice::class . '>', $q->getResult());
    assertType(Invoice::class . '|null', $q->getOneOrNull());
    assertType('Prism\Orm\Query\Paginator<' . Invoice::class . '>', $q->orderBy($i->issuedAt->desc())->paginate(1));
    assertType('list<' . Summary::class . '>', $q->selectInto(Summary::class, reference: $i->reference, total: $i->total, count: $i->id->count())->getResult());
    assertType(Summary::class . '|null', $q->selectInto(Summary::class, reference: $i->reference, total: $i->total, count: $i->id->count())->getOneOrNull());
    assertType('Prism\Orm\Query\Expression\DecimalExpression', $i->total->sum());
    assertType('Prism\Orm\Query\Expression\IntExpression', $i->meta->path('n')->asInt());
}
