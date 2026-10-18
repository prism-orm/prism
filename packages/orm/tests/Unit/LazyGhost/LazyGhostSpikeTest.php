<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\LazyGhost;

use PHPUnit\Framework\TestCase;
use Prism\Orm\Tests\Unit\LazyGhost\Fixtures\GhostClient;

/**
 * Spike de la phase 0 (ADR 0001) : fige le comportement des lazy ghosts natifs de PHP 8.4
 * sur lequel reposeront LazyFactory et BatchLoader en phase 4.
 *
 * Ces tests documentent PHP, pas du code Prism : s'ils cassent après une montée de version,
 * l'ADR 0001 est à réviser.
 */
final class LazyGhostSpikeTest extends TestCase
{
    /** @var \ReflectionClass<GhostClient> */
    private \ReflectionClass $class;

    private int $initializations = 0;

    protected function setUp(): void
    {
        $this->class = new \ReflectionClass(GhostClient::class);
        $this->initializations = 0;
    }

    // RG-04.7
    public function testIdentifierIsReadableWithoutInitialization(): void
    {
        $ghost = $this->ghost();

        self::assertSame(42, $ghost->id);
        self::assertTrue($this->class->isUninitializedLazyObject($ghost));
        self::assertSame(0, $this->initializations);
    }

    // RG-04.7
    public function testOtherPropertyInitializesOnce(): void
    {
        $ghost = $this->ghost();

        self::assertSame('ACME', $ghost->name);
        self::assertSame('C42', $ghost->code);
        self::assertFalse($this->class->isUninitializedLazyObject($ghost));
        self::assertSame(1, $this->initializations);
    }

    public function testWritingMutablePropertyInitializesFirst(): void
    {
        $ghost = $this->ghost();

        $ghost->name = 'Renamed';

        self::assertSame(1, $this->initializations);
        self::assertSame('Renamed', $ghost->name);
    }

    public function testGhostKeepsItsFinalClass(): void
    {
        $ghost = $this->ghost();

        // aucune sous-classe proxy : l'objet est une vraie instance de la classe finale
        self::assertFalse(new \ReflectionObject($ghost)->getParentClass());
        self::assertTrue(new \ReflectionObject($ghost)->isFinal());
    }

    public function testVarDumpDoesNotInitialize(): void
    {
        $ghost = $this->ghost();

        ob_start();
        var_dump($ghost);
        $dump = (string) ob_get_clean();

        self::assertStringContainsString('lazy ghost object', $dump);
        self::assertTrue($this->class->isUninitializedLazyObject($ghost));
    }

    public function testArrayCastDoesNotInitializeAndOnlyExposesRawValues(): void
    {
        $ghost = $this->ghost();

        self::assertSame(['id' => 42], $this->rawState($ghost));
        self::assertTrue($this->class->isUninitializedLazyObject($ghost));
    }

    public function testCloneInitializesTheOriginalBeforeCopying(): void
    {
        $ghost = $this->ghost();

        $copy = clone $ghost;

        self::assertFalse($this->class->isUninitializedLazyObject($ghost));
        self::assertFalse($this->class->isUninitializedLazyObject($copy));
        self::assertSame('ACME', $copy->name);
        self::assertSame(1, $this->initializations);
    }

    public function testSerializeInitializesAndProducesAPlainObject(): void
    {
        $ghost = $this->ghost();

        $restored = unserialize(serialize($ghost), ['allowed_classes' => [GhostClient::class]]);

        self::assertSame(1, $this->initializations);
        self::assertInstanceOf(GhostClient::class, $restored);
        self::assertFalse($this->class->isUninitializedLazyObject($restored));
        self::assertSame('ACME', $restored->name);
    }

    public function testJsonEncodeInitializes(): void
    {
        $ghost = $this->ghost();

        self::assertSame('{"id":42,"code":"C42","name":"ACME"}', json_encode($ghost));
        self::assertSame(1, $this->initializations);
    }

    public function testComparisonInitializesBothSides(): void
    {
        $a = $this->ghost();
        $b = $this->ghost();

        self::assertSame(0, $a <=> $b);
        self::assertSame(2, $this->initializations);
    }

    // Piège pour les tests Prism : assertEquals() compare l'état brut sans initialiser,
    // deux ghosts de même id sont donc « égaux » même si leurs lignes diffèrent
    public function testPhpUnitAssertEqualsDoesNotInitialize(): void
    {
        $a = $this->ghost();
        $b = $this->ghost();

        self::assertEquals($a, $b);
        self::assertSame(0, $this->initializations);
    }

    // RG-04.8 : l'échec n'est pas mémorisé, chaque accès relance le chargement
    public function testFailingInitializerLeavesGhostUninitializedAndRetries(): void
    {
        $ghost = $this->ghost(fail: true);

        foreach ([1, 2] as $attempt) {
            try {
                $this->class->initializeLazyObject($ghost);
                self::fail('Initializer should have thrown.');
            } catch (\RuntimeException $e) {
                self::assertSame('row gone', $e->getMessage());
            }
            self::assertSame($attempt, $this->initializations);
        }

        self::assertTrue($this->class->isUninitializedLazyObject($ghost));
        self::assertSame(42, $ghost->id);
    }

    // Contrainte pour LazyFactory : l'initializer ne doit pas réécrire l'identifiant readonly
    public function testInitializerCannotRewriteReadonlyIdentifier(): void
    {
        $ghost = $this->class->newLazyGhost(function (GhostClient $client): void {
            $this->class->getProperty('id')->setValue($client, 42);
        });
        $this->class->getProperty('id')->setRawValueWithoutLazyInitialization($ghost, 42);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');

        $this->class->initializeLazyObject($ghost);
    }

    public function testReadonlyPropertyIsImmutableAfterInitialization(): void
    {
        $ghost = $this->ghost();
        $code = $this->class->getProperty('code');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');

        $code->setValue($ghost, 'OTHER');
    }

    /**
     * @return array<mixed>
     */
    private function rawState(object $object): array
    {
        return (array) $object;
    }

    private function ghost(bool $fail = false): GhostClient
    {
        $ghost = $this->class->newLazyGhost(function (GhostClient $client) use ($fail): void {
            ++$this->initializations;
            if ($fail) {
                throw new \RuntimeException('row gone');
            }
            $this->class->getProperty('code')->setValue($client, 'C42');
            $this->class->getProperty('name')->setValue($client, 'ACME');
        });
        $this->class->getProperty('id')->setRawValueWithoutLazyInitialization($ghost, 42);

        return $ghost;
    }
}
