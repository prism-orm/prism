<?php

declare(strict_types=1);

namespace Prism\Orm\Vector;

/**
 * Lignes dont un vecteur #[EmbeddedVector] est devenu périmé à un flush (RG-09.7), remises à
 * l'EmbeddingScheduler après le commit. Les identifiants et le tenant sont des valeurs de la
 * base : l'objet se sérialise tel quel dans un message.
 *
 * @api
 */
final readonly class StaleEmbeddings
{
    /**
     * @param class-string               $className
     * @param non-empty-list<int|string> $ids
     * @param int|string|null            $tenant    tenant des lignes (#[Tenanted]), sinon null
     */
    public function __construct(
        public string $className,
        public string $field,
        public array $ids,
        public int|string|null $tenant = null,
    ) {
    }
}
