<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

use Prism\Dbal\Exception\ConversionException;
use Prism\Dbal\Platform\AbstractPlatform;

/**
 * Vecteur d'embedding (pilier 4, ADR 0017) : list<float> côté PHP, dimension portée par la
 * longueur de la colonne.
 *
 * - Écriture : texte « [0.5,-1.25,3] » sur toutes les plateformes ; MariaDB ne lit un vecteur
 *   lié qu'à travers VEC_FromText(), PostgreSQL à travers un CAST (getParameterSql()).
 * - Lecture : binaire float32 little-endian (MariaDB) ou texte (PostgreSQL, SQLite).
 *
 * La dimension n'est pas connue ici : l'ORM la vérifie avec la classe et le champ (RG-09.1).
 *
 * @api
 */
final class VectorType extends Type
{
    public function getName(): string
    {
        return 'vector';
    }

    public function toDatabase(mixed $value, ConversionContext $context): ?string
    {
        if (null === $value) {
            return null;
        }

        // la plus courte écriture qui relit le même flottant (serialize_precision = -1)
        return json_encode($this->components($value), \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @return list<float>|null
     */
    public function toPhp(mixed $value, ConversionContext $context): ?array
    {
        if (\is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if (null === $value) {
            return null;
        }
        if (!\is_string($value) || '' === $value) {
            throw ConversionException::invalidDatabaseValue($this->getName(), $value, 'a vector');
        }

        if ('[' === $value[0]) {
            try {
                $decoded = json_decode($value, true, 2, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw ConversionException::invalidDatabaseValue($this->getName(), $value, 'a vector', $e);
            }

            return $this->components($decoded, database: true);
        }

        // MariaDB : float32 little-endian, 4 octets par composante
        if (0 !== \strlen($value) % 4) {
            throw ConversionException::invalidDatabaseValue($this->getName(), $value, 'a vector');
        }
        /** @var array<int, float> $floats */
        $floats = (array) unpack('g*', $value);

        return array_values($floats);
    }

    public function getParameterSql(string $placeholder, AbstractPlatform $platform): string
    {
        return $platform->getVectorParameterSql($placeholder);
    }

    /**
     * @return non-empty-list<float>
     *
     * @throws ConversionException
     */
    private function components(mixed $value, bool $database = false): array
    {
        if (!\is_array($value) || [] === $value || !array_is_list($value)) {
            throw $this->invalid($value, $database);
        }
        $components = [];
        foreach ($value as $component) {
            if ((!\is_int($component) && !\is_float($component)) || !is_finite((float) $component)) {
                throw $this->invalid($value, $database);
            }
            $components[] = (float) $component;
        }

        return $components;
    }

    private function invalid(mixed $value, bool $database): ConversionException
    {
        return $database
            ? ConversionException::invalidDatabaseValue($this->getName(), $value, 'a vector')
            : ConversionException::invalidPhpValue($this->getName(), $value, 'a non-empty list of finite floats');
    }
}
