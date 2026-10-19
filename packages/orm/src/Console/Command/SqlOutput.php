<?php

declare(strict_types=1);

namespace Prism\Orm\Console\Command;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
final class SqlOutput
{
    /**
     * @param list<string> $sql
     */
    public static function write(SymfonyStyle $io, array $sql): void
    {
        foreach ($sql as $statement) {
            $io->writeln($statement . ';');
        }
    }
}
