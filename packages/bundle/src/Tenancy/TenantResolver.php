<?php

declare(strict_types=1);

namespace Prism\Bundle\Tenancy;

use Prism\Orm\Tenancy\StackTenantContext;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Le tenant de la requête principale, sur kernel.request (RG-08.5, ADR 0014) : depuis un en-tête
 * ou un sous-domaine, avant le firewall ; depuis l'utilisateur connecté, après lui. Sans valeur,
 * aucun tenant n'est posé : l'accès aux entités #[Tenanted] échoue (RG-08.4).
 *
 * L'en-tête vient du client : il ne convient que derrière une passerelle qui le pose elle-même.
 *
 * @internal
 */
final class TenantResolver
{
    public const string HEADER = 'header';

    public const string SUBDOMAIN = 'subdomain';

    public const string USER = 'user';

    private ?string $identifier = null;

    /**
     * @param string $source nom de l'en-tête, ou domaine sous lequel lire le sous-domaine
     * @param string $type   type de l'identifiant du tenant (integer, bigint, uuid)
     */
    public function __construct(
        private readonly StackTenantContext $context,
        private readonly string $strategy,
        private readonly string $source,
        private readonly string $type,
        private readonly ?TenantLoader $loader = null,
        private readonly ?TokenStorageInterface $tokens = null,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (self::USER === $this->strategy) {
            $user = $this->tokens?->getToken()?->getUser();
            $tenantId = $user instanceof TenantAwareUserInterface ? $user->getTenantId() : null;
            $this->identifier = null === $tenantId ? null : (string) $tenantId;
            $this->context->setTenantId($tenantId);

            return;
        }
        $identifier = self::HEADER === $this->strategy ? $request->headers->get($this->source) : self::subdomain($request->getHost(), $this->source);
        $this->identifier = null === $identifier || '' === $identifier ? null : $identifier;
        $this->context->setTenantId(null === $this->identifier ? null : $this->tenantId($this->identifier));
    }

    public function getStrategy(): string
    {
        return $this->strategy;
    }

    /**
     * L'identifiant lu dans la dernière requête, avant traduction.
     */
    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function reset(): void
    {
        $this->identifier = null;
    }

    // acme.example.com sous example.com : acme ; www.acme.example.com : acme
    private static function subdomain(string $host, string $domain): ?string
    {
        $suffix = '.' . strtolower($domain);
        $host = strtolower($host);
        if (!str_ends_with($host, $suffix) || $host === $suffix) {
            return null;
        }
        $labels = explode('.', substr($host, 0, -\strlen($suffix)));

        return $labels[\count($labels) - 1];
    }

    private function tenantId(string $identifier): int|string|null
    {
        if (null !== $this->loader) {
            return $this->loader->load($identifier);
        }
        if ('uuid' === $this->type) {
            return $identifier;
        }

        // un identifiant entier : tout autre texte ne désigne aucun tenant
        return ctype_digit($identifier) ? (int) $identifier : null;
    }
}
