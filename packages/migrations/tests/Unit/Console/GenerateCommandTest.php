<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Prism\Migrations\Console\Command\GenerateCommand;
use Prism\Migrations\MigrationFinder;
use Prism\Migrations\MigrationsConfiguration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * db:migrations:generate : une migration vide, nommée d'après l'heure UTC (RG-06.1).
 */
final class GenerateCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/prism-generate-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $files = glob($this->dir . '/*');
        foreach (false === $files ? [] : $files as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function tester(string $namespace = 'App\\Migrations\\Generated'): CommandTester
    {
        // 16:30 à Paris : 14:30 UTC
        $now = static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-21 16:30:05', new \DateTimeZone('Europe/Paris'));

        return new CommandTester(new GenerateCommand(new MigrationsConfiguration($this->dir, $namespace), $now));
    }

    public function testEmptyMigrationNamedAfterTheUtcTime(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $file = $this->dir . '/Version20261021143005.php';
        self::assertFileExists($file, 'the directory is created');
        self::assertStringContainsString('Migration Version20261021143005 created', $tester->getDisplay());

        exec(\sprintf('%s -l %s 2>&1', escapeshellarg(\PHP_BINARY), escapeshellarg($file)), $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        $migrations = new MigrationFinder($this->dir, 'App\\Migrations\\Generated')->find();
        self::assertSame(['Version20261021143005'], array_keys($migrations), 'loadable as a migration');
        self::assertStringContainsString('sans down(), la migration est irréversible', (string) file_get_contents($file), 'irreversible until its down() is written (RG-06.7)');
    }

    public function testExistingFileIsNotOverwritten(): void
    {
        mkdir($this->dir);
        file_put_contents($this->dir . '/Version20261021143005.php', '<?php // mine');

        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('already exists', $tester->getDisplay());
        self::assertSame('<?php // mine', file_get_contents($this->dir . '/Version20261021143005.php'));
    }

    public function testUnwritableDirectoryFails(): void
    {
        file_put_contents($this->dir, 'a file, not a directory');
        $tester = $this->tester();

        try {
            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertStringContainsString('Cannot write', $tester->getDisplay());
        } finally {
            unlink($this->dir);
        }
    }
}
