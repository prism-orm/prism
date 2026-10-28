<?php

declare(strict_types=1);

namespace Prism\Website\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Website\Tests\Support\DocPages;
use Prism\Website\Tests\Support\SourceClasses;

/**
 * ADR 0020 : l'API publique figée à la 1.0. Chaque classe des paquets est marquée @api ou.
 *
 * @internal, et une méthode ou une propriété publique d'une classe @api n'expose aucune classe
 *
 * @internal dans sa signature, sauf à être elle-même marquée @internal
 */
final class ApiSurfaceTest extends TestCase
{
    /**
     * @return array<string, string> classe → source, de tous les paquets
     */
    private static function sources(): array
    {
        $classes = [];
        $directories = glob(DocPages::root() . '/packages/*/src', \GLOB_ONLYDIR);
        foreach (false === $directories ? [] : $directories as $src) {
            $classes += SourceClasses::in($src);
        }

        return $classes;
    }

    public function testEveryClassIsApiOrInternal(): void
    {
        $unmarked = array_keys(array_filter(self::sources(), static fn (string $source): bool => null === SourceClasses::tag($source)));

        self::assertSame([], $unmarked, 'each class of packages/*/src has @api or @internal in its docblock');
    }

    public function testApiSignaturesExposeNoInternalClass(): void
    {
        $internal = [];
        foreach (self::sources() as $class => $source) {
            if ('internal' === SourceClasses::tag($source)) {
                $internal[$class] = true;
            }
        }

        $leaks = [];
        foreach (array_keys(SourceClasses::api()) as $class) {
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || str_contains((string) $method->getDocComment(), '@internal')) {
                    continue;
                }
                $types = [$method->getReturnType(), ...array_map(static fn (\ReflectionParameter $p): ?\ReflectionType => $p->getType(), $method->getParameters())];
                foreach (self::classNames($types) as $used) {
                    if (isset($internal[$used])) {
                        $leaks[] = \sprintf('%s::%s() uses %s', $class, $method->getName(), $used);
                    }
                }
            }
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->getDeclaringClass()->getName() !== $class || str_contains((string) $property->getDocComment(), '@internal')) {
                    continue;
                }
                foreach (self::classNames([$property->getType()]) as $used) {
                    if (isset($internal[$used])) {
                        $leaks[] = \sprintf('%s::$%s uses %s', $class, $property->getName(), $used);
                    }
                }
            }
        }
        sort($leaks);

        self::assertSame([], $leaks, 'an @api signature exposes an @internal class: mark the member @internal, or the class @api');
    }

    /**
     * @param list<\ReflectionType|null> $types
     *
     * @return list<string>
     */
    private static function classNames(array $types): array
    {
        $names = [];
        foreach ($types as $type) {
            $parts = $type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType ? $type->getTypes() : [$type];
            foreach ($parts as $part) {
                if ($part instanceof \ReflectionNamedType && !$part->isBuiltin()) {
                    $names[] = $part->getName();
                } elseif ($part instanceof \ReflectionIntersectionType) {
                    array_push($names, ...self::classNames(array_values($part->getTypes())));
                }
            }
        }

        return $names;
    }
}
