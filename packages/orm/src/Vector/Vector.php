<?php

declare(strict_types=1);

namespace Prism\Orm\Vector;

use Prism\Orm\Exception\InvalidArgumentException;

/**
 * Vecteur d'embedding immuable (RG-09.2). Un champ typé Vector est paresseux par défaut
 * (RG-09.6) : l'entité reçoit un ghost, chargé au premier accès avec ceux de son lot.
 *
 *     $doc->embedding = new Vector($provider->embed([$doc->content])[0]);
 *     $similarity = $a->cosine($b);
 *
 * @api
 */
final readonly class Vector implements \Countable
{
    /** @var non-empty-list<float> */
    public array $values;

    /**
     * @param list<float|int> $values
     *
     * @throws InvalidArgumentException liste vide, ou composante non finie
     */
    public function __construct(array $values)
    {
        $this->values = self::checked($values);
    }

    /**
     * @return non-empty-list<float>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function dimensions(): int
    {
        return \count($this->values);
    }

    public function count(): int
    {
        return \count($this->values);
    }

    /**
     * Le même vecteur, de norme 1 : le produit scalaire de deux vecteurs normés est leur cosinus.
     *
     * @throws InvalidArgumentException vecteur nul
     */
    public function normalize(): self
    {
        $norm = $this->norm();
        if (0.0 === $norm) {
            throw InvalidArgumentException::invalidVector('a zero vector cannot be normalized');
        }

        return new self(array_map(static fn (float $x): float => $x / $norm, $this->values));
    }

    /**
     * Similarité cosinus, dans [-1, 1].
     *
     * @throws InvalidArgumentException dimensions différentes, ou vecteur nul
     */
    public function cosine(self $other): float
    {
        $norms = $this->norm() * $other->norm();
        if (0.0 === $norms) {
            throw InvalidArgumentException::invalidVector('the cosine of a zero vector is undefined');
        }

        return $this->dot($other) / $norms;
    }

    /**
     * Distance, plus petite = plus proche, comme la calcule la base : 1 − cosinus, euclidienne,
     * ou l'opposé du produit scalaire.
     *
     * @throws InvalidArgumentException dimensions différentes, ou vecteur nul (cosinus)
     */
    public function distance(self $other, Distance $distance): float
    {
        return match ($distance) {
            Distance::Cosine => 1.0 - $this->cosine($other),
            Distance::Euclidean => sqrt(array_sum(array_map(static fn (float $a, float $b): float => ($a - $b) ** 2, $this->values, $this->sameDimensions($other)->values))),
            Distance::InnerProduct => -$this->dot($other),
        };
    }

    /**
     * @param list<float|int> $values
     *
     * @return non-empty-list<float>
     */
    private static function checked(array $values): array
    {
        if ([] === $values) {
            throw InvalidArgumentException::invalidVector('a vector needs a non-empty list of floats');
        }
        $floats = [];
        foreach ($values as $value) {
            if (!is_finite((float) $value)) {
                throw InvalidArgumentException::invalidVector('every component must be finite, NAN and INF are refused');
            }
            $floats[] = (float) $value;
        }

        return $floats;
    }

    private function dot(self $other): float
    {
        return array_sum(array_map(static fn (float $a, float $b): float => $a * $b, $this->values, $this->sameDimensions($other)->values));
    }

    private function norm(): float
    {
        return sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $this->values)));
    }

    private function sameDimensions(self $other): self
    {
        if (\count($other->values) !== \count($this->values)) {
            throw InvalidArgumentException::invalidVector(\sprintf('the vectors have %d and %d dimensions', \count($this->values), \count($other->values)));
        }

        return $other;
    }
}
