<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Controller;

use Prism\Bundle\Attribute\MapEntity;
use Prism\Bundle\Tests\Fixtures\Entity\Shop\Product;
use Symfony\Component\HttpFoundation\Response;

final class ProductController
{
    public function show(Product $product): Response
    {
        return new Response($product->ref);
    }

    public function maybe(?Product $product): Response
    {
        return new Response(null === $product ? 'none' : $product->ref);
    }

    public function byRef(#[MapEntity(mapping: ['ref' => 'ref'])] Product $product): Response
    {
        return new Response((string) $product->id);
    }
}
