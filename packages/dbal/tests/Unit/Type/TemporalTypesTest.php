<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Type;

use Prism\Dbal\Exception\ConversionException;
use Prism\Dbal\Type\DateImmutableType;
use Prism\Dbal\Type\DateTimeImmutableType;
use Prism\Dbal\Type\TimeImmutableType;

final class TemporalTypesTest extends TypeTestCase
{
    // RG-01.6 : sans fuseau configuré, aucune conversion
    public function testDateTimeWithoutTimezoneIsNotConverted(): void
    {
        $type = new DateTimeImmutableType();
        $paris = new \DateTimeImmutable('2026-10-18 14:30:15', new \DateTimeZone('Europe/Paris'));

        self::assertSame('2026-10-18 14:30:15', $type->toDatabase($paris, self::context()));

        $read = $type->toPhp('2026-10-18 14:30:15', self::context());
        self::assertInstanceOf(\DateTimeImmutable::class, $read);
        self::assertSame('2026-10-18 14:30:15', $read->format('Y-m-d H:i:s'));
        self::assertSame(date_default_timezone_get(), $read->getTimezone()->getName());
    }

    // RG-01.6 : avec un fuseau de stockage UTC, écriture en UTC et relecture en UTC (décision C)
    public function testDateTimeIsStoredAndReadInTheStorageTimezone(): void
    {
        $type = new DateTimeImmutableType();
        $paris = new \DateTimeImmutable('2026-10-18 14:30:15', new \DateTimeZone('Europe/Paris'));

        self::assertSame('2026-10-18 12:30:15', $type->toDatabase($paris, self::context('UTC')));

        $read = $type->toPhp('2026-10-18 12:30:15', self::context('UTC'));
        self::assertInstanceOf(\DateTimeImmutable::class, $read);
        self::assertSame('UTC', $read->getTimezone()->getName());
        self::assertSame($paris->getTimestamp(), $read->getTimestamp());
    }

    // passage à l'heure d'hiver : 02:30 existe deux fois à Paris, UTC lève l'ambiguïté
    public function testDaylightSavingTransitionIsUnambiguousInUtc(): void
    {
        $type = new DateTimeImmutableType();
        $paris = new \DateTimeZone('Europe/Paris');
        $utc = new \DateTimeZone('UTC');
        // 25 octobre 2026 : 02:30 à Paris existe en heure d'été (00:30 UTC) puis en heure d'hiver (01:30 UTC)
        $summer = new \DateTimeImmutable('2026-10-25 00:30:00', $utc)->setTimezone($paris);
        $winter = new \DateTimeImmutable('2026-10-25 01:30:00', $utc)->setTimezone($paris);

        self::assertSame('02:30', $summer->format('H:i'));
        self::assertSame('02:30', $winter->format('H:i'));
        self::assertSame('2026-10-25 00:30:00', $type->toDatabase($summer, self::context('UTC')));
        self::assertSame('2026-10-25 01:30:00', $type->toDatabase($winter, self::context('UTC')));
        // sans fuseau de stockage, les deux instants deviennent la même valeur en base
        self::assertSame($type->toDatabase($summer, self::context()), $type->toDatabase($winter, self::context()));
    }

    // décision A : précision à la seconde, microsecondes tronquées côté PHP (jamais arrondies)
    public function testMicrosecondsAreTruncated(): void
    {
        $type = new DateTimeImmutableType();

        self::assertSame('2026-10-18 12:00:00', $type->toDatabase(new \DateTimeImmutable('2026-10-18 12:00:00.999999'), self::context()));

        $read = $type->toPhp('2026-10-18 12:00:00.654321', self::context());
        self::assertInstanceOf(\DateTimeImmutable::class, $read);
        self::assertSame('2026-10-18 12:00:00.000000', $read->format('Y-m-d H:i:s.u'));
    }

    public function testMutableDateTimeIsRefusedWithAHint(): void
    {
        foreach ([new DateTimeImmutableType(), new DateImmutableType(), new TimeImmutableType()] as $type) {
            try {
                $type->toDatabase(new \DateTime(), self::context());
                self::fail('Expected ConversionException.');
            } catch (ConversionException $e) {
                self::assertStringContainsString('use \DateTimeImmutable instead', $e->getMessage());
            }
        }
    }

    public function testInvalidDatabaseDatesAreRefused(): void
    {
        foreach (['2026-02-30 10:00:00', '18/10/2026', '', '2026-10-18'] as $value) {
            try {
                new DateTimeImmutableType()->toPhp($value, self::context());
                self::fail(\sprintf('Expected ConversionException for "%s".', $value));
            } catch (ConversionException $e) {
                self::assertStringContainsString('"Y-m-d H:i:s" format', $e->getMessage());
            }
        }
    }

    public function testDateIsNeverConverted(): void
    {
        $type = new DateImmutableType();
        $lateInParis = new \DateTimeImmutable('2026-10-18 23:30:00', new \DateTimeZone('Europe/Paris'));

        self::assertSame('2026-10-18', $type->toDatabase($lateInParis, self::context('UTC')));

        $read = $type->toPhp('2026-10-18', self::context('UTC'));
        self::assertInstanceOf(\DateTimeImmutable::class, $read);
        self::assertSame('2026-10-18 00:00:00', $read->format('Y-m-d H:i:s'));
    }

    public function testTime(): void
    {
        $type = new TimeImmutableType();

        self::assertSame('08:15:00', $type->toDatabase(new \DateTimeImmutable('2026-10-18 08:15:00.5'), self::context('UTC')));

        $read = $type->toPhp('08:15:00', self::context());
        self::assertInstanceOf(\DateTimeImmutable::class, $read);
        self::assertSame('1970-01-01 08:15:00', $read->format('Y-m-d H:i:s'));
    }

    public function testDateTimeImmutableFromTheDriverIsReturnedAsIs(): void
    {
        $value = new \DateTimeImmutable();

        self::assertSame($value, new DateTimeImmutableType()->toPhp($value, self::context()));
    }

    public function testNonDateValuesAreRefused(): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('expected a \DateTimeImmutable');

        new DateTimeImmutableType()->toDatabase('2026-10-18', self::context());
    }
}
