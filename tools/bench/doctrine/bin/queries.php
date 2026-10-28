<?php

declare(strict_types=1);

/*
 * Le nombre de requêtes SQL de chaque scénario, par ORM (ADR 0021) : compté par un observateur de
 * Prism et par le middleware de journal de Doctrine DBAL, hors des mesures de durée. Les
 * transactions (BEGIN, COMMIT) ne sont comptées ni d'un côté ni de l'autre.
 */

require \dirname(__DIR__) . '/vendor/autoload.php';

use Prism\Dbal\QueryObserver;
use PrismVsDoctrine\Doctrine;
use PrismVsDoctrine\Prism;
use PrismVsDoctrine\Scenario;
use PrismVsDoctrine\Setup;

$prismCount = new class implements QueryObserver {
    public int $count = 0;

    public function queryExecuted(string $sql, array $params, float $durationMs): void
    {
        ++$this->count;
    }
};
$doctrineCount = 0;
$prism = Setup::prism($prismCount);
$doctrine = Setup::doctrine(static function () use (&$doctrineCount): void {
    ++$doctrineCount;
});

$count = static function (\Closure $scenario, \Closure $read): int {
    $before = $read();
    $scenario();

    return $read() - $before;
};
$readPrism = static fn (): int => $prismCount->count;
$readDoctrine = static function () use (&$doctrineCount): int {
    return $doctrineCount;
};

$rows = [
    'N+1: 100 orders, their customer and lines' => [
        $count(static fn () => Scenario::walk($prism->getRepository(Prism\PurchaseOrder::class)->findBy(['id' => Scenario::orderIds()])), $readPrism),
        $count(static fn () => Scenario::walk($doctrine->getRepository(Doctrine\PurchaseOrder::class)->findBy(['id' => Scenario::orderIds()])), $readDoctrine),
        $count(static function () use ($doctrine): void {
            $doctrine->clear();
            Scenario::walk(Scenario::doctrineJoin($doctrine));
        }, $readDoctrine),
    ],
    'Hydration: findAll() of 10,000 entities' => [
        $count(static fn () => $prism->getRepository(Prism\Item::class)->findAll(), $readPrism),
        $count(static fn () => $doctrine->getRepository(Doctrine\Item::class)->findAll(), $readDoctrine),
        null,
    ],
];

printf("%-45s %8s %10s %18s\n", 'Scenario (SQL queries)', 'Prism', 'Doctrine', 'Doctrine + join');
foreach ($rows as $label => [$p, $d, $j]) {
    printf("%-45s %8d %10d %18s\n", $label, $p, $d, null === $j ? '-' : (string) $j);
}
