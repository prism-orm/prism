<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Id;

use PHPUnit\Framework\TestCase;
use Prism\Orm\Id\UuidV7Generator;

// RFC 9562 : version 7, variante 10, horodatage en millisecondes
final class UuidV7GeneratorTest extends TestCase
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testFormatVersionAndVariant(): void
    {
        $uuid = new UuidV7Generator()->next();

        self::assertMatchesRegularExpression(self::PATTERN, $uuid);
        self::assertTrue(self::isRfcUuidV7($uuid));
    }

    public function testTimestampIsEncoded(): void
    {
        $ms = 1_793_000_000_123;
        $uuid = new UuidV7Generator(static fn (): int => $ms)->next();

        self::assertSame($ms, UuidV7Generator::timestampOf($uuid));
        self::assertStringStartsWith(substr(str_pad(dechex($ms), 12, '0', \STR_PAD_LEFT), 0, 8), $uuid);
    }

    // monotone dans une même milliseconde : triés et tous différents
    public function testSameMillisecondIsStrictlyIncreasing(): void
    {
        $generator = new UuidV7Generator(static fn (): int => 1_793_000_000_000);

        $previous = '';
        $seen = [];
        for ($i = 0; $i < 10_000; ++$i) {
            $uuid = $generator->next();
            self::assertGreaterThan($previous, $uuid);
            self::assertMatchesRegularExpression(self::PATTERN, $uuid);
            $seen[$uuid] = true;
            $previous = $uuid;
        }

        self::assertCount(10_000, $seen);
    }

    public function testClockGoingBackwardsKeepsTheOrder(): void
    {
        $times = [2_000, 2_000, 1_000, 1_500, 3_000];
        $generator = new UuidV7Generator(static function () use (&$times): int {
            return (int) array_shift($times);
        });

        $uuids = array_map(static fn (): string => $generator->next(), range(1, 5));

        $sorted = $uuids;
        sort($sorted);
        self::assertSame($sorted, $uuids);
        self::assertSame([2_000, 2_000, 2_000, 2_000, 3_000], array_map(UuidV7Generator::timestampOf(...), $uuids), 'a past time reuses the last millisecond');
    }

    public function testNewMillisecondDrawsNewRandomBits(): void
    {
        $times = [1_000, 1_001];
        $generator = new UuidV7Generator(static function () use (&$times): int {
            return (int) array_shift($times);
        });

        self::assertNotSame(substr($generator->next(), 15), substr($generator->next(), 15));
    }

    private static function isRfcUuidV7(string $uuid): bool
    {
        $bytes = (string) hex2bin(str_replace('-', '', $uuid));

        return 7 === (\ord($bytes[6]) >> 4) && 0b10 === (\ord($bytes[8]) >> 6);
    }
}
