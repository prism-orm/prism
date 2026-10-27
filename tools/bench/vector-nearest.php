<?php

declare(strict_types=1);

/*
 * Critère de sortie de la phase 9 : nearest() < 50 ms (p95) sur 100 000 documents, sous MariaDB
 * et PostgreSQL (ADR 0018, section 6).
 *
 * 100 000 vecteurs aléatoires de 768 dimensions (la taille de nomic-embed-text), normalisés, d'une
 * graine fixe : le sens n'influe pas sur la vitesse. L'index HNSW (cosinus, m = 16) est créé après
 * le chargement, puis 20 recherches d'échauffement et 200 mesurées, chacune avec un nouveau
 * vecteur : nearest() des 10 plus proches, sans filtre, puis avec un critère qui garde 1 % des
 * lignes (pré-filtrage, ADR 0017), sur une colonne indexée comme le serait tenant_id. Temps de
 * bout en bout, hydratation comprise.
 *
 * MariaDB garde le graphe HNSW dans un cache de 16 Mo par défaut (mhnsw_max_cache_size), et les
 * pages dans un buffer pool de 128 Mo : trop peu pour 100 000 vecteurs de 768 dimensions, le
 * graphe serait relu à chaque recherche. Comme en production, le benchmark les dimensionne
 * (1 Go chacun, affichés), puis rétablit les valeurs d'avant.
 *
 *     make bench-vector [DB=mariadb|pgsql] [ROWS=100000] [KEEP=1]
 *
 * KEEP=1 garde la base à la fin, et la réutilise au lancement suivant si elle a ses lignes.
 */

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

use Prism\Benchmarks\Entity\BenchVector;
use Prism\Benchmarks\Support\BenchDatabase;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\IndexKind;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Table;
use Prism\Orm\EntityManager;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Tools\SchemaTool;

const DATABASE = 'prism_bench_vector';
const DIMENSIONS = 768;
const QUERIES = 200;
const WARMUP = 20;
const LIMIT_MS = 50.0;

$rows = (int) (getenv('ROWS') ?: 100_000);
$keep = '1' === getenv('KEEP');

/**
 * @return list<float> un vecteur normalisé, du générateur à graine fixe
 */
function randomVector(): array
{
    $v = [];
    for ($i = 0; $i < DIMENSIONS; ++$i) {
        $v[] = mt_rand() / mt_getrandmax() * 2 - 1;
    }
    $norm = sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $v)));

    return array_map(static fn (float $x): float => $x / $norm, $v);
}

/**
 * @param list<float> $times
 */
function percentile(array $times, float $p): float
{
    sort($times);

    return $times[min(\count($times) - 1, (int) ceil($p / 100 * \count($times)) - 1)];
}

$reuse = false;
if ($keep) {
    try {
        $reuse = $rows === (int) BenchDatabase::connection(DATABASE)->executeQuery('SELECT COUNT(*) FROM bench_vector')->fetchOne();
    } catch (\Throwable) {
    }
}
if (!$reuse) {
    BenchDatabase::createEmpty(DATABASE);
}
$restore = [];
try {
    $connection = BenchDatabase::connection(DATABASE);
    $platform = $connection->getPlatform();
    if ($platform instanceof MariaDbPlatform) {
        foreach (['mhnsw_max_cache_size', 'innodb_buffer_pool_size'] as $variable) {
            $restore[$variable] = $connection->executeQuery('SELECT @@GLOBAL.' . $variable)->fetchOne();
            $connection->executeStatement(\sprintf('SET GLOBAL %s = %d', $variable, 1 << 30));
        }
        printf("MariaDB: mhnsw_max_cache_size and innodb_buffer_pool_size set to 1 GiB for the run\n");
    }
    $target = new SchemaTool($platform)->fromMetadata([new AttributeDriver()->loadMetadata(BenchVector::class)]);
    $table = $target->getTable('bench_vector');
    $bare = $table;
    foreach ($table->getIndexes() as $index) {
        if (IndexKind::Vector === $index->kind) {
            $bare = $bare->withoutIndex($index->name);
        }
    }
    if (!$reuse) {
    $connection->createSchemaManager()->migrate(new Comparator($platform)->compare(new Schema(), new Schema([$bare])));

    // chargement par INSERT de 200 lignes (outil de mesure, hors de l'ORM) : seul nearest() est mesuré
    mt_srand(42);
    $start = hrtime(true);
    $insert = \sprintf('INSERT INTO %s (%s, %s) VALUES ', $platform->quoteIdentifier('bench_vector'), $platform->quoteIdentifier('category'), $platform->quoteIdentifier('embedding'));
    for ($done = 0; $done < $rows; $done += 200) {
        $values = [];
        $params = [];
        for ($i = $done; $i < min($done + 200, $rows); ++$i) {
            $values[] = \sprintf('(:c%d, %s)', $i, $platform->getVectorParameterSql(':v' . $i));
            $params['c' . $i] = $i % 100;
            $params['v' . $i] = $connection->convertToDatabase(randomVector(), 'vector');
        }
        $connection->executeStatement($insert . implode(', ', $values), $params);
    }
    $load = (hrtime(true) - $start) / 1e9;
    $start = hrtime(true);
    $connection->createSchemaManager()->migrate(new Comparator($platform)->compare(new Schema([$bare]), $target));
    $index = (hrtime(true) - $start) / 1e9;
    printf("%s, %d rows of %d dimensions: loaded in %.0f s, HNSW index built in %.0f s\n", $connection->getPlatform()::class, $rows, DIMENSIONS, $load, $index);
    } else {
        printf("%s, %d rows of %d dimensions: reused\n", $connection->getPlatform()::class, $rows, DIMENSIONS);
        mt_srand(42 + $rows);
    }

    $em = new EntityManager($connection);
    $documents = $em->getRepository(BenchVector::class);
    $failed = false;
    foreach (['without filter' => [], 'category = 7 (1 %)' => ['category' => 7]] as $label => $criteria) {
        $times = [];
        $short = 0;
        for ($i = 0; $i < WARMUP + QUERIES; ++$i) {
            $query = randomVector();
            $em->clear();
            $start = hrtime(true);
            $hits = $documents->nearest('embedding', $query, limit: 10, criteria: $criteria);
            $elapsed = (hrtime(true) - $start) / 1e6;
            if ($i >= WARMUP) {
                $times[] = $elapsed;
                $short += \count($hits) < 10 ? 1 : 0;
            }
        }
        $p95 = percentile($times, 95);
        printf("nearest(limit: 10) %-20s p50 %6.1f ms   p95 %6.1f ms   p99 %6.1f ms   fewer than 10 hits: %d/%d\n", $label, percentile($times, 50), $p95, percentile($times, 99), $short, QUERIES);
        if ([] === $criteria) {
            $failed = $p95 >= LIMIT_MS;
        }
    }
    printf("Criterion: p95 without filter < %.0f ms: %s\n", LIMIT_MS, $failed ? 'FAILED' : 'met');
    exit($failed ? 1 : 0);
} finally {
    if (!$keep) {
        foreach ($restore as $variable => $value) {
            BenchDatabase::connection(DATABASE)->executeStatement(\sprintf('SET GLOBAL %s = %d', $variable, is_numeric($value) ? (int) $value : 0));
        }
        BenchDatabase::drop(DATABASE);
    }
}
