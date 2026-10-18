<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use Prism\Orm\Mapping\Index;
use Prism\Orm\Mapping\LifecycleCallbackAttribute;
use Prism\Orm\Mapping\MappingAttribute;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Address;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Client;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Invoice;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\InvoiceLine;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\InvoiceListener;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Tag;

/**
 * Lit les attributs comme le fera l'AttributeDriver et vérifie qu'une entité réaliste
 * s'instancie sans erreur et couvre les 33 attributs.
 */
final class AttributeUsageTest extends TestCase
{
    private const array FIXTURES = [Invoice::class, InvoiceLine::class, Client::class, Tag::class, Address::class, InvoiceListener::class];

    public function testAllFixtureAttributesInstantiate(): void
    {
        $used = [];

        foreach (self::FIXTURES as $class) {
            foreach ($this->mappingAttributes(new \ReflectionClass($class)) as $attribute) {
                $used[$attribute::class] = true;
            }
        }

        self::assertCount(33, $used, 'Fixtures should exercise every mapping attribute. Missing: ' . implode(', ', $this->missing($used)));
    }

    public function testRepeatableIndexIsReadTwice(): void
    {
        $indexes = (new \ReflectionClass(Invoice::class))->getAttributes(Index::class);

        self::assertCount(2, $indexes);
        self::assertSame("status <> 'draft'", $indexes[1]->newInstance()->where);
    }

    public function testMethodCanCarrySeveralLifecycleCallbacks(): void
    {
        $attributes = (new \ReflectionMethod(Invoice::class, 'onOtherEvents'))
            ->getAttributes(LifecycleCallbackAttribute::class, \ReflectionAttribute::IS_INSTANCEOF);

        self::assertCount(3, $attributes);
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return list<MappingAttribute>
     */
    private function mappingAttributes(\ReflectionClass $class): array
    {
        // les propriétés promues portent les attributs de leur paramètre
        $reflectors = [$class, ...$class->getProperties(), ...$class->getMethods()];
        $attributes = [];

        foreach ($reflectors as $reflector) {
            foreach ($reflector->getAttributes(MappingAttribute::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $attributes[] = $attribute->newInstance();
            }
        }

        return $attributes;
    }

    /**
     * @param array<class-string, true> $used
     *
     * @return list<string>
     */
    private function missing(array $used): array
    {
        $all = array_map(
            static fn (array $row): string => $row[0],
            iterator_to_array(AttributeContractTest::attributes(), false),
        );

        return array_values(array_diff($all, array_keys($used)));
    }
}
