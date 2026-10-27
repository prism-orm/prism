<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Support;

use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Query\Metamodel\MetamodelGenerator;
use Prism\Orm\Tests\Functional\VectorFixtures\Doc;
use Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\AllTypes;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures as Tenancy;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior;

/**
 * Metamodel des fixtures du QueryBuilder typé, versionné à côté des entités pour que PHPStan
 * vérifie les requêtes des tests. Le régénérer après un changement de ces fixtures :
 *
 *     docker compose run --rm php php packages/orm/tests/Support/MetamodelFixtures.php
 *
 * @internal
 */
final class MetamodelFixtures
{
    public const string PREFIX = 'Prism\\Orm\\Tests\\';

    /**
     * @return list<ClassMetadata<object>>
     */
    public static function metadata(): array
    {
        $driver = new AttributeDriver();

        return array_map($driver->loadMetadata(...), [
            AllTypes::class,
            Batch\Account::class,
            Batch\Country::class,
            Batch\Customer::class,
            Batch\Depot::class,
            Batch\Group::class,
            Batch\Order::class,
            Batch\Seller::class,
            Behavior\Link::class,
            Behavior\Page::class,
            Tenancy\Client::class,
            Tenancy\Contract::class,
            Tenancy\Currency::class,
            Tenancy\Invoice::class,
            Tenancy\Line::class,
            Tenancy\Rate::class,
            Tenancy\Tag::class,
            Doc::class,
        ]);
    }

    public static function directory(): string
    {
        return \dirname(__DIR__);
    }

    public static function generate(): void
    {
        new MetamodelGenerator()->generate(self::metadata(), self::directory(), self::PREFIX);
    }
}

$script = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (\PHP_SAPI === 'cli' && \is_string($script) && __FILE__ === realpath($script)) {
    require \dirname(__DIR__, 4) . '/vendor/autoload.php';
    MetamodelFixtures::generate();
}
