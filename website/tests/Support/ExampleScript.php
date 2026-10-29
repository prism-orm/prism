<?php

declare(strict_types=1);

namespace Prism\Website\Tests\Support;

/**
 * Un bloc PHP de la documentation rendu en script exécutable : les exemples restent courts
 * (sans « use » ni « declare »), le script reçoit les imports des noms qu'il emploie.
 *
 * - Imports : la table explicite de website/tests/Examples/imports.php, puis les classes de la
 *   démo (App\, metamodel compris), puis les classes @api de Prism dont le nom court est unique.
 * - Un nom déclaré par le bloc, ou déjà importé par lui, n'est jamais importé.
 * - Un bloc qui déclare une classe sans espace de noms est placé dans App\Entity, celui des
 *   entités de la démo : ses relations vers elles se valident comme dans la démo.
 *
 * @internal
 */
final class ExampleScript
{
    public const string DEFAULT_NAMESPACE = 'App\\Entity';

    /** @var array<string, string>|null nom court → classe */
    private static ?array $imports = null;

    public static function render(CodeBlock $block): string
    {
        $code = $block->code;
        // l'ouverture et le declare viennent du script ; un commentaire sur la ligne d'ouverture est gardé
        $code = (string) preg_replace('/^\s*<\?php\b[ \t]*/', '', $code);
        $code = (string) preg_replace('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*$/m', '', $code);

        $namespace = null;
        if (1 === preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;\s*$/m', $code, $m)) {
            $namespace = $m[1];
            $code = (string) preg_replace('/^\s*namespace\s+[\w\\\\]+\s*;\s*$/m', '', $code, 1);
        }
        $declared = self::declaredNames($code);
        if (null === $namespace && [] !== $declared) {
            $namespace = self::DEFAULT_NAMESPACE;
        }

        preg_match_all('/^\s*use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $code, $uses, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);
        $taken = $declared;
        foreach ($uses as $use) {
            $taken[] = $use[2] ?? SourceClasses::shortName($use[1]);
        }

        $imports = [];
        foreach (self::imports() as $short => $class) {
            if (\in_array($short, $taken, true) || 1 !== preg_match('/(?<![\w\\\\$>:])' . preg_quote($short, '/') . '\b/', $code)) {
                continue;
            }
            // un alias d'espace de noms (Prism → Prism\Orm\Mapping) seulement si chaque Prism\X en est une classe :
            // Prism\Bundle\PrismBundle::class reste un nom complet
            if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
                preg_match_all('/(?<![\w\\\\$>:])' . preg_quote($short, '/') . '\\\\(\w+)(\\\\)?/', $code, $qualified, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);
                foreach ($qualified as $name) {
                    if (null !== $name[2] || !class_exists($class . '\\' . $name[1])) {
                        continue 2;
                    }
                }
            }
            $imports[] = 'use ' . $class . (SourceClasses::shortName($class) === $short ? '' : ' as ' . $short) . ';';
        }
        sort($imports);

        return "<?php\n\ndeclare(strict_types=1);\n\n"
            . (null === $namespace ? '' : 'namespace ' . $namespace . ";\n\n")
            . ([] === $imports ? '' : implode("\n", $imports) . "\n\n")
            . trim($code, "\n") . "\n";
    }

    /**
     * Les blocs exécutables d'une page en séquence, réunis en un seul : chacun perd son ouverture,
     * son declare et son espace de noms (un seul pour la page), ses « use » remontent en tête.
     *
     * @param non-empty-list<CodeBlock> $blocks
     */
    public static function combine(array $blocks): CodeBlock
    {
        $namespaces = [];
        $uses = [];
        $parts = [];
        foreach ($blocks as $block) {
            $code = (string) preg_replace('/^\s*<\?php\b[ \t]*/', '', $block->code);
            $code = (string) preg_replace('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*$/m', '', $code);
            if (1 === preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;\s*$/m', $code, $m)) {
                $namespaces[] = $m[1];
                $code = (string) preg_replace('/^\s*namespace\s+[\w\\\\]+\s*;\s*$/m', '', $code, 1);
            }
            // les imports seulement, en début de ligne ; un « use » de trait est indenté dans sa classe
            preg_match_all('/^use\s+[\w\\\\]+(?:\s+as\s+\w+)?\s*;\s*$/m', $code, $m);
            array_push($uses, ...array_map('trim', $m[0]));
            $code = (string) preg_replace('/^use\s+[\w\\\\]+(?:\s+as\s+\w+)?\s*;\s*$/m', '', $code);
            $parts[] = '// ' . $block->name() . "\n" . trim($code, "\n");
        }
        $namespaces = array_values(array_unique($namespaces));
        if (\count($namespaces) > 1) {
            throw new \LogicException(\sprintf('%s: the blocks of a sequence share one namespace, not %s.', $blocks[0]->file, implode(', ', $namespaces)));
        }
        $head = [...([] === $namespaces ? [] : ['namespace ' . $namespaces[0] . ';']), ...array_values(array_unique($uses))];

        return new CodeBlock($blocks[0]->file, $blocks[0]->line, 'php', [], implode("\n\n", [...([] === $head ? [] : [implode("\n", $head)]), ...$parts]));
    }

    /**
     * L'erreur de syntaxe du script, ou null. Un bloc no-run peut n'être qu'un fragment de classe
     * (méthode, constructeur) : il est aussi essayé dans le corps d'une classe.
     */
    public static function syntaxError(CodeBlock $block): ?string
    {
        $error = self::parse(self::render($block));
        if (null === $error || $block->runs()) {
            return $error;
        }
        $fragment = new CodeBlock($block->file, $block->line, $block->language, $block->flags, "final class DocsFragment\n{\n" . $block->code . "\n}");

        return null === self::parse(self::render($fragment)) ? null : $error;
    }

    /**
     * @return array<string, string> nom court → classe
     */
    public static function imports(): array
    {
        if (null !== self::$imports) {
            return self::$imports;
        }
        $root = DocPages::root();

        // les classes @api de Prism, nom court unique seulement : une ambiguïté se règle dans imports.php
        $api = [];
        foreach (array_keys(SourceClasses::api()) as $class) {
            $api[SourceClasses::shortName($class)][] = $class;
        }
        $imports = [];
        foreach ($api as $short => $classes) {
            if (1 === \count($classes)) {
                $imports[$short] = $classes[0];
            }
        }

        // l'application d'exemple (ADR 0023), et le metamodel de ses entités (App\Entity\Order_)
        foreach (array_keys(SourceClasses::in($root . '/website/tests/App')) as $appClass) {
            $imports[SourceClasses::shortName($appClass)] = $appClass;
            if (str_starts_with($appClass, 'App\\Entity\\')) {
                $imports[SourceClasses::shortName($appClass) . '_'] = $appClass . '_';
            }
        }

        /** @var array<string, string> $explicit */
        $explicit = require \dirname(__DIR__) . '/Examples/imports.php';

        return self::$imports = [...$imports, ...$explicit];
    }

    /**
     * @return list<string>
     */
    private static function declaredNames(string $code): array
    {
        preg_match_all('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $code, $m);

        return $m[1];
    }

    private static function parse(string $script): ?string
    {
        try {
            \PhpToken::tokenize($script, \TOKEN_PARSE);

            return null;
        } catch (\ParseError $e) {
            return \sprintf('%s (line %d of the rendered script)', $e->getMessage(), $e->getLine());
        }
    }
}
