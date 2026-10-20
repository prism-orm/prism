<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Support;

use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Query\Metamodel\MetamodelGenerator;
use Prism\PHPStan\Tests\Fixtures;

/**
 * Metamodel des fixtures de l'extension, versionné à côté des entités. Le régénérer après un
 * changement de ces fixtures :
 *
 *     docker compose run --rm php php packages/phpstan/tests/Support/MetamodelFixtures.php
 *
 * @internal
 */
final class MetamodelFixtures
{
    public const string PREFIX = 'Prism\\PHPStan\\Tests\\';

    /**
     * @return list<ClassMetadata<object>>
     */
    public static function metadata(): array
    {
        $driver = new AttributeDriver();

        return array_map($driver->loadMetadata(...), [Fixtures\Client::class, Fixtures\Contract::class, Fixtures\Invoice::class]);
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
