<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;
use Prism\Orm\Repository\EntityRepository;

/**
 * @extends EntityRepository<Invoice>
 */
final class InvoiceRepository extends EntityRepository
{
}
