<?php

declare(strict_types=1);

namespace Prism\Orm\Id;

use Prism\Dbal\Connection;

/**
 * UUID version 7 (RFC 9562) sans dépendance : 48 bits d'horodatage en millisecondes, version,
 * variante, 74 bits aléatoires.
 *
 * Monotone dans le processus : dans une même milliseconde (ou si l'horloge recule), la partie
 * aléatoire est incrémentée au lieu d'être tirée à nouveau. Les identifiants restent triés, ce
 * qui garde les index B-tree compacts.
 *
 * @internal
 */
final class UuidV7Generator implements IdGenerator
{
    private const int RAND_B_MAX = (1 << 62) - 1;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    private int $lastMs = -1;

    private int $randA = 0;

    private int $randB = 0;

    /**
     * @param (\Closure(): int)|null $clock millisecondes depuis l'epoch Unix (injectable pour les tests)
     */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => (int) floor(microtime(true) * 1000);
    }

    public function isPostInsert(): bool
    {
        return false;
    }

    public function generate(Connection $connection): string
    {
        return $this->next();
    }

    public function next(): string
    {
        $ms = ($this->clock)();

        if ($ms > $this->lastMs) {
            $this->lastMs = $ms;
            $this->randA = random_int(0, 0xFFF);
            // le bit de poids fort est laissé à 0 : de la marge pour les incréments
            $this->randB = random_int(0, self::RAND_B_MAX >> 1);
        } elseif (++$this->randB > self::RAND_B_MAX) {
            $this->randB = 0;
            if (++$this->randA > 0xFFF) {
                // 2^74 identifiants dans la même milliseconde : on emprunte la suivante
                $this->randA = 0;
                ++$this->lastMs;
            }
        }

        return self::format($this->lastMs, $this->randA, $this->randB);
    }

    /**
     * Horodatage (ms) contenu dans un UUID v7.
     */
    public static function timestampOf(string $uuid): int
    {
        return (int) hexdec(substr(str_replace('-', '', $uuid), 0, 12));
    }

    private static function format(int $ms, int $randA, int $randB): string
    {
        $bytes = pack('J', $ms << 16)                              // 48 bits d'horodatage + 16 bits ignorés
            . \chr(0x70 | ($randA >> 8)) . \chr($randA & 0xFF)     // version 7 + rand_a (12 bits)
            . \chr(0x80 | (($randB >> 56) & 0x3F))                 // variante 10 + rand_b (6 bits)
            . substr(pack('J', $randB), 1);                        // rand_b (56 bits restants)
        $hex = bin2hex(substr($bytes, 0, 6) . substr($bytes, 8));

        return \sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
