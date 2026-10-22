<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Controller;

use Prism\Bundle\Attribute\MapEntity;
use Prism\Bundle\Tests\Fixtures\Entity\Shop\Product;
use Prism\Orm\EntityManagerInterface;
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

    /**
     * De quoi remplir le panneau du profiler : un N+1 voulu, un chargement par lot, un flush.
     */
    public function report(EntityManagerInterface $em): Response
    {
        // N+1 : six requêtes identiques au lieu d'une
        for ($id = 1; $id <= 6; ++$id) {
            $em->find(Product::class, $id);
        }
        $em->clear();

        // les catégories de tous les produits en une requête (pilier 1)
        $names = [];
        foreach ($em->getRepository(Product::class)->findAll() as $product) {
            $names[] = $product->category?->name;
        }

        $product = $em->find(Product::class, 1);
        \assert($product instanceof Product);
        $product->stock += 7;
        $em->flush();

        return new Response(implode(',', $names));
    }

    public function byRef(#[MapEntity(mapping: ['ref' => 'ref'])] Product $product): Response
    {
        return new Response((string) $product->id);
    }
}
