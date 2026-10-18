<?php

declare(strict_types=1);

namespace Prism\Dbal\Exception;

/**
 * URL de connexion illisible ou incomplète. Le mot de passe n'apparaît jamais dans le message.
 *
 * @api
 */
final class InvalidUrlException extends \InvalidArgumentException implements DbalException
{
    public static function because(#[\SensitiveParameter] string $url, string $reason): self
    {
        return new self(\sprintf('Invalid database URL "%s": %s', self::mask($url), $reason));
    }

    public static function mask(#[\SensitiveParameter] string $url): string
    {
        return (string) preg_replace('#^([a-z0-9+.-]+://[^:/@]*):[^@]*@#i', '$1:***@', $url);
    }
}
