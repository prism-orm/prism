<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use Prism\Orm\Repository\EntityRepository;

/**
 * @extends EntityRepository<Order>
 */
final class OrderRepository extends EntityRepository
{
}
