<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Support;

use Prism\Dbal\Connection;
use Prism\Orm\EntityManagerInterface;

/**
 * Ce qu'une application reçoit par autowiring (RG-07.1) : les services par défaut et les nommés.
 */
final readonly class AutowiredServices
{
    public function __construct(
        public EntityManagerInterface $entityManager,
        public EntityManagerInterface $billingEntityManager,
        public EntityManagerInterface $defaultEntityManager,
        public Connection $connection,
        public Connection $billingConnection,
    ) {
    }
}
