<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\DriverManager;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Migrations\VersionStorage;
use Prism\Orm\Console\Command\DatabaseCreateCommand;
use Prism\Orm\Console\Command\DatabaseDropCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * db:database:create et drop sur les 4 bases, sur une base de nom aléatoire ; bin/prism lancé
 * comme un vrai processus.
 */
final class ConsoleTest extends TestCase
{
    private string $url;

    private string $database;

    protected function setUp(): void
    {
        $url = TestDatabase::url();
        if (null === $url) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }
        $suffix = bin2hex(random_bytes(4));
        if (str_starts_with($url, 'sqlite')) {
            $this->database = sys_get_temp_dir() . '/prism-console-' . $suffix . '/db.sqlite';
            // quatre barres : un chemin absolu (sqlite:///x serait relatif)
            $this->url = 'sqlite:///' . $this->database . '?serverVersion=3.46.0';
        } else {
            $this->database = 'prism_console_' . $suffix;
            $this->url = (string) preg_replace('#^([^?]+/)[^/?]+#', '$1' . $this->database, $url);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->url)) {
            new CommandTester(new DatabaseDropCommand($this->connection()))->execute(['--force' => true, '--if-exists' => true], ['interactive' => false]);
            if (str_starts_with($this->url, 'sqlite') && is_dir(\dirname($this->database))) {
                rmdir(\dirname($this->database));
            }
        }
    }

    private function connection(): Connection
    {
        return DriverManager::fromUrl($this->url);
    }

    private static function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function testCreateAndDropTheDatabase(): void
    {
        $create = new CommandTester(new DatabaseCreateCommand($this->connection()));
        $created = [$create->execute([])];
        $created[] = $create->execute([]);
        $exists = self::display($create);
        $created[] = $create->execute(['--if-not-exists' => true]);

        // créée ; puis déjà là : refusé, sauf --if-not-exists
        self::assertSame([Command::SUCCESS, Command::FAILURE, Command::SUCCESS], $created);
        self::assertStringContainsString('already exists; pass --if-not-exists', $exists);

        // la base créée est utilisable
        $connection = $this->connection();
        $connection->executeStatement('CREATE TABLE probe (id INTEGER NOT NULL PRIMARY KEY)');
        self::assertEquals(0, $connection->executeQuery('SELECT COUNT(*) FROM probe')->fetchOne());

        $drop = new CommandTester(new DatabaseDropCommand($connection));
        $codes = [$drop->execute([])];
        $needsForce = self::display($drop);
        $codes[] = $drop->execute(['--force' => true]);
        $codes[] = $drop->execute(['--force' => true]);
        $missing = self::display($drop);
        $codes[] = $drop->execute(['--force' => true, '--if-exists' => true]);

        // sans --force, rien ; puis supprimée ; puis absente : refusé, sauf --if-exists
        self::assertSame([Command::INVALID, Command::SUCCESS, Command::FAILURE, Command::SUCCESS], $codes);
        self::assertStringContainsString('Run with --force to drop it.', $needsForce);
        self::assertStringContainsString('does not exist; pass --if-exists', $missing);
    }

    // RG-06.9 comme pour migrate : en prod, une confirmation
    public function testDropAsksForConfirmationInProduction(): void
    {
        new CommandTester(new DatabaseCreateCommand($this->connection()))->execute([]);

        $drop = new CommandTester(new DatabaseDropCommand($this->connection(), 'prod'));
        $drop->setInputs(['no']);
        self::assertSame(Command::FAILURE, $drop->execute(['--force' => true]));
        self::assertStringContainsString('Cancelled: nothing was dropped.', self::display($drop));
        self::assertSame(Command::SUCCESS, new CommandTester(new DatabaseCreateCommand($this->connection()))->execute(['--if-not-exists' => true]), 'still there');
    }

    public function testBinPrismRunsAsAProcess(): void
    {
        new CommandTester(new DatabaseCreateCommand($this->connection()))->execute([]);
        $dir = sys_get_temp_dir() . '/prism-bin-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $fixtures = \dirname(__DIR__) . '/Fixtures/Basic';
        file_put_contents($dir . '/prism.php', \sprintf(<<<'PHP'
            <?php
            return new Prism\Migrations\Console\PrismConfig(
                new Prism\Orm\EntityManager(Prism\Dbal\DriverManager::fromUrl(%s)),
                new Prism\Migrations\MigrationsConfiguration(%s, 'Prism\Migrations\Tests\Fixtures\Basic'),
            );
            PHP, var_export($this->url, true), var_export($fixtures, true)));
        $bin = \dirname(__DIR__, 2) . '/bin/prism';

        try {
            $run = static function (string $arguments, ?string $cwd = null) use ($bin): array {
                exec(\sprintf('cd %s && %s %s %s 2>&1', escapeshellarg($cwd ?? sys_get_temp_dir()), escapeshellarg(\PHP_BINARY), escapeshellarg($bin), $arguments), $output, $status);

                return [$status, (string) preg_replace('/\s+/', ' ', implode("\n", $output))];
            };

            [$status, $output] = $run('db:migrations:status', $dir);
            self::assertSame(0, $status, $output);
            self::assertStringContainsString('Pending 3', $output, 'prism.php of the current directory');

            [$status, $output] = $run('db:migrations:migrate --no-interaction --config=' . escapeshellarg($dir . '/prism.php'));
            self::assertSame(0, $status, $output);
            self::assertStringContainsString('3 migration(s) executed', $output);
            self::assertTrue(\in_array(VersionStorage::TABLE, $this->connection()->createSchemaManager()->listTableNames(), true));

            [$status, $output] = $run('db:migrations:status');
            self::assertSame(1, $status);
            self::assertStringContainsString('prism.php: create it so that it returns your EntityManager', $output);
        } finally {
            unlink($dir . '/prism.php');
            rmdir($dir);
        }
    }
}
