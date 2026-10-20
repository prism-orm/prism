<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Debug;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Orm\Debug\N1Detector;
use Prism\Orm\Debug\NPlusOneDetected;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Tests\Support\TestDispatcher;

/**
 * RG-04.15 : requêtes identiques comptées par SQL normalisé, détection au-delà du seuil avec
 * l'origine dans le code de l'application.
 */
final class N1DetectorTest extends TestCase
{
    private TestDispatcher $events;

    protected function setUp(): void
    {
        $this->events = new TestDispatcher();
    }

    private function execute(N1Detector $detector, string $sql, int $times): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $detector->queryExecuted($sql, [':id' => $i], 0.1);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapes(): iterable
    {
        yield 'named parameters' => ['SELECT "id" FROM "seller" WHERE "id" = :prism_1', 'SELECT "id" FROM "seller" WHERE "id" = ?'];
        yield 'IN list of any length' => ['SELECT * FROM t WHERE id IN (:prism_1, :prism_2, :prism_3)', 'SELECT * FROM t WHERE id IN (?)'];
        yield 'positional parameters in IN' => ['SELECT * FROM t WHERE id in ( ?,? )', 'SELECT * FROM t WHERE id IN (?)'];
        yield 'literals of raw SQL' => ["SELECT * FROM t2 WHERE code = 'O''Hara' AND total > 12.50 AND qty = 3", 'SELECT * FROM t2 WHERE code = ? AND total > ? AND qty = ?'];
        yield 'quoted names keep their digits' => ['SELECT "col1", `t2`.x FROM "t3"', 'SELECT "col1", `t2`.x FROM "t3"'];
        yield 'whitespace' => ["SELECT *\n  FROM t\tWHERE id = :id ", 'SELECT * FROM t WHERE id = ?'];
    }

    #[DataProvider('shapes')]
    public function testNormalizedShape(string $sql, string $expected): void
    {
        self::assertSame($expected, N1Detector::normalize($sql));
    }

    public function testNothingUpToTheThreshold(): void
    {
        $detector = new N1Detector($this->events);

        $this->execute($detector, 'SELECT * FROM seller WHERE id = :prism_1', 5);

        self::assertSame([], $detector->getDetections());
        self::assertSame([], $this->events->dispatched);
    }

    public function testDetectedOnceBeyondTheThresholdWithItsOrigin(): void
    {
        $detector = new N1Detector($this->events);

        $line = __LINE__ + 2;
        for ($id = 1; $id <= 8; ++$id) {
            $detector->queryExecuted('SELECT * FROM seller WHERE id = :prism_1', [':prism_1' => $id], 0.2);
        }

        self::assertCount(1, $this->events->dispatched, 'published once, when the threshold is crossed');
        $event = $this->events->dispatched[0];
        self::assertInstanceOf(NPlusOneDetected::class, $event);
        self::assertSame(['SELECT * FROM seller WHERE id = ?', 6, __FILE__ . ':' . $line], [$event->sql, $event->count, $event->origin]);
        self::assertSame(8, $detector->getDetections()[0]->count, 'the detection keeps counting');
    }

    public function testEachShapeIsCountedApart(): void
    {
        $detector = new N1Detector($this->events, threshold: 2);

        $this->execute($detector, 'SELECT * FROM seller WHERE id = :prism_1', 3);
        $this->execute($detector, 'SELECT * FROM depot WHERE id = :prism_1', 2);
        $this->execute($detector, 'SELECT * FROM country WHERE id IN (:prism_1)', 2);
        $this->execute($detector, 'SELECT * FROM country WHERE id IN (:prism_1, :prism_2)', 1);

        self::assertSame(['SELECT * FROM seller WHERE id = ?', 'SELECT * FROM country WHERE id IN (?)'], array_map(static fn (NPlusOneDetected $d): string => $d->sql, $detector->getDetections()));
    }

    public function testResetOpensANewScope(): void
    {
        $detector = new N1Detector($this->events, threshold: 1);
        $this->execute($detector, 'SELECT 1', 2);

        $detector->reset();
        $this->execute($detector, 'SELECT 1', 1);

        self::assertSame([], $detector->getDetections());
        $this->execute($detector, 'SELECT 1', 1);
        self::assertCount(2, $this->events->dispatched, 'detected again in the new scope');
    }

    public function testWorksWithoutDispatcher(): void
    {
        $detector = new N1Detector(threshold: 1);
        $this->execute($detector, 'SELECT 1', 2);

        self::assertCount(1, $detector->getDetections());
    }

    public function testIgnoredPathsDecideTheOrigin(): void
    {
        // le code de Prism et ce dossier de test ignorés : il ne reste que vendor/, toujours ignoré
        $prism = \dirname((string) new \ReflectionClass(N1Detector::class)->getFileName(), 2) . '/';
        $detector = new N1Detector($this->events, threshold: 1, ignoredPaths: [$prism, __DIR__ . '/']);

        $this->execute($detector, 'SELECT 1', 2);

        $event = $this->events->dispatched[0];
        self::assertInstanceOf(NPlusOneDetected::class, $event);
        self::assertSame('unknown', $event->origin);
    }

    public function testOnlyReadsAreCounted(): void
    {
        $detector = new N1Detector($this->events, threshold: 1);

        $this->execute($detector, 'INSERT INTO seller (name) VALUES (:prism_1)', 3);
        $this->execute($detector, 'UPDATE seller SET name = :prism_1 WHERE id = :prism_2', 3);
        $this->execute($detector, 'DELETE FROM seller WHERE id = :prism_1', 3);
        $this->execute($detector, 'INSERT INTO archive SELECT * FROM seller', 3);
        $this->execute($detector, ' with t as (select 1) select * from t', 2);

        self::assertSame(['with t as (select ?) select * from t'], array_map(static fn (NPlusOneDetected $d): string => $d->sql, $detector->getDetections()));
    }

    public function testThresholdMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The N+1 threshold must be at least 1, got 0.');

        new N1Detector(threshold: 0);
    }
}
