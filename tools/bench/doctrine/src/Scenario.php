<?php

declare(strict_types=1);

namespace PrismVsDoctrine;

use Doctrine\ORM\EntityManager as DoctrineEntityManager;

/**
 * Le code applicatif du scénario N+1, le même pour les deux ORM.
 */
final class Scenario
{
    public const int ORDERS = 100;

    private function __construct()
    {
    }

    /**
     * @return list<int>
     */
    public static function orderIds(int $first = 1, int $count = self::ORDERS): array
    {
        return range($first, $first + $count - 1);
    }

    /**
     * Le client et les lignes de chaque commande : ce qu'affiche une liste de commandes.
     *
     * @param iterable<Prism\PurchaseOrder|Doctrine\PurchaseOrder> $orders
     */
    public static function walk(iterable $orders): int
    {
        $sum = 0;
        $count = 0;
        foreach ($orders as $order) {
            $sum += \strlen($order->customer->name);
            foreach ($order->lines as $line) {
                $sum += $line->quantity;
            }
            ++$count;
        }
        if (self::ORDERS !== $count) {
            throw new \LogicException(\sprintf('%d orders read, %d expected.', $count, self::ORDERS));
        }

        return $sum;
    }

    /**
     * Doctrine optimisé à la main : une jointure qui charge le client et les lignes.
     *
     * @return list<Doctrine\PurchaseOrder>
     */
    public static function doctrineJoin(DoctrineEntityManager $em): array
    {
        /** @var list<Doctrine\PurchaseOrder> */
        return $em->createQuery('SELECT o, c, l FROM ' . Doctrine\PurchaseOrder::class . ' o JOIN o.customer c JOIN o.lines l WHERE o.id IN (:ids)')
            ->setParameter('ids', self::orderIds())
            ->getResult();
    }
}
