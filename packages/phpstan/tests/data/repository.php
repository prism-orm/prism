<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Data\Repository;

use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Repository\EntityRepository;
use Prism\PHPStan\Tests\Fixtures\Client;
use Prism\PHPStan\Tests\Fixtures\Invoice;
use Prism\PHPStan\Tests\Fixtures\InvoiceRepository;

use function PHPStan\Testing\assertType;

function types(EntityManagerInterface $em, EntityManager $concrete): void
{
    assertType(InvoiceRepository::class, $em->getRepository(Invoice::class));
    assertType(InvoiceRepository::class, $concrete->getRepository(Invoice::class));
    assertType('list<' . Invoice::class . '>', $em->getRepository(Invoice::class)->findPaid());
    assertType(EntityRepository::class . '<' . Client::class . '>', $em->getRepository(Client::class));
    assertType(Invoice::class . '|null', $em->getRepository(Invoice::class)->findOneBy([]));
}
