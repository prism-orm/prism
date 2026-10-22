<?php

declare(strict_types=1);

namespace Prism\Bundle\Maker;

use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;

/**
 * Le code PHP d'un champ ou d'un côté de relation, comme l'écrirait un développeur Prism :
 * propriétés publiques typées, mapping minimal (le type n'est écrit que s'il ne se déduit pas du
 * type PHP, RG-02.1 ; la nullabilité vient du « ? », RG-02.3).
 *
 * @internal
 */
final class FieldWriter
{
    /** type Prism → type PHP */
    public const array TYPES = [
        'string' => 'string', 'text' => 'string', 'integer' => 'int', 'smallint' => 'int', 'bigint' => 'int',
        'boolean' => 'bool', 'decimal' => 'string', 'float' => 'float', 'datetime_immutable' => '\DateTimeImmutable',
        'date_immutable' => '\DateTimeImmutable', 'time_immutable' => '\DateTimeImmutable', 'json' => 'array',
        'uuid' => 'string', 'binary' => 'string',
    ];

    public const array RELATIONS = ['ManyToOne', 'OneToMany', 'ManyToMany', 'OneToOne'];

    // ce que le driver déduit du type PHP seul (RG-02.1)
    private const array INFERRED = ['string' => 'string', 'int' => 'integer', 'bool' => 'boolean', 'float' => 'float', '\DateTimeImmutable' => 'datetime_immutable', 'array' => 'json'];

    public static function field(string $name, string $type, bool $nullable, ?int $length = null, ?int $precision = null, ?int $scale = null): string
    {
        $php = self::TYPES[$type] ?? throw new \InvalidArgumentException(\sprintf('Unknown type "%s".', $type));
        $arguments = [];
        if (self::INFERRED[$php] !== $type) {
            $arguments[] = \sprintf("type: '%s'", $type);
        }
        if ('string' === $type) {
            $arguments[] = 'length: ' . ($length ?? 255);
        }
        if ('decimal' === $type) {
            $arguments[] = 'precision: ' . ($precision ?? 10);
            $arguments[] = 'scale: ' . ($scale ?? 2);
        }

        return implode("\n", array_filter([
            'array' === $php ? ($nullable ? '/** @var array<mixed>|null */' : '/** @var array<mixed> */') : null,
            '#[Prism\Column' . ([] === $arguments ? '' : '(' . implode(', ', $arguments) . ')') . ']',
            \sprintf('public %s%s $%s%s;', $nullable ? '?' : '', $php, $name, $nullable ? ' = null' : ''),
        ]));
    }

    /**
     * Un côté ToOne : ManyToOne (propriétaire), ou OneToOne propriétaire (inversedBy) ou inverse
     * (mappedBy, toujours nullable).
     */
    public static function toOne(string $kind, string $name, string $target, bool $nullable, ?string $inversedBy = null, ?string $mappedBy = null): string
    {
        $arguments = array_filter(['mappedBy' => $mappedBy, 'inversedBy' => $inversedBy], static fn (?string $v): bool => null !== $v);
        $nullable = $nullable || null !== $mappedBy;

        return implode("\n", [
            \sprintf('#[Prism\%s%s]', $kind, [] === $arguments ? '' : '(' . implode(', ', array_map(static fn (string $k, string $v): string => \sprintf("%s: '%s'", $k, $v), array_keys($arguments), $arguments)) . ')'),
            \sprintf('public %s%s $%s%s;', $nullable ? '?' : '', EntitySource::shortName($target), $name, $nullable ? ' = null' : ''),
        ]);
    }

    /**
     * Un côté collection : OneToMany (inverse, mappedBy) ou ManyToMany (inversedBy ou mappedBy).
     */
    public static function collection(string $kind, string $name, string $target, ?string $mappedBy = null, ?string $inversedBy = null): string
    {
        $short = EntitySource::shortName($target);
        $arguments = [$short . '::class'];
        if (null !== $mappedBy) {
            $arguments[] = \sprintf("mappedBy: '%s'", $mappedBy);
        }
        if (null !== $inversedBy) {
            $arguments[] = \sprintf("inversedBy: '%s'", $inversedBy);
        }

        return implode("\n", [
            \sprintf('/** @var Collection<int, %s> */', $short),
            \sprintf('#[Prism\%s(targetEntity: %s)]', $kind, implode(', ', $arguments)),
            \sprintf('public Collection $%s;', $name),
        ]);
    }

    // les imports et l'initialisation d'une collection, dans le constructeur
    public static function addCollection(EntitySource $source, string $code, string $name): void
    {
        $source->addUse(ArrayCollection::class);
        $source->addUse(Collection::class);
        $source->addProperty($code);
        $source->addToConstructor(\sprintf('$this->%s = new ArrayCollection();', $name));
    }
}
