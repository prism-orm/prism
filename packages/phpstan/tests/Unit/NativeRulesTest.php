<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Unit;

use PHPStan\Rules\Methods\CallMethodsRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Prism\Orm\Query\Expression\IntExpression;
use Prism\Orm\Query\Expression\StringExpression;
use Prism\Orm\Query\Metamodel\DateTimeField;
use Prism\Orm\Query\Metamodel\DecimalField;
use Prism\Orm\Query\Metamodel\EnumField;
use Prism\Orm\Query\Metamodel\IntField;
use Prism\PHPStan\Tests\Fixtures\Status;

/**
 * RG-05.3 par les seuls génériques et classes de champ : PHPStan refuse une valeur d'un autre
 * type que le champ, avec ou sans l'extension.
 *
 * @extends RuleTestCase<CallMethodsRule>
 */
final class NativeRulesTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        // règle native, lue seulement par ce test : hors de la promesse de compatibilité, sans risque
        // @phpstan-ignore phpstanApi.classConstant
        return self::getContainer()->getByType(CallMethodsRule::class);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [\dirname(__DIR__, 2) . '/extension.neon'];
    }

    public function testValuesOfTheFieldType(): void
    {
        $parameter = static fn (string $name, string $method, string $expected, string $given): string => \sprintf('Parameter #1 $%s of method %s() expects %s, %s given.', $name, $method, $expected, $given);

        $this->analyse([\dirname(__DIR__) . '/data/value-types.php'], [
            [$parameter('value', EnumField::class . '<' . Status::class . '>::eq', Status::class, 'string'), 16],
            [$parameter('value', DecimalField::class . '::gte', 'numeric-string', '100.0'), 18],
            [$parameter('value', DateTimeField::class . '::lt', 'DateTimeImmutable', 'DateTime'), 20],
            [$parameter('values', IntField::class . '::in', 'list<int>', "array{'1'}"), 22],
            [$parameter('value', IntExpression::class . '::gt', 'int', 'string'), 24],
            [$parameter('value', StringExpression::class . '::eq', 'string', 'int'), 26],
        ]);
    }
}
