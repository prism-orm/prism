<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use Prism\Orm\Repository\EntityRepository;

/**
 * @extends EntityRepository<Customer>
 */
final class CustomerRepository extends EntityRepository
{
}
