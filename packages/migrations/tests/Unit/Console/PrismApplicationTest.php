<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Prism\Migrations\Console\PrismApplication;
use Prism\Migrations\MigrationsConfiguration;

/**
 * bin/prism : prism.php, --config, et toutes les commandes db:* câblées.
 */
final class PrismApplicationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/prism-app-' . bin2hex(random_bytes(4));
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

    private function config(string $body): string
    {
        $file = $this->dir . '/prism-' . bin2hex(random_bytes(3)) . '.php';
        file_put_contents($file, "<?php\n" . $body);

        return $file;
    }

    public function testConfigOptionIsTakenOutOfTheArguments(): void
    {
        self::assertSame(['/app/prism.php', ['bin/prism', 'db:migrations:status']], PrismApplication::configFile(['bin/prism', 'db:migrations:status'], '/app/prism.php'));
        self::assertSame(['a.php', ['bin/prism', 'list']], PrismApplication::configFile(['bin/prism', '--config=a.php', 'list'], 'prism.php'));
        self::assertSame(['b.php', ['bin/prism', 'list', '-v']], PrismApplication::configFile(['bin/prism', 'list', '--config', 'b.php', '-v'], 'prism.php'));
    }

    public function testEntityManagerAloneIsEnough(): void
    {
        $config = PrismApplication::load($this->config("return new Prism\\Orm\\EntityManager(Prism\\Dbal\\DriverManager::fromUrl('sqlite:///:memory:?serverVersion=3.46.0'));"));

        self::assertSame('migrations', $config->migrations->directory);
        self::assertSame('App\\Migrations', $config->migrations->namespace);
    }

    public function testPrismConfigIsKept(): void
    {
        $config = PrismApplication::load($this->config(<<<'PHP'
            return new Prism\Migrations\Console\PrismConfig(
                new Prism\Orm\EntityManager(Prism\Dbal\DriverManager::fromUrl('sqlite:///:memory:?serverVersion=3.46.0')),
                new Prism\Migrations\MigrationsConfiguration('db/migrations', 'Acme\Migrations', 'prod'),
            );
            PHP));

        self::assertEquals(new MigrationsConfiguration('db/migrations', 'Acme\\Migrations', 'prod'), $config->migrations);
    }

    public function testMissingOrInvalidConfigIsExplained(): void
    {
        try {
            PrismApplication::load($this->dir . '/nope.php');
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('nope.php: create it so that it returns your EntityManager', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('returns array: it must return an EntityManagerInterface or a Prism\\Migrations\\Console\\PrismConfig.');
        PrismApplication::load($this->config('return [];'));
    }

    public function testEveryCommandIsRegistered(): void
    {
        $application = PrismApplication::create(PrismApplication::load($this->config("return new Prism\\Orm\\EntityManager(Prism\\Dbal\\DriverManager::fromUrl('sqlite:///:memory:?serverVersion=3.46.0'));")));

        $commands = array_values(array_filter(array_keys($application->all()), static fn (string $name): bool => str_starts_with($name, 'db:')));
        sort($commands);

        self::assertSame([
            'db:cache:clear-metadata', 'db:database:create', 'db:database:drop', 'db:mapping:info', 'db:metamodel:generate',
            'db:migrations:diff', 'db:migrations:execute', 'db:migrations:generate', 'db:migrations:lint', 'db:migrations:list',
            'db:migrations:migrate', 'db:migrations:status', 'db:query:sql',
            'db:schema:create', 'db:schema:drop', 'db:schema:update', 'db:schema:validate',
        ], $commands);
        self::assertSame('Prism', $application->getName());
    }
}
