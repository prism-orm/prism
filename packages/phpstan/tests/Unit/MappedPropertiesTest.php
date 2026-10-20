<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Unit;

use PHPStan\Rules\Properties\UninitializedPropertyRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Prism\PHPStan\Tests\Fixtures\Initialized\Member;
use Prism\PHPStan\Tests\Fixtures\Initialized\Plain;

/**
 * Les propriétés mappées d'une entité sont hydratées sans constructeur (RG-03.12) : l'extension
 * les déclare initialisées ; une propriété non mappée reste vérifiée (RG-02.7).
 *
 * @extends RuleTestCase<UninitializedPropertyRule>
 */
final class MappedPropertiesTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        // règle native, lue seulement par ce test : hors de la promesse de compatibilité, sans risque
        // @phpstan-ignore phpstanApi.classConstant
        return self::getContainer()->getByType(UninitializedPropertyRule::class);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [\dirname(__DIR__) . '/uninitialized.neon'];
    }

    public function testMappedPropertiesAreInitialized(): void
    {
        $fixtures = \dirname(__DIR__) . '/Fixtures';
        $message = static fn (string $class, string $property): string => \sprintf('Class %s has an uninitialized property $%s. Give it default value or assign it in the constructor.', $class, $property);

        $this->analyse([$fixtures . '/Initialized/Member.php', $fixtures . '/Initialized/Plain.php', $fixtures . '/Client.php', $fixtures . '/Invoice.php', $fixtures . '/Address.php'], [
            [$message(Member::class, 'nickname'), 19],
            [$message(Plain::class, 'name'), 10],
        ]);
    }
}
