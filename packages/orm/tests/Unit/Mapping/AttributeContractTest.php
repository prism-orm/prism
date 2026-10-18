<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Orm\Mapping;
use Prism\Orm\Mapping\AssociationAttribute;
use Prism\Orm\Mapping\LifecycleCallbackAttribute;
use Prism\Orm\Mapping\MappingAttribute;

/**
 * Vérifie le contrat commun des 33 attributs de mapping de la 1.0 (RT-06, RT-07).
 */
final class AttributeContractTest extends TestCase
{
    private const int CLASS_TARGET = \Attribute::TARGET_CLASS;
    private const int CLASS_REPEATABLE = \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE;
    private const int PROPERTY = \Attribute::TARGET_PROPERTY;
    private const int METHOD = \Attribute::TARGET_METHOD;

    /**
     * @return iterable<string, array{class-string, int}>
     */
    public static function attributes(): iterable
    {
        $map = [
            // classe : structure
            Mapping\Entity::class => self::CLASS_TARGET,
            Mapping\Table::class => self::CLASS_TARGET,
            Mapping\Index::class => self::CLASS_REPEATABLE,
            Mapping\UniqueConstraint::class => self::CLASS_REPEATABLE,
            Mapping\Embeddable::class => self::CLASS_TARGET,
            Mapping\HasLifecycleCallbacks::class => self::CLASS_TARGET,
            Mapping\EntityListeners::class => self::CLASS_TARGET,
            // classe : piliers et comportements
            Mapping\Tenanted::class => self::CLASS_TARGET,
            Mapping\Auditable::class => self::CLASS_TARGET,
            Mapping\Timestampable::class => self::CLASS_TARGET,
            Mapping\Blameable::class => self::CLASS_TARGET,
            Mapping\SoftDeletable::class => self::CLASS_TARGET,
            Mapping\FullTextIndex::class => self::CLASS_REPEATABLE,
            // propriété
            Mapping\Id::class => self::PROPERTY,
            Mapping\GeneratedValue::class => self::PROPERTY,
            Mapping\Column::class => self::PROPERTY,
            Mapping\Version::class => self::PROPERTY,
            Mapping\Embedded::class => self::PROPERTY,
            Mapping\VectorIndex::class => self::PROPERTY,
            Mapping\EmbeddedVector::class => self::PROPERTY,
            // relations
            Mapping\ManyToOne::class => self::PROPERTY,
            Mapping\OneToOne::class => self::PROPERTY,
            Mapping\OneToMany::class => self::PROPERTY,
            Mapping\ManyToMany::class => self::PROPERTY,
            Mapping\JoinTable::class => self::PROPERTY,
            Mapping\OrderBy::class => self::PROPERTY,
            // méthodes
            Mapping\PrePersist::class => self::METHOD,
            Mapping\PostPersist::class => self::METHOD,
            Mapping\PreUpdate::class => self::METHOD,
            Mapping\PostUpdate::class => self::METHOD,
            Mapping\PreRemove::class => self::METHOD,
            Mapping\PostRemove::class => self::METHOD,
            Mapping\PostLoad::class => self::METHOD,
        ];

        foreach ($map as $class => $flags) {
            yield new \ReflectionClass($class)->getShortName() => [$class, $flags];
        }
    }

    public function testThereAreExactly33Attributes(): void
    {
        self::assertCount(33, iterator_to_array(self::attributes()));
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('attributes')]
    public function testAttributeIsFinalAndImplementsMarker(string $class, int $flags): void
    {
        $reflection = new \ReflectionClass($class);

        self::assertTrue($reflection->isFinal(), $class . ' must be final (RT-06).');
        self::assertTrue($reflection->implementsInterface(MappingAttribute::class));
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('attributes')]
    public function testAttributeDeclaresExpectedTargets(string $class, int $flags): void
    {
        $attributes = new \ReflectionClass($class)->getAttributes(\Attribute::class);

        self::assertCount(1, $attributes);
        self::assertSame($flags, $attributes[0]->newInstance()->flags);
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('attributes')]
    public function testEveryPublicPropertyIsReadonly(string $class, int $flags): void
    {
        $mutable = [];
        foreach (new \ReflectionClass($class)->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isReadOnly()) {
                $mutable[] = $property->getName();
            }
        }

        // un attribut marqueur (sans propriété) passe aussi : l'assertion est toujours exécutée
        self::assertSame([], $mutable, \sprintf('%s: public properties must be readonly (RT-07).', $class));
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('attributes')]
    public function testSpecialisedInterfacesMatchTarget(string $class, int $flags): void
    {
        $reflection = new \ReflectionClass($class);

        self::assertSame(self::METHOD === $flags, $reflection->implementsInterface(LifecycleCallbackAttribute::class));

        $isRelation = \in_array($class, [Mapping\ManyToOne::class, Mapping\OneToOne::class, Mapping\OneToMany::class, Mapping\ManyToMany::class], true);
        self::assertSame($isRelation, $reflection->implementsInterface(AssociationAttribute::class));
    }
}
