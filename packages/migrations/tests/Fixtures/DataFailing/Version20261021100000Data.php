<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Fixtures\DataFailing;

use Prism\Migrations\DataMigration;
use Prism\Migrations\Tests\Fixtures\DataEntities\Customer;
use Prism\Orm\EntityManagerInterface;

// modifie, écrit, puis échoue : tout est annulé
final class Version20261021100000Data extends DataMigration
{
    public function run(EntityManagerInterface $em): void
    {
        foreach ($em->getRepository(Customer::class)->findBy([]) as $customer) {
            $customer->firstName = 'changed';
        }
        $em->flush();

        throw new \RuntimeException('Boom in the data migration.');
    }
}
