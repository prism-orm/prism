<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Fixtures\Data;

use Prism\Migrations\DataMigration;
use Prism\Migrations\Tests\Fixtures\DataEntities\Customer;
use Prism\Orm\EntityManagerInterface;

// RG-06.8 : le prénom tiré du nom complet, par l'EntityManager
final class Version20261021110000Data extends DataMigration
{
    public function getDescription(): string
    {
        return 'First names from full names';
    }

    public function run(EntityManagerInterface $em): void
    {
        foreach ($em->getRepository(Customer::class)->findBy([]) as $customer) {
            $customer->firstName = explode(' ', $customer->fullName)[0];
        }
    }

    public function revert(EntityManagerInterface $em): void
    {
        foreach ($em->getRepository(Customer::class)->findBy([]) as $customer) {
            $customer->firstName = null;
        }
    }
}
