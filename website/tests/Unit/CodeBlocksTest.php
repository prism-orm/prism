<?php

declare(strict_types=1);

namespace Prism\Website\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Website\Tests\Support\CodeBlock;
use Prism\Website\Tests\Support\DocPages;
use Prism\Website\Tests\Support\ExampleScript;

/**
 * Les blocs PHP des pages du site (RG-10.3) : marqueurs connus, syntaxe valide, y compris pour
 * un bloc no-run ; leur exécution est dans Functional\ExamplesTest.
 */
final class CodeBlocksTest extends TestCase
{
    /**
     * @return iterable<string, array{CodeBlock}>
     */
    public static function phpBlocks(): iterable
    {
        foreach (DocPages::codeBlocks('php') as $block) {
            yield $block->name() => [$block];
        }
    }

    #[DataProvider('phpBlocks')]
    public function testFlagsAreKnown(CodeBlock $block): void
    {
        self::assertSame([], array_values(array_diff($block->flags, CodeBlock::FLAGS)), \sprintf('%s: unknown marker; known: %s.', $block->name(), implode(', ', CodeBlock::FLAGS)));
    }

    #[DataProvider('phpBlocks')]
    public function testSyntaxIsValid(CodeBlock $block): void
    {
        self::assertNull(ExampleScript::syntaxError($block), $block->name() . "\n\n" . ExampleScript::render($block));
    }

    public function testExplicitImportsExist(): void
    {
        /** @var array<string, string> $explicit */
        $explicit = require \dirname(__DIR__) . '/Examples/imports.php';
        foreach ($explicit as $short => $class) {
            // un alias d'espace de noms (Prism → Prism\Orm\Mapping) désigne un dossier de classes
            $exists = class_exists($class) || interface_exists($class) || enum_exists($class) || class_exists($class . '\\Entity');
            self::assertTrue($exists, \sprintf('%s => %s does not exist.', $short, $class));
        }
    }

    public function testParsesFencesWithVitePressInfo(): void
    {
        $blocks = DocPages::parse('page.md', "Text\n\n```php:line-numbers no-run [Symfony] {2}\n\$a = 1;\n```\n\n~~~yaml\nprism: ~\n~~~\n\n````md\n```php\nnested\n```\n````\n");

        self::assertCount(3, $blocks);
        self::assertSame(['php', ['no-run'], '$a = 1;', 3], [$blocks[0]->language, $blocks[0]->flags, $blocks[0]->code, $blocks[0]->line]);
        self::assertFalse($blocks[0]->runs());
        self::assertSame(['yaml', 'prism: ~'], [$blocks[1]->language, $blocks[1]->code]);
        // un bloc imbriqué dans une clôture plus longue reste du texte
        self::assertSame(['md', "```php\nnested\n```"], [$blocks[2]->language, $blocks[2]->code]);
    }

    public function testRenderAddsImportsNamespaceAndStrictTypes(): void
    {
        $script = ExampleScript::render(new CodeBlock('page.md', 1, 'php', [], "#[Prism\\Entity]\nfinal class Post\n{\n    #[Prism\\Id, Prism\\GeneratedValue]\n    public ?int \$id = null;\n\n    #[Prism\\ManyToOne]\n    public Customer \$author;\n}"));

        self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Entity;\n\n", $script);
        self::assertStringContainsString("use App\\Entity\\Customer;\n", $script);
        self::assertStringContainsString("use Prism\\Orm\\Mapping as Prism;\n", $script);
        self::assertStringNotContainsString('use App\\Entity\\Post;', $script);
    }

    public function testRenderKeepsTheBlockOwnImportsAndNamespace(): void
    {
        $script = ExampleScript::render(new CodeBlock('page.md', 1, 'php', [], "<?php // prism.php\n\ndeclare(strict_types=1);\n\nnamespace Acme;\n\nuse Acme\\Model\\Order;\n\n\$o = Order_::as('o');\n\$x = new Order();"));

        self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme;\n\nuse App\\Entity\\Order_;\n\n// prism.php", $script);
        self::assertStringNotContainsString('use App\\Entity\\Order;', $script);
        self::assertSame(1, substr_count($script, 'declare(strict_types=1)'));
    }

    public function testNamespaceAliasIsNotImportedOverAFullName(): void
    {
        $script = ExampleScript::render(new CodeBlock('page.md', 1, 'php', [], "return [Prism\\Bundle\\PrismBundle::class => ['all' => true]];"));

        self::assertStringNotContainsString('use Prism\\Orm\\Mapping as Prism;', $script);
    }

    public function testCombineRunsTheBlocksOfASequenceAsOneScript(): void
    {
        $combined = ExampleScript::combine([
            new CodeBlock('guide.md', 3, 'php', [], "<?php\n\nnamespace App\\Entity;\n\nuse Prism\\Orm\\Mapping as Prism;\n\n#[Prism\\Entity]\nfinal class Post\n{\n    use Timestamps;\n}"),
            new CodeBlock('guide.md', 20, 'php', [], "use Prism\\Orm\\Mapping as Prism;\n\n\$post = new Post();"),
        ]);
        $script = ExampleScript::render($combined);

        self::assertSame(['guide.md', 3], [$combined->file, $combined->line]);
        self::assertSame(1, substr_count($script, 'namespace App\\Entity;'));
        self::assertSame(1, substr_count($script, 'use Prism\\Orm\\Mapping as Prism;'));
        self::assertStringContainsString("    use Timestamps;\n", $script, 'a trait stays in its class');
        self::assertLessThan(strpos($script, '$post = new Post();'), strpos($script, 'final class Post'));
    }

    public function testNoRunFragmentsMayBeClassMembers(): void
    {
        $member = 'public function __construct(private EntityManagerInterface $em) {}';

        self::assertNull(ExampleScript::syntaxError(new CodeBlock('page.md', 1, 'php', ['no-run'], $member)));
        self::assertNotNull(ExampleScript::syntaxError(new CodeBlock('page.md', 1, 'php', [], $member)), 'a runnable block is a script, not a class member');
    }
}
