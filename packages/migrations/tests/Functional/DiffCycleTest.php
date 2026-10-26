<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Migrations\Console\Command\DiffCommand;
use Prism\Migrations\Console\Command\MigrateCommand;
use Prism\Migrations\MigrationsConfiguration;
use Prism\Migrations\Tests\Fixtures\Entities\V1;
use Prism\Migrations\Tests\Fixtures\Entities\V2;
use Prism\Migrations\VersionStorage;
use Prism\Orm\EntityManager;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Critère de sortie de la phase 6 sur les 4 bases : diff → migrate → diff vide. Des entités V1
 * sur une base vide, puis V2 (table et colonne renommées, colonne et index ajoutés) avec les
 * renommages confirmés (RG-06.3) : données et compteur de séquence gardés ; puis retour à V1
 * par le down() généré (RG-06.7).
 */
final class DiffCycleTest extends TestCase
{
    private const array TABLES = ['df_bill', 'df_invoice', 'df_client', 'df_page', 'prism_audit_log', VersionStorage::TABLE];

    private Connection $connection;

    private string $dir;

    private MigrationsConfiguration $configuration;

    private int $second = 0;

    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }
        $this->connection = TestDatabase::connection();
        $this->drop();
        $this->dir = sys_get_temp_dir() . '/prism-diff-' . bin2hex(random_bytes(4));
        // un namespace par test : les classes générées ne se chargent qu'une fois par processus
        $this->configuration = new MigrationsConfiguration($this->dir, 'Prism\\Migrations\\Tests\\Generated\\Run' . bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->drop();
            $this->connection->close();
        }
        $files = glob($this->dir . '/*');
        foreach (false === $files ? [] : $files as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function drop(): void
    {
        $platform = $this->connection->getPlatform();
        foreach (self::TABLES as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table));
        }
        if ('PostgreSQL' === $platform->getName()) {
            foreach (['df_client_id_seq', 'df_invoice_id_seq', 'df_bill_id_seq', 'df_page_id_seq'] as $sequence) {
                $this->connection->executeStatement('DROP SEQUENCE IF EXISTS ' . $platform->quoteIdentifier($sequence));
            }
        }
    }

    private static function metadata(string $version): MetadataFactory
    {
        return new MetadataFactory(new AttributeDriver([\dirname(__DIR__) . '/Fixtures/Entities/' . $version]));
    }

    /**
     * @param list<string> $answers
     *
     * @return array{int, string}
     */
    private function diff(string $version, array $answers = [], bool $interactive = true): array
    {
        // une seconde de plus à chaque génération : une version par fichier
        $now = fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-21 10:00:00 UTC')->modify('+' . $this->second++ . ' seconds');
        $tester = new CommandTester(new DiffCommand($this->connection, self::metadata($version), $this->configuration, $now));
        $tester->setInputs($answers);
        $code = $tester->execute([], ['interactive' => $interactive]);

        return [$code, (string) preg_replace('/\s+/', ' ', $tester->getDisplay())];
    }

    private function migrate(?string $to = null): void
    {
        $tester = new CommandTester(new MigrateCommand($this->connection, $this->configuration));
        self::assertSame(Command::SUCCESS, $tester->execute(null === $to ? [] : ['version' => $to], ['interactive' => false]), $tester->getDisplay());
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->dir . '/*.php');

        return false === $files ? [] : array_map(static fn (string $f): string => basename($f, '.php'), $files);
    }

    private function lastFile(): string
    {
        $files = $this->files();

        return (string) file_get_contents($this->dir . '/' . end($files) . '.php');
    }

    public function testDiffMigrateDiffIsEmptyWithRenamesAndBack(): void
    {
        // V1 sur une base vide
        [$code, $display] = $this->diff('V1');
        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('Migration Version20261021100000 generated', $display);
        $this->migrate();
        [$code, $display] = $this->diff('V1');
        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('No changes: the database matches the entities, no migration generated.', $display, 'RG-06.2');
        self::assertCount(1, $this->files(), 'RG-06.2: no file without a change');

        // des données, par l'ORM : la séquence de PostgreSQL avance
        $em = new EntityManager($this->connection, self::metadata('V1'));
        $client = new V1\Client();
        $client->name = 'Ada';
        $client->mail = 'ada@example.com';
        $invoice = new V1\Invoice($client);
        $invoice->ref = 'F-1';
        $invoice->total = '12.50';
        $em->persist($client);
        $em->persist($invoice);
        $em->flush();

        // V2 : la table et la colonne renommées sont confirmées
        [$code, $display] = $this->diff('V2', ['yes', 'yes']);
        self::assertSame(Command::SUCCESS, $code, $display);
        self::assertStringContainsString('Rename the table df_invoice to df_bill?', $display);
        self::assertStringContainsString('Rename the column df_client.mail to df_client.email?', $display);
        $generated = $this->lastFile();
        self::assertStringContainsString("\$m->renameTable('df_invoice', 'df_bill');", $generated);
        self::assertStringContainsString("\$m->renameColumn('df_client', 'mail', 'email');", $generated);
        self::assertStringNotContainsString('dropTable', $generated);
        $postgres = 'PostgreSQL' === $this->connection->getPlatform()->getName();
        self::assertSame($postgres, str_contains($generated, "\$m->renameSequence('df_invoice_id_seq', 'df_bill_id_seq');"), 'the counter of the sequence follows its table');

        $this->migrate();
        [, $display] = $this->diff('V2');
        self::assertStringContainsString('No changes', $display, 'diff → migrate → diff is empty');
        self::assertSame(
            [['ref' => 'F-1', 'email' => 'ada@example.com']],
            $this->connection->executeQuery('SELECT b.ref, c.email FROM df_bill b INNER JOIN df_client c ON c.id = b.client_id')->fetchAllAssociative(),
            'the data survives the renames',
        );

        // l'identifiant suivant n'est pas 1 : le compteur est gardé
        $em = new EntityManager($this->connection, self::metadata('V2'));
        $ada = $em->getRepository(V2\Client::class)->findOneBy(['email' => 'ada@example.com']);
        self::assertNotNull($ada);
        $next = new V2\Invoice($ada);
        $next->ref = 'F-2';
        $em->persist($next);
        $em->flush();
        self::assertSame(2, $next->id);

        // retour à V1 par le down() généré
        $files = $this->files();
        $this->migrate($files[0]);
        [, $display] = $this->diff('V1');
        self::assertStringContainsString('No changes', $display, 'down() restores the V1 schema exactly');
        self::assertSame('ada@example.com', $this->connection->executeQuery('SELECT mail FROM df_client')->fetchOne());
    }

    // RG-06.3 : sans question possible, drop + add et un avertissement ; down() irréversible (RG-06.7)
    // ADR 0015 : un index partiel et la table du journal d'audit passent par diff → migrate → diff vide
    public function testPartialUniqueIndexAndAuditLog(): void
    {
        [$code] = $this->diff('SoftDelete');
        self::assertSame(Command::SUCCESS, $code);
        $file = $this->lastFile();
        self::assertStringContainsString("whereNull: 'deleted_at'", $file);
        self::assertStringContainsString("new Table('prism_audit_log'", $file);

        $this->migrate();
        [$code, $display] = $this->diff('SoftDelete');
        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('No changes', $display);
        self::assertCount(1, $this->files());
    }

    public function testNonInteractiveDiffWarnsAboutPossibleRenames(): void
    {
        $this->diff('V1');
        $this->migrate();

        [$code, $display] = $this->diff('V2', interactive: false);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString("The table df_invoice is no longer mapped and df_bill is created with the same columns: if it is a rename, replace the createTable() below with \$m->renameTable('df_invoice', 'df_bill') to keep the data.", $display);
        self::assertStringContainsString('df_client.mail is dropped and df_client.email added, both string', $display);
        self::assertStringContainsString('Its down() is not generated: it drops df_client.mail, whose data', $display);
        $generated = $this->lastFile();
        self::assertStringContainsString('// RG-06.3 : The table df_invoice is no longer mapped', $generated);
        self::assertStringNotContainsString("\$m->dropTable('df_invoice');", $generated, 'an unmapped table is kept without --complete');
        self::assertStringContainsString('throw IrreversibleMigrationException::forVersion(', $generated);
    }

    public function testDryRunPrintsTheMigrationWithoutWritingIt(): void
    {
        $tester = new CommandTester(new DiffCommand($this->connection, self::metadata('V1'), $this->configuration));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true], ['interactive' => false]));
        self::assertStringContainsString("\$m->createTable(new Table('df_client', [", $tester->getDisplay());
        self::assertSame([], $this->files());
    }

    public function testCompleteDropsUnmappedTablesButNotThePrismOnes(): void
    {
        $this->diff('V1');
        $this->migrate();
        $this->connection->executeStatement('CREATE TABLE df_legacy (id INTEGER NOT NULL PRIMARY KEY)');

        try {
            [, $display] = $this->diff('V1');
            self::assertStringContainsString('No changes', $display, 'unmapped tables are ignored without --complete');

            $tester = new CommandTester(new DiffCommand($this->connection, self::metadata('V1'), $this->configuration));
            $tester->execute(['--complete' => true, '--dry-run' => true], ['interactive' => false]);
            self::assertStringContainsString("\$m->dropTable('df_legacy');", $tester->getDisplay());
            self::assertStringNotContainsString(VersionStorage::TABLE, $tester->getDisplay(), 'the prism_ tables are never dropped');
        } finally {
            $this->connection->executeStatement('DROP TABLE IF EXISTS df_legacy');
        }
    }
}
