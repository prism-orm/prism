<?php

declare(strict_types=1);

namespace Prism\Benchmarks;

use PhpBench\Attributes as Bench;
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

/**
 * Critère de sortie de la phase 8 : surcoût du filtre tenant < 5 % sur la lecture. La même
 * lecture de 10 000 entités, sans tenant, puis tenantée avec le filtre et la garde du SQL brut
 * (ADR 0014) ; la colonne du tenant est lue en plus. Les deux tables sont compactées après leur
 * remplissage : sans cela, l'écart mesuré serait surtout l'état physique de la seconde. Chaque
 * sujet tourne dans ses propres processus : sur une machine partagée, comparer deux sujets n'est
 * pas fiable ; le critère se mesure par make bench-tenancy, qui alterne les deux lectures.
 */
#[Bench\BeforeClassMethods('createDatabase')]
#[Bench\AfterClassMethods('dropDatabase')]
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(10)]
#[Bench\Revs(5)]
#[Bench\Warmup(2)]
#[Bench\OutputTimeUnit('milliseconds', 1)]
final class TenancyBench
{
    private const string DATABASE = 'prism_bench_tenancy';

    private const int ROWS = 10_000;

    private EntityManager $plain;

    private EntityManager $tenanted;

    public static function createDatabase(): void
    {
        BenchDatabase::create(self::DATABASE, self::ROWS, tenanted: true);
    }

    public static function dropDatabase(): void
    {
        BenchDatabase::drop(self::DATABASE);
    }

    public function setUp(): void
    {
        $this->plain = new EntityManager(BenchDatabase::connection(self::DATABASE));

        $context = new StackTenantContext(1);
        $metadata = new MetadataFactory(new AttributeDriver([__DIR__ . '/Entity']));
        $url = BenchDatabase::url(self::DATABASE);
        $connection = DriverManager::fromUrl($url, new Configuration()->withGuards([new TenantGuard($metadata, $context)]));
        $this->tenanted = new EntityManager($connection, $metadata, tenantContext: $context);
    }

    public function benchFindAllWithoutTenant(): void
    {
        $this->plain->clear();
        if (self::ROWS !== \count($this->plain->getRepository(BenchItem::class)->findAll())) {
            throw new \LogicException('Unexpected row count.');
        }
    }

    public function benchFindAllTenanted(): void
    {
        $this->tenanted->clear();
        if (self::ROWS !== \count($this->tenanted->getRepository(TenantBenchItem::class)->findAll())) {
            throw new \LogicException('Unexpected row count.');
        }
    }

    // la même lecture sans tenant, après la lecture tenantée : l'ordre des sujets ne biaise pas l'écart
    public function benchFindAllWithoutTenantAgain(): void
    {
        $this->benchFindAllWithoutTenant();
    }
}
