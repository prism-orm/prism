<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Orm\Query\Metamodel\MetamodelGenerator;
use Prism\PHPStan\MetadataProvider;
use Prism\PHPStan\Tests\Fixtures\Dto\Summary;
use Prism\PHPStan\Tests\Fixtures\Invoice;
use Prism\PHPStan\Tests\Support\MetamodelFixtures;

/**
 * Le mapping lu par l'extension (ADR 0011) : celui de l'exécution, rien pour une classe qui
 * n'est pas une entité.
 */
final class MetadataProviderTest extends TestCase
{
    public function testFixtureMetamodelIsFresh(): void
    {
        self::assertTrue(new MetamodelGenerator()->isFresh(MetamodelFixtures::metadata(), MetamodelFixtures::directory(), MetamodelFixtures::PREFIX), 'regenerate it: docker compose run --rm php php packages/phpstan/tests/Support/MetamodelFixtures.php');
    }

    public function testMetadataOfEntitiesOnly(): void
    {
        $provider = new MetadataProvider('/nonexistent');

        self::assertSame(Invoice::class, $provider->metadataFor(Invoice::class)?->className);
        self::assertSame($provider->metadataFor(Invoice::class), $provider->metadataFor(Invoice::class), 'read once');
        self::assertNull($provider->metadataFor(Summary::class), 'not an entity');
        self::assertNull($provider->metadataFor('App\\Missing'), 'not loadable');
    }

    public function testComparablePathsAndSuggestions(): void
    {
        $metadata = new MetadataProvider('/nonexistent')->metadataFor(Invoice::class);
        self::assertNotNull($metadata);
        $paths = MetadataProvider::comparablePaths($metadata);

        self::assertSame(['id', 'reference', 'total', 'discount', 'status', 'issuedAt', 'paidAt', 'meta', 'billing.city', 'billing.zip', 'shipping.city', 'shipping.zip', 'client'], $paths);
        self::assertSame('reference', MetadataProvider::closest('refernce', $paths));
        self::assertSame('issuedAt', MetadataProvider::closest('ISSUEDAT', $paths), 'case-insensitive');
        self::assertNull(MetadataProvider::closest('somethingElse', $paths));
    }

    public function testMetamodelDirectoryIsAutoloaded(): void
    {
        $dir = sys_get_temp_dir() . '/prism-phpstan-' . bin2hex(random_bytes(4));
        mkdir($dir . '/Acme', 0o777, true);
        file_put_contents($dir . '/Acme/Thing_.php', "<?php\nnamespace Acme;\nfinal class Thing_ {}\n");

        new MetadataProvider($dir);

        self::assertTrue(class_exists('Acme\\Thing_'));
        unlink($dir . '/Acme/Thing_.php');
        rmdir($dir . '/Acme');
        rmdir($dir);
    }
}
