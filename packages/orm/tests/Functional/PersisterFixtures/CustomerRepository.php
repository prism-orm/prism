<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\PersisterFixtures;

use Prism\Dbal\Query\Order;
use Prism\Orm\Repository\EntityRepository;

// ADR 0006 : un repository métier étend EntityRepository
/**
 * @extends EntityRepository<Customer>
 */
final class CustomerRepository extends EntityRepository
{
    /**
     * @return list<Customer>
     */
    public function inCity(string $city): array
    {
        return $this->findBy(['city' => $city], ['name' => Order::Asc]);
    }

    // ADR 0006 : un repository métier atteint l'EntityManager et la metadata de sa classe
    public function moveTo(Customer $customer, string $city): void
    {
        $customer->city = $city;
        $this->getEntityManager()->flush();
    }

    public function tableName(): string
    {
        return $this->getClassMetadata()->tableName;
    }
}
