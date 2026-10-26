<?php

declare(strict_types=1);

/*
 * Critère de sortie de la phase 8 : surcoût du filtre tenant < 5 % sur la lecture.
 *
 * La même lecture de 10 000 entités, sans tenant puis tenantée (filtre et garde du SQL brut),
 * alternées dans un même processus, médiane de 31 mesures chacune : un sujet PHPBench tourne dans
 * ses propres processus, et sur une machine partagée l'écart entre deux sujets n'est que du bruit.
 *
 *     make bench-tenancy [DB=mariadb|pgsql]
 */

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

use Prism\Benchmarks\Entity\BenchItem;
use Prism\Benchmarks\Entity\TenantBenchItem;
use Prism\Benchmarks\Support\BenchDatabase;
use Prism\Dbal\Configuration;
use Prism\Dbal\DriverManager;
use Prism\Orm\EntityManager;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tenancy\StackTenantContext;
use Prism\Orm\Tenancy\TenantGuard;

const DATABASE = 'prism_bench_tenancy_overhead';
const ROWS = 10_000;
const RUNS = 31;
const LIMIT = 5.0;

BenchDatabase::create(DATABASE, ROWS, tenanted: true);
try {
    $plain = new EntityManager(BenchDatabase::connection(DATABASE));
    $context = new StackTenantContext(1);
    $metadata = new MetadataFactory(new AttributeDriver([\dirname(__DIR__, 2) . '/benchmarks/Entity']));
    $guarded = DriverManager::fromUrl(BenchDatabase::url(DATABASE), new Configuration()->withGuards([new TenantGuard($metadata, $context)]));
    $tenanted = new EntityManager($guarded, $metadata, tenantContext: $context);

    $read = static function (EntityManager $em, string $class): float {
        $em->clear();
        $start = hrtime(true);
        if (ROWS !== \count($em->getRepository($class)->findAll())) {
            throw new LogicException('Unexpected row count.');
        }

        return (hrtime(true) - $start) / 1e6;
    };
    $read($plain, BenchItem::class);
    $read($tenanted, TenantBenchItem::class);

    $times = [[], []];
    for ($i = 0; $i < RUNS; ++$i) {
        $times[0][] = $read($plain, BenchItem::class);
        $times[1][] = $read($tenanted, TenantBenchItem::class);
    }
    $median = static function (array $values): float {
        sort($values);

        return $values[intdiv(\count($values), 2)];
    };
    [$without, $with] = [$median($times[0]), $median($times[1])];
    $overhead = ($with / $without - 1) * 100;

    printf("Without tenant: %.1f ms\nTenanted:       %.1f ms\nOverhead:       %+.1f %% (limit %.0f %%)\n", $without, $with, $overhead, LIMIT);
    exit($overhead < LIMIT ? 0 : 1);
} finally {
    BenchDatabase::drop(DATABASE);
}
