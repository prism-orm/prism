<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Logger PSR-3 en mémoire pour vérifier ce que journalise Connection.
 *
 * @internal
 */
final class TestLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => \is_string($level) ? $level : get_debug_type($level), 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    public function withSql(string $sql): array
    {
        return array_values(array_filter($this->records, static fn (array $r): bool => ($r['context']['sql'] ?? null) === $sql));
    }

    /**
     * @return list<string>
     */
    public function sqlStatements(): array
    {
        $sql = [];
        foreach ($this->records as $record) {
            if (\is_string($record['context']['sql'] ?? null)) {
                $sql[] = $record['context']['sql'];
            }
        }

        return $sql;
    }
}
