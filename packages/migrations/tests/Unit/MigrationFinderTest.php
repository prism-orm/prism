<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Migrations\Exception\MigrationException;
use Prism\Migrations\MigrationFinder;
use Prism\Migrations\Tests\Fixtures\Basic;

/**
 * RG-06.1 : une migration par fichier VersionYYYYMMDDHHMMSS.php, dans l'ordre chronologique.
 */
final class MigrationFinderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/prism-migrations-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->dir . '/*');
        foreach (false === $files ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testMigrationsInChronologicalOrder(): void
    {
        $migrations = new MigrationFinder(\dirname(__DIR__) . '/Fixtures/Basic', 'Prism\\Migrations\\Tests\\Fixtures\\Basic')->find();

        self::assertSame(['Version20261021100000', 'Version20261021110000', 'Version20261021120000'], array_keys($migrations));
        self::assertInstanceOf(Basic\Version20261021110000::class, $migrations['Version20261021110000']);
    }

    public function testMissingDirectoryHasNoMigration(): void
    {
        self::assertSame([], new MigrationFinder($this->dir . '/nope', 'App')->find());
    }

    public function testFileNameIsChecked(): void
    {
        touch($this->dir . '/AddPaidAt.php');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('is not named VersionYYYYMMDDHHMMSS.php (RG-06.1).');

        new MigrationFinder($this->dir, 'App')->find();
    }

    public function testClassMustMatchTheNamespace(): void
    {
        file_put_contents($this->dir . '/Version20261021130000.php', "<?php\nnamespace Elsewhere;\nfinal class Version20261021130000 {}\n");

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('does not declare the class App\\Migrations\\Version20261021130000: check its namespace.');

        new MigrationFinder($this->dir, 'App\\Migrations')->find();
    }

    public function testClassMustBeAMigration(): void
    {
        file_put_contents($this->dir . '/Version20261021140000.php', "<?php\nnamespace App\\Plain;\nfinal class Version20261021140000 {}\n");

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('App\\Plain\\Version20261021140000 does not extend Prism\\Migrations\\AbstractMigration.');

        new MigrationFinder($this->dir, 'App\\Plain')->find();
    }
}
