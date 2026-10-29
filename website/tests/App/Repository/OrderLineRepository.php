<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrderLine;
use Prism\Orm\Repository\EntityRepository;

/**
 * @extends EntityRepository<OrderLine>
 */
final class OrderLineRepository extends EntityRepository
{
}
