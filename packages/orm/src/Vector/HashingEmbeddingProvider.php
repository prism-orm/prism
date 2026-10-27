<?php

declare(strict_types=1);

namespace Prism\Orm\Vector;

use Prism\Orm\Exception\InvalidArgumentException;

/**
 * Fournisseur déterministe et sans réseau (ADR 0018) : chaque mot (minuscules, lettres et
 * chiffres Unicode) ajoute ±1 à une dimension choisie par hachage, puis le vecteur est normalisé.
 *
 * Il ne comprend pas le sens : deux synonymes sont éloignés, deux textes qui partagent des mots
 * sont proches. Il sert aux tests, à la CI, au développement hors ligne, et d'exemple
 * d'implémentation ; jamais à une recherche sémantique en production.
 *
 * @api
 */
final readonly class HashingEmbeddingProvider implements EmbeddingProvider
{
    /**
     * @throws InvalidArgumentException dimension inférieure à 1
     */
    public function __construct(private int $dimensions = 256)
    {
        if ($dimensions < 1) {
            throw InvalidArgumentException::invalidVector(\sprintf('the dimension must be at least 1, got %d', $dimensions));
        }
    }

    public function model(): string
    {
        return 'prism/hashing-' . $this->dimensions;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function embed(array $texts): array
    {
        return array_map($this->vector(...), $texts);
    }

    /**
     * @return list<float>
     */
    private function vector(string $text): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, \PREG_SPLIT_NO_EMPTY);
        foreach (false === $words ? [] : $words as $word) {
            $hash = crc32($word);
            $vector[$hash % $this->dimensions] += 0 === ($hash >> 31 & 1) ? 1.0 : -1.0;
        }
        $norm = sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $vector)));

        // un texte sans mot : le vecteur nul, que le cosinus ne rapproche de rien
        return array_values(0.0 === $norm ? $vector : array_map(static fn (float $x): float => $x / $norm, $vector));
    }
}
