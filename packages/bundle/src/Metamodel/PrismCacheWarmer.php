<?php

declare(strict_types=1);

namespace Prism\Bundle\Metamodel;

use Prism\Orm\Metadata\MetadataFactory;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * cache:warmup (ADR 0013, RG-05.1) : les metadata validées et mises en cache, le metamodel
 * généré. Un mapping invalide fait échouer le warmup en prod, plutôt que la première requête ;
 * en dev, il est laissé à db:schema:validate.
 *
 * @internal
 */
final class PrismCacheWarmer implements CacheWarmerInterface
{
    /**
     * @param list<MetadataFactory> $metadataFactories
     */
    public function __construct(
        private readonly array $metadataFactories,
        private readonly MetamodelRefresher $metamodel,
        private readonly bool $debug,
    ) {
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if ($this->debug) {
            $this->metamodel->refreshIfStale();

            return [];
        }
        foreach ($this->metadataFactories as $metadata) {
            if ($metadata->hasCache()) {
                $metadata->warmUp();
            }
        }
        $this->metamodel->generate();

        return [];
    }
}
