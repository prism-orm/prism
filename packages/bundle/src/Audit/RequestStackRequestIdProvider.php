<?php

declare(strict_types=1);

namespace Prism\Bundle\Audit;

use Prism\Orm\Audit\RequestIdProvider;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * L'identifiant de la requête principale, pour relier ses lignes d'audit (RG-08.10) : l'en-tête
 * X-Request-Id posé par le proxy, sinon un identifiant tiré une fois par requête.
 *
 * @internal
 */
final class RequestStackRequestIdProvider implements RequestIdProvider
{
    private const string ATTRIBUTE = '_prism_request_id';

    public function __construct(
        private readonly RequestStack $requests,
    ) {
    }

    public function getRequestId(): ?string
    {
        $request = $this->requests->getMainRequest();
        if (null === $request) {
            return null;
        }
        $id = $request->attributes->get(self::ATTRIBUTE);
        if (!\is_string($id)) {
            $header = $request->headers->get('X-Request-Id');
            $id = null !== $header && '' !== $header ? substr($header, 0, 64) : bin2hex(random_bytes(16));
            $request->attributes->set(self::ATTRIBUTE, $id);
        }

        return $id;
    }
}
