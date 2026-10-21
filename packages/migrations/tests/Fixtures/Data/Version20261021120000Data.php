<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Fixtures\Data;

use Prism\Migrations\DataMigration;
use Prism\Orm\EntityManagerInterface;

// seule dans sa version, sans revert() : irréversible (RG-06.7)
final class Version20261021120000Data extends DataMigration
{
    public function run(EntityManagerInterface $em): void
    {
        $em->getConnection()->executeStatement('UPDATE dt_customer SET full_name = UPPER(full_name)');
    }
}
