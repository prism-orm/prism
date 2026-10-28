<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

use Prism\Dbal\Exception\ConversionException;

/**
 * Les distances vectorielles et le score plein texte de SQLite, calculés en PHP (ADR 0017) :
 * SQLite n'a ni type ni index vectoriel, il sert aux tests et au développement. Enregistrées
 * par la Connection sur chaque connexion SQLite ; NULL si un vecteur est NULL ou nul (cosinus
 * indéfini).
 *
 * @internal
 */
final class SqliteVectorFunctions
{
    /**
     * @return array<string, \Closure(mixed, mixed): ?float> nom SQL → fonction à 2 arguments
     */
    public static function all(): array
    {
        return [
            'prism_vec_cosine' => self::cosine(...),
            'prism_vec_euclidean' => self::euclidean(...),
            'prism_vec_inner' => self::inner(...),
            'prism_fts_score' => self::fullTextScore(...),
        ];
    }

    /**
     * Score par termes, comme un OU pondéré : pour chaque mot distinct cherché, tf / (tf + 1,2),
     * tf étant son nombre d'occurrences dans le document (saturation à la BM25, sans longueur).
     * Mots en minuscules, lettres et chiffres Unicode ; 0 sans mot commun.
     */
    public static function fullTextScore(mixed $document, mixed $query): float
    {
        if (!\is_string($document) || !\is_string($query)) {
            return 0.0;
        }
        $counts = array_count_values(self::words($document));
        $score = 0.0;
        foreach (array_unique(self::words($query)) as $word) {
            $tf = $counts[$word] ?? 0;
            $score += $tf / ($tf + 1.2);
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, \PREG_SPLIT_NO_EMPTY);

        return false === $words ? [] : $words;
    }

    public static function cosine(mixed $a, mixed $b): ?float
    {
        $pair = self::pair($a, $b);
        if (null === $pair) {
            return null;
        }
        [$dot, $normA, $normB] = [0.0, 0.0, 0.0];
        foreach ($pair[0] as $i => $x) {
            $y = $pair[1][$i];
            $dot += $x * $y;
            $normA += $x * $x;
            $normB += $y * $y;
        }

        return 0.0 === $normA || 0.0 === $normB ? null : 1.0 - $dot / (sqrt($normA) * sqrt($normB));
    }

    public static function euclidean(mixed $a, mixed $b): ?float
    {
        $pair = self::pair($a, $b);
        if (null === $pair) {
            return null;
        }
        $sum = 0.0;
        foreach ($pair[0] as $i => $x) {
            $sum += ($x - $pair[1][$i]) ** 2;
        }

        return sqrt($sum);
    }

    // l'opposé du produit scalaire : une distance, comme pgvector
    public static function inner(mixed $a, mixed $b): ?float
    {
        $pair = self::pair($a, $b);
        if (null === $pair) {
            return null;
        }
        $dot = 0.0;
        foreach ($pair[0] as $i => $x) {
            $dot += $x * $pair[1][$i];
        }

        return -$dot;
    }

    /**
     * @return array{list<float>, list<float>}|null
     *
     * @throws ConversionException texte invalide ou dimensions différentes
     */
    private static function pair(mixed $a, mixed $b): ?array
    {
        if (null === $a || null === $b) {
            return null;
        }
        $a = self::vector($a);
        $b = self::vector($b);
        if (\count($a) !== \count($b)) {
            throw ConversionException::invalidDatabaseValue('vector', \sprintf('%d and %d dimensions', \count($a), \count($b)), 'two vectors of the same dimension');
        }

        return [$a, $b];
    }

    /**
     * @return list<float>
     */
    private static function vector(mixed $text): array
    {
        $decoded = \is_string($text) ? json_decode($text, true) : null;
        if (!\is_array($decoded) || !array_is_list($decoded)) {
            throw ConversionException::invalidDatabaseValue('vector', $text, 'a vector');
        }

        return array_map(static fn (mixed $x): float => \is_int($x) || \is_float($x) ? (float) $x : throw ConversionException::invalidDatabaseValue('vector', $text, 'a vector'), $decoded);
    }
}
