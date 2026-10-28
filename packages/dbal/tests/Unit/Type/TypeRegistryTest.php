<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Type;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Exception\DuplicateTypeException;
use Prism\Dbal\Exception\UnknownTypeException;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\DecimalType;
use Prism\Dbal\Type\Type;
use Prism\Dbal\Type\TypeRegistry;

final class TypeRegistryTest extends TestCase
{
    public function testDefaultsContainTheFifteenNativeTypes(): void
    {
        self::assertSame(
            ['string', 'text', 'integer', 'smallint', 'bigint', 'boolean', 'decimal', 'float', 'datetime_immutable', 'date_immutable', 'time_immutable', 'json', 'uuid', 'binary', 'vector'],
            TypeRegistry::withDefaults()->getNames(),
        );
    }

    public function testNativeTypesAreFinalAndStateless(): void
    {
        foreach (TypeRegistry::withDefaults()->getNames() as $name) {
            $class = new \ReflectionClass(TypeRegistry::withDefaults()->get($name));

            self::assertTrue($class->isFinal(), $class->getName() . ' must be final.');
            self::assertSame([], $class->getProperties(), $class->getName() . ' must be stateless.');
        }
    }

    public function testRegisterReturnsACopy(): void
    {
        $defaults = TypeRegistry::withDefaults();
        $extended = $defaults->register(self::moneyType());

        self::assertNotSame($defaults, $extended);
        self::assertFalse($defaults->has('money'));
        self::assertTrue($extended->has('money'));
        self::assertSame('money', $extended->get('money')->getName());
    }

    public function testDuplicateNamesAreRefused(): void
    {
        $this->expectException(DuplicateTypeException::class);
        $this->expectExceptionMessage('Type "decimal" is already registered');

        TypeRegistry::withDefaults()->register(new DecimalType());
    }

    public function testUnknownTypeListsRegisteredNames(): void
    {
        $this->expectException(UnknownTypeException::class);
        $this->expectExceptionMessage('Unknown type "money". Registered types: string, text,');

        TypeRegistry::withDefaults()->get('money');
    }

    private static function moneyType(): Type
    {
        return new class extends Type {
            public function getName(): string
            {
                return 'money';
            }

            public function toDatabase(mixed $value, ConversionContext $context): ?int
            {
                return \is_int($value) ? $value : null;
            }

            public function toPhp(mixed $value, ConversionContext $context): mixed
            {
                return $value;
            }
        };
    }
}
