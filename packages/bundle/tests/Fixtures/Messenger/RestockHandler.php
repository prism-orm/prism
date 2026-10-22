<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Messenger;

use Prism\Bundle\Tests\Fixtures\Entity\Shop\Product;
use Prism\Orm\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

// un handler maladroit : il laisse une entité persistée sans flush et une transaction ouverte
#[AsMessageHandler]
final readonly class RestockHandler
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function __invoke(Restock $message): void
    {
        $product = new Product();
        $product->ref = $message->ref;
        $this->em->persist($product);
        $this->em->getConnection()->beginTransaction();
        if ($message->fail) {
            throw new \RuntimeException('Restock failed.');
        }
    }
}
