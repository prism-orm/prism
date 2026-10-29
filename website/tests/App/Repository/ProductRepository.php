<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use Prism\Orm\Repository\EntityRepository;

/**
 * @extends EntityRepository<Product>
 */
final class ProductRepository extends EntityRepository
{
}
