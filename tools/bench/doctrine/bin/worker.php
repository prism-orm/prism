<?php

declare(strict_types=1);

/*
 * Mémoire en mode worker (ADR 0021) : N « requêtes » dans un même processus, chacune lit 20
 * commandes et leur client, en modifie une, flush() puis clear(), comme un worker FrankenPHP ou
 * Messenger après kernel.reset. La mémoire est relevée après un gc_collect_cycles(), des deux côtés.
 *
 *     php bin/worker.php prism|doctrine [requests]
 */

require \dirname(__DIR__) . '/vendor/autoload.php';

use PrismVsDoctrine\Doctrine;
use PrismVsDoctrine\Prism;
use PrismVsDoctrine\Setup;

$orm = $argv[1] ?? '';
$requests = (int) ($argv[2] ?? 10_000);
[$em, $class] = match ($orm) {
    'prism' => [Setup::prism(), Prism\PurchaseOrder::class],
    'doctrine' => [Setup::doctrine(), Doctrine\PurchaseOrder::class],
    default => throw new \InvalidArgumentException('Usage: php bin/worker.php prism|doctrine [requests]'),
};

$memory = static function (): int {
    gc_collect_cycles();

    return memory_get_usage();
};
$start = hrtime(true);
$atThousand = 0;
for ($i = 1; $i <= $requests; ++$i) {
    $first = (($i * 20) % Setup::ORDERS) + 1;
    $orders = $em->getRepository($class)->findBy(['id' => range($first, min($first + 19, Setup::ORDERS))]);
    foreach ($orders as $order) {
        \strlen($order->customer->name);
    }
    $orders[0]->status = 0 === $i % 2 ? 'open' : 'sent';
    $em->flush();
    $em->clear();
    if (1_000 === $i) {
        $atThousand = $memory();
    }
}
$seconds = (hrtime(true) - $start) / 1e9;
$atEnd = $memory();
printf("%-8s %6d requests in %5.1f s (%4.0f req/s) | memory after 1,000: %6.2f MB, after %d: %6.2f MB, growth %+7.1f KB\n", $orm, $requests, $seconds, $requests / $seconds, $atThousand / 1048576, $requests, $atEnd / 1048576, ($atEnd - $atThousand) / 1024);
