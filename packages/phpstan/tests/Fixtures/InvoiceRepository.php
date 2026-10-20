<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Fixtures;

use Prism\Orm\Repository\EntityRepository;

/**
 * @extends EntityRepository<Invoice>
 */
final class InvoiceRepository extends EntityRepository
{
    /**
     * @return list<Invoice>
     */
    public function findPaid(): array
    {
        return $this->findBy(['status' => Status::Paid]);
    }
}
