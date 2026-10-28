<?php

declare(strict_types=1);

namespace Prism\Website\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Bundle\DependencyInjection\PrismConfiguration;
use Prism\Exception\PrismException;
use Prism\Website\Tests\Support\DocPages;
use Prism\Website\Tests\Support\SourceClasses;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\PrototypedArrayNode;

/**
 * RG-10a.4 : les pages de référence suivent le code. Un attribut, un paramètre, un événement,
 * une exception, une erreur PHPStan ou une option de prism.yaml ajoutés sans leur documentation
 * font échouer ce test ; une entrée documentée qui n'existe plus aussi. Une page encore à écrire
 * (phase 10a, étape 6) saute son test.
 *
 * Les commandes sont vérifiées par CommandsDocumentationTest (packages/migrations).
 */
final class ReferenceCompletenessTest extends TestCase
{
    private const string ATTRIBUTES = 'website/reference/attributes.md';

    private const string EXCEPTIONS = 'website/reference/exceptions.md';

    private const string EVENTS = 'packages/orm/docs/reference/events.md';

    private const string CONFIGURATION = 'packages/bundle/docs/reference/configuration.md';

    private const string PHPSTAN = 'packages/phpstan/docs/reference/errors.md';

    /** La forme courte de prism.yaml, convertie avant l'arbre (PrismConfiguration::expandShortForm). */
    private const array SHORT_FORM = ['prism.url', 'prism.entity_dirs', 'prism.migrations.dir', 'prism.migrations.namespace'];

    public function testEveryAttributeAndParameterIsDocumented(): void
    {
        $page = self::page(self::ATTRIBUTES);
        $sections = self::sections($page, '/^#{2,4} .*`#\[(\w+)\]`/m');

        $expected = [];
        $missing = [];
        foreach (self::attributes() as $name => $class) {
            $expected[] = $name;
            $parameters = new \ReflectionClass($class)->getConstructor()?->getParameters() ?? [];
            foreach ($parameters as $parameter) {
                if (!isset($sections[$name]) || 1 !== preg_match('/^\| *`' . preg_quote($parameter->getName(), '/') . '` *\|/m', $sections[$name])) {
                    $missing[] = '#[' . $name . '(' . $parameter->getName() . ')]';
                }
            }
        }

        self::assertSameNames($expected, array_keys($sections), 'attributes');
        self::assertSame([], $missing, 'each constructor parameter has a row "| `name` |" in its attribute section');
    }

    public function testEveryEventIsDocumented(): void
    {
        $sections = self::sections(self::page(self::EVENTS), '/^#{2,4} `(\w+)`/m');
        $events = array_keys(array_filter(self::apiClasses(), static fn (\ReflectionClass $c): bool => self::isConcrete($c)
            && (str_ends_with($c->getShortName(), 'Event') || str_ends_with($c->getShortName(), 'Detected'))));

        self::assertSameNames($events, array_keys($sections), 'events');
    }

    public function testEveryExceptionIsDocumented(): void
    {
        $sections = self::sections(self::page(self::EXCEPTIONS), '/^#{2,4} `(\w+)`/m');
        $exceptions = array_keys(array_filter(self::apiClasses(), static fn (\ReflectionClass $c): bool => self::isConcrete($c)
            && $c->implementsInterface(\Throwable::class) && $c->implementsInterface(PrismException::class)));

        self::assertSameNames($exceptions, array_keys($sections), 'exceptions');
    }

    /**
     * Ni interface ni classe abstraite ; une exception à constructeur privé, créée par ses
     * méthodes statiques, en est une.
     *
     * @param \ReflectionClass<object> $class
     */
    private static function isConcrete(\ReflectionClass $class): bool
    {
        return !$class->isInterface() && !$class->isAbstract() && !$class->isTrait() && !$class->isEnum();
    }

    public function testEveryPhpStanErrorIsDocumented(): void
    {
        $sections = self::sections(self::page(self::PHPSTAN), '/^#{2,4} `(prism\.[\w.]+)`/m');
        $identifiers = [];
        foreach (SourceClasses::in(DocPages::root() . '/packages/phpstan/src') as $source) {
            preg_match_all("/identifier\\('(prism\\.[\\w.]+)'\\)/", $source, $m);
            array_push($identifiers, ...$m[1]);
        }

        self::assertNotSame([], $identifiers);
        self::assertSameNames(array_values(array_unique($identifiers)), array_keys($sections), 'PHPStan errors');
    }

    public function testEveryConfigurationOptionIsDocumented(): void
    {
        $sections = self::sections(self::page(self::CONFIGURATION), '/^#{2,4} `(prism(?:\.[\w<>]+)+)`/m');
        $tree = new TreeBuilder('prism');
        PrismConfiguration::define($tree->getRootNode());
        $options = [...self::SHORT_FORM, ...self::options($tree->buildTree(), 'prism')];

        self::assertSameNames($options, array_keys($sections), 'prism.yaml options');
    }

    private static function page(string $file): string
    {
        if (!DocPages::exists($file)) {
            self::markTestSkipped(\sprintf('%s is not written yet (phase 10a, step 6).', $file));
        }

        return DocPages::read($file);
    }

    /**
     * Le texte de chaque section dont le titre correspond au motif, jusqu'au titre suivant.
     *
     * @return array<string, string> nom → section
     */
    private static function sections(string $page, string $headingPattern): array
    {
        preg_match_all('/^#{1,4} /m', $page, $headings, \PREG_OFFSET_CAPTURE);
        $starts = array_map(static fn (array $h): int => $h[1], $headings[0]);
        $sections = [];
        preg_match_all($headingPattern, $page, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $start = $match[0][1];
            $next = array_values(array_filter($starts, static fn (int $s): bool => $s > $start))[0] ?? \strlen($page);
            $sections[$match[1][0]] = substr($page, $start, $next - $start);
        }

        return $sections;
    }

    /**
     * @param list<string> $expected
     * @param list<string> $documented
     */
    private static function assertSameNames(array $expected, array $documented, string $what): void
    {
        self::assertSame(
            ['missing' => array_values(array_diff($expected, $documented)), 'unknown' => array_values(array_diff($documented, $expected))],
            ['missing' => [], 'unknown' => []],
            \sprintf('%s: one section per item, none for an item that does not exist', $what),
        );
    }

    /**
     * @return array<string, class-string> nom court → attribut @api, hors phase 9
     */
    private static function attributes(): array
    {
        $attributes = [];
        foreach (self::apiClasses() as $class) {
            if ([] !== $class->getAttributes(\Attribute::class)) {
                $attributes[$class->getShortName()] = $class->getName();
            }
        }

        return $attributes;
    }

    /**
     * @return array<string, \ReflectionClass<object>> nom court → classe @api
     */
    private static function apiClasses(): array
    {
        $classes = [];
        foreach (array_keys(SourceClasses::api()) as $class) {
            $reflection = new \ReflectionClass($class);
            $classes[$reflection->getShortName()] = $reflection;
        }

        return $classes;
    }

    /**
     * Les chemins des options, « <name> » pour la clé d'une collection : prism.connections.<name>.url.
     *
     * @return list<string>
     */
    private static function options(NodeInterface $node, string $path): array
    {
        if ($node instanceof PrototypedArrayNode) {
            $prototype = $node->getPrototype();
            if (!$prototype instanceof ArrayNode) {
                return [$path];
            }

            return self::children($prototype, $path . '.<' . ($node->getKeyAttribute() ?? 'key') . '>');
        }
        if ($node instanceof ArrayNode) {
            return self::children($node, $path);
        }

        return [$path];
    }

    /**
     * @return list<string>
     */
    private static function children(ArrayNode $node, string $path): array
    {
        $options = [];
        foreach ($node->getChildren() as $name => $child) {
            array_push($options, ...self::options($child, $path . '.' . $name));
        }

        return $options;
    }
}
