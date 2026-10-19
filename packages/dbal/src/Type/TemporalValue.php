<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

use Prism\Dbal\Exception\ConversionException;

/**
 * Conversions communes aux types de date et d'heure.
 *
 * @internal
 */
final class TemporalValue
{
    /**
     * Les microsecondes sont tronquées par le format : jamais laissées à l'arrondi du serveur.
     */
    public static function toDatabase(string $type, mixed $value, string $format, ?\DateTimeZone $timezone): ?string
    {
        if (null === $value) {
            return null;
        }
        if ($value instanceof \DateTime) {
            throw ConversionException::mutableDateTime($type);
        }
        if (!$value instanceof \DateTimeImmutable) {
            throw ConversionException::invalidPhpValue($type, $value, 'a \DateTimeImmutable');
        }

        return (null === $timezone ? $value : $value->setTimezone($timezone))->format($format);
    }

    public static function toPhp(string $type, mixed $value, string $format, ?\DateTimeZone $timezone): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }
        if (!\is_string($value)) {
            throw ConversionException::invalidDatabaseValue($type, $value, \sprintf('a string in the "%s" format', $format));
        }

        // une colonne plus précise que la seconde renvoie des microsecondes : on les ignore
        $value = (string) preg_replace('/\.\d+$/', '', $value);
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();

        if (false === $date || (false !== $errors && $errors['warning_count'] > 0)) {
            throw ConversionException::invalidDatabaseValue($type, $value, \sprintf('a valid date in the "%s" format', $format));
        }

        return $date;
    }
}
