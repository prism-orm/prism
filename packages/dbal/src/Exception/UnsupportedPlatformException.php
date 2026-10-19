<?php

declare(strict_types=1);

namespace Prism\Dbal\Exception;

/**
 * Version de serveur illisible ou inférieure au minimum supporté par Prism 1.0.
 *
 * @api
 */
final class UnsupportedPlatformException extends \RuntimeException implements DbalException
{
    public static function versionTooOld(string $platform, string $version, string $minimum): self
    {
        return new self(\sprintf('%s %s is not supported: Prism requires %s %s or later.', $platform, $version, $platform, $minimum));
    }

    public static function unparsableVersion(string $platform, string $version): self
    {
        return new self(\sprintf('Cannot read the %s server version from "%s"; set "serverVersion" in the database URL.', $platform, $version));
    }
}
