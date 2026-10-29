<?php

declare(strict_types=1);

namespace App;

/**
 * Montants en centimes entiers : un decimal arrive en chaîne (RG-01.5) et n'est jamais converti
 * en flottant.
 */
final class Money
{
    public static function toCents(string $amount): int
    {
        if (1 !== preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $m)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an amount with at most 2 decimals.', $amount));
        }
        $cents = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return '-' === $m[1] ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        return \sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
    }
}
