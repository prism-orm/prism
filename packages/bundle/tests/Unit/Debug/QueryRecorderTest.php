<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Prism\Bundle\Debug\QueryRecorder;

/**
 * RG-07.4 : l'enregistrement s'arrête au plafond, le compte et la durée continuent.
 */
final class QueryRecorderTest extends TestCase
{
    public function testRecordingStopsAtTheLimitButCountingGoesOn(): void
    {
        $recorder = new QueryRecorder();
        for ($i = 1; $i <= 1432; ++$i) {
            $recorder->queryExecuted('SELECT ' . $i, ['p' => $i], 0.5);
        }

        self::assertCount(1000, $recorder->getQueries(), '1 000 per request by default');
        self::assertSame('SELECT 1000', $recorder->getQueries()[999]['sql']);
        self::assertSame(1432, $recorder->getCount());
        self::assertSame(716.0, $recorder->getDurationMs());
    }

    public function testResetStartsAnEmptyRecording(): void
    {
        $recorder = new QueryRecorder(limit: 2);
        $recorder->queryExecuted('SELECT 1', [], 1.0);
        $recorder->queryExecuted('SELECT 2', [], 1.0);
        $recorder->queryExecuted('SELECT 3', [], 1.0);

        $recorder->reset();
        $recorder->queryExecuted('SELECT 4', [], 2.0);

        self::assertSame([['sql' => 'SELECT 4', 'params' => [], 'durationMs' => 2.0]], $recorder->getQueries());
        self::assertSame([1, 2.0], [$recorder->getCount(), $recorder->getDurationMs()]);
    }
}
