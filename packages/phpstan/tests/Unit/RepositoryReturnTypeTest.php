<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Unit;

use PHPStan\Testing\TypeInferenceTestCase;

/**
 * getRepository() rend le repository déclaré par #[Entity(repositoryClass: …)].
 */
final class RepositoryReturnTypeTest extends TypeInferenceTestCase
{
    /**
     * @return iterable<mixed>
     */
    public static function types(): iterable
    {
        yield from self::gatherAssertTypes(\dirname(__DIR__) . '/data/repository.php');
        yield from self::gatherAssertTypes(\dirname(__DIR__) . '/data/generics.php');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('types')]
    public function testTypes(string $assertType, string $file, mixed ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [\dirname(__DIR__, 2) . '/extension.neon'];
    }
}
