<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Bundle\DependencyInjection\PrismConfiguration;
use Prism\Bundle\PrismBundle;

/**
 * La recette Flex (phase 7, étape 8) suit le code : le bundle qu'elle enregistre existe, les
 * fichiers qu'elle copie aussi, la variable qu'elle ajoute est celle que lit la configuration par
 * défaut. Son application réelle est vérifiée par tools/compat/compat.sh.
 */
final class RecipeTest extends TestCase
{
    private const string RECIPE = __DIR__ . '/../../../../recipes/prism-orm/bundle/0.7';

    /**
     * @return array{bundles: array<string, list<string>>, copy-from-recipe: array<string, string>, env: array<string, string>}
     */
    private static function manifest(): array
    {
        /** @var array{bundles: array<string, list<string>>, copy-from-recipe: array<string, string>, env: array<string, string>} */
        return json_decode((string) file_get_contents(self::RECIPE . '/manifest.json'), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testBundleIsRegisteredInEveryEnvironment(): void
    {
        self::assertSame([PrismBundle::class => ['all']], self::manifest()['bundles']);
    }

    public function testCopiedFilesExist(): void
    {
        foreach (array_keys(self::manifest()['copy-from-recipe']) as $from) {
            self::assertDirectoryExists(self::RECIPE . '/' . $from, $from);
        }
        foreach (['config/packages/prism.yaml', 'src/Entity/.gitignore', 'migrations/.gitignore'] as $file) {
            self::assertFileExists(self::RECIPE . '/' . $file);
        }
    }

    public function testDatabaseUrlIsTheOneOfTheDefaultConfiguration(): void
    {
        $env = self::manifest()['env'];

        self::assertArrayHasKey('DATABASE_URL', $env);
        self::assertStringContainsString('serverVersion=', $env['DATABASE_URL'], 'Prism needs it');
        // la recette et la configuration sans prism.yaml lisent la même variable
        self::assertStringContainsString("url: '" . PrismConfiguration::DEFAULT_URL . "'", (string) file_get_contents(self::RECIPE . '/config/packages/prism.yaml'));
    }
}
