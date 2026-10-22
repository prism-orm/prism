<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Repository;

use Prism\Bundle\Tests\Fixtures\Entity\Main\Customer;
use Prism\Orm\Repository\EntityRepository;

/**
 * @extends EntityRepository<Customer>
 */
final class CustomerRepository extends EntityRepository
{
    public function findOneByName(string $name): ?Customer
    {
        return $this->findOneBy(['name' => $name]);
    }
}
