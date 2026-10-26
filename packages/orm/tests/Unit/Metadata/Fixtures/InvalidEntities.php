<?php

declare(strict_types=1);

/**
 * Entités invalides, une erreur chacune ; chargées par AttributeDriverTest (pas d'autoload).
 */

namespace Prism\Orm\Tests\Unit\Metadata\Fixtures\Invalid;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class MutableDate
{
    #[Prism\Id]
    public int $id = 0;
    #[Prism\Column]
    public ?\DateTime $at = null;
}

#[Prism\Entity]
final class MutableDateWithExplicitType
{
    #[Prism\Id]
    public int $id = 0;
    #[Prism\Column(type: 'datetime_immutable')]
    public ?\DateTime $at = null;
}

#[Prism\Entity]
final class UnionType
{
    #[Prism\Id]
    public int $id = 0;
    #[Prism\Column]
    public int|string $value = 0;
}

#[Prism\Entity]
final class ObjectType
{
    #[Prism\Id]
    public int $id = 0;
    #[Prism\Column]
    public ?\stdClass $value = null;
}

#[Prism\Entity]
final class NotNullOnNullable
{
    #[Prism\Id]
    public int $id = 0;
    #[Prism\Column(nullable: false)]
    public ?string $value = null;
}

#[Prism\Entity]
final class NullableOnNotNull
{
    #[Prism\Id]
    public int $id = 0;
    #[Prism\Column(nullable: true)]
    public string $value = '';
}

#[Prism\Entity]
final class NullableId
{
    #[Prism\Id, Prism\Column(nullable: true)]
    public ?int $id = null;
}

#[Prism\Entity]
final class NoId
{
    #[Prism\Column]
    public string $name = '';
}

#[Prism\Entity]
final class TwoIds
{
    #[Prism\Id]
    public int $a = 0;
    #[Prism\Id]
    public int $b = 0;
}

#[Prism\Entity]
final class CallbackWithoutFlag
{
    #[Prism\Id]
    public int $id = 0;

    #[Prism\PrePersist]
    public function touch(): void
    {
    }
}

#[Prism\Entity]
final class EmbeddedNotEmbeddable
{
    #[Prism\Id]
    public int $id = 0;
    /** @var \ArrayObject<int, string>|null */
    #[Prism\Embedded]
    public ?\ArrayObject $value = null;
}

#[Prism\Embeddable]
final class OuterEmbeddable
{
    #[Prism\Embedded]
    public ?\Prism\Orm\Tests\Unit\Mapping\Fixtures\Address $inner = null;
    #[Prism\Column]
    public string $label = '';
}

#[Prism\Entity]
final class NestedEmbeddable
{
    #[Prism\Id]
    public int $id = 0;
    #[Prism\Embedded]
    public ?OuterEmbeddable $outer = null;
}

abstract class MappedParent
{
    #[Prism\Column]
    public string $inherited = '';
}

#[Prism\Entity]
final class ChildOfMappedParent extends MappedParent
{
    #[Prism\Id]
    public int $id = 0;
}

#[Prism\Entity]
final class BadDefault
{
    #[Prism\Id]
    public int $id = 0;
    /** @var list<int> */
    #[Prism\Column(type: 'json', default: [1])]
    public array $value = [];
}

final class NotAnEntity
{
}

#[Prism\Entity]
#[Prism\Tenanted(strictForeignKeys: true)]
final class StrictTenantForeignKeys
{
    #[Prism\Id]
    public int $id = 0;
}
