<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * Le metamodel ne peut pas être écrit dans son dossier de génération.
 *
 * @api
 */
final class MetamodelException extends \RuntimeException implements OrmException
{
    public static function cannotWrite(string $path): self
    {
        return new self(\sprintf('Cannot write the metamodel file "%s": check that the directory is writable.', $path));
    }
}
