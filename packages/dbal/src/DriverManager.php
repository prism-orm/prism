<?php

declare(strict_types=1);

namespace Prism\Dbal;

use Prism\Dbal\Exception\InvalidUrlException;
use Prism\Dbal\Exception\UnsupportedPlatformException;
use Prism\Dbal\Platform\PlatformResolver;

/**
 * Point d'entrée : crée une Connection depuis une URL au format Symfony (RG-00.2).
 *
 * Avec « serverVersion », la plateforme est choisie sans se connecter ; sinon la version est
 * lue sur le serveur à la première requête.
 *
 * @api
 */
final class DriverManager
{
    private function __construct()
    {
    }

    /**
     * @throws InvalidUrlException          URL illisible, incomplète ou option inconnue
     * @throws UnsupportedPlatformException « serverVersion » sous le minimum supporté
     */
    public static function fromUrl(#[\SensitiveParameter] string $url, Configuration $configuration = new Configuration()): Connection
    {
        $parameters = ConnectionParameters::fromUrl($url);
        $platform = null === $parameters->serverVersion ? null : PlatformResolver::resolve($parameters, $parameters->serverVersion);

        return new Connection($parameters, $configuration, $platform);
    }
}
