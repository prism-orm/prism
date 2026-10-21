<?php

declare(strict_types=1);

namespace Prism\Migrations\Exception;

use Prism\Migrations\Direction;

/**
 * Migration introuvable, invalide, modifiée après exécution, ou échouée.
 *
 * @api
 */
final class MigrationException extends \RuntimeException implements MigrationsException
{
    public static function invalidFileName(string $file): self
    {
        return new self(\sprintf('The migration file %s is not named VersionYYYYMMDDHHMMSS.php (RG-06.1).', $file));
    }

    public static function classNotFound(string $file, string $class): self
    {
        return new self(\sprintf('The migration file %s does not declare the class %s: check its namespace.', $file, $class));
    }

    public static function notAMigration(string $class): self
    {
        return new self(\sprintf('%s does not extend Prism\Migrations\AbstractMigration.', $class));
    }

    public static function unknownVersion(string $version): self
    {
        return new self(\sprintf('Unknown migration version "%s": no such migration file.', $version));
    }

    public static function alreadyExecuted(string $version): self
    {
        return new self(\sprintf('The migration %s is already executed: run it --down first.', $version));
    }

    public static function notExecuted(string $version): self
    {
        return new self(\sprintf('The migration %s is not executed: there is nothing to revert.', $version));
    }

    // RG-06.4
    public static function modified(string $version): self
    {
        return new self(\sprintf('The migration %s was modified after it was executed: its operations no longer match the recorded checksum. Restore it, or pass --allow-modified if the change is deliberate.', $version));
    }

    public static function invalidOperation(string $version, \Throwable $previous): self
    {
        return new self(\sprintf('The migration %s cannot apply to the current schema: %s', $version, $previous->getMessage()), previous: $previous);
    }

    // RG-06.6 : sans transaction, ce qui a précédé l'échec reste en base
    public static function failed(string $version, Direction $direction, bool $partial, \Throwable $previous): self
    {
        return new self(\sprintf(
            'The migration %s failed (%s): %s %s',
            $version,
            $direction->value,
            $previous->getMessage(),
            $partial ? 'It ran without a transaction: the statements before the failure stay applied, check the schema before running it again.' : 'Its transaction was rolled back: nothing was applied.',
        ), previous: $previous);
    }
}
