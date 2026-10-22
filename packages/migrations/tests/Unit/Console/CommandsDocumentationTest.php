<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\DriverManager;
use Prism\Migrations\Console\PrismApplication;
use Prism\Migrations\Console\PrismConfig;
use Prism\Orm\EntityManager;
use Symfony\Component\Console\Command\Command;

/**
 * packages/bundle/docs/commands.md suit les commandes de bin/prism : une section par commande, et ses tableaux
 * listent exactement ses arguments et ses options, raccourcis compris (critère de sortie de la
 * phase 6). Une option ajoutée, renommée ou retirée sans la documentation fait échouer ce test.
 */
final class CommandsDocumentationTest extends TestCase
{
    /**
     * @return array<string, Command>
     */
    private static function commands(): array
    {
        $application = PrismApplication::create(new PrismConfig(new EntityManager(DriverManager::fromUrl('sqlite:///:memory:?serverVersion=3.46.0'))));

        $commands = [];
        foreach ($application->all('db') as $command) {
            $commands[(string) $command->getName()] = $command;
        }

        return $commands;
    }

    /**
     * Les sections « ### `db:…` » de la documentation, par commande.
     *
     * @return array<string, string>
     */
    private static function sections(): array
    {
        $doc = (string) file_get_contents(\dirname(__DIR__, 4) . '/bundle/docs/commands.md');
        preg_match_all('/^### `(db:[a-z:-]+)`\n(.*?)(?=^##)/ms', $doc . "\n##", $matches, \PREG_SET_ORDER);

        $sections = [];
        foreach ($matches as [, $name, $body]) {
            $sections[$name] = $body;
        }

        return $sections;
    }

    /**
     * Les lignes de tableau « | `--option` | `-f` | » et « | `<argument>` | | » d'une section.
     *
     * @return list<string>
     */
    private static function documented(string $section): array
    {
        preg_match_all('/^\| `(--[a-z-]+|<[a-z]+>)` \| (?:`(-[a-z])`)? *\|/m', $section, $rows, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);

        return array_map(static fn (array $row): string => $row[1] . (null === ($row[2] ?? null) ? '' : ' ' . $row[2]), $rows);
    }

    /**
     * @return list<string>
     */
    private static function defined(Command $command): array
    {
        $definition = $command->getDefinition();
        $arguments = array_map(static fn ($a): string => '<' . $a->getName() . '>', array_values($definition->getArguments()));
        $options = array_map(static fn ($o): string => '--' . $o->getName() . (null === $o->getShortcut() || '' === $o->getShortcut() ? '' : ' -' . $o->getShortcut()), array_values($definition->getOptions()));

        return [...$arguments, ...$options];
    }

    public function testEveryCommandHasItsSection(): void
    {
        $commands = array_keys(self::commands());
        $sections = array_keys(self::sections());
        sort($commands);
        sort($sections);

        self::assertCount(18, $commands);
        self::assertSame($commands, $sections, 'one section per command, and no section for a command that does not exist');
    }

    public function testSectionsListExactlyTheArgumentsAndOptions(): void
    {
        $sections = self::sections();
        $differences = [];
        foreach (self::commands() as $name => $command) {
            $documented = self::documented($sections[$name] ?? '');
            $defined = self::defined($command);
            sort($documented);
            sort($defined);
            if ($documented !== $defined) {
                $differences[$name] = ['documented' => $documented, 'defined' => $defined];
            }
        }

        self::assertSame([], $differences);
    }

    public function testEachSectionHasAnExample(): void
    {
        foreach (self::sections() as $name => $section) {
            self::assertStringContainsString('$ vendor/bin/prism ' . $name, $section, $name);
        }
    }
}
