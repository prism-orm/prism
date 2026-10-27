<?php

declare(strict_types=1);

namespace Prism\Bundle\Messenger;

/**
 * Recalcule des vecteurs #[EmbeddedVector] périmés (RG-09.7, ADR 0018), publié après le commit
 * du flush qui les a rendus périmés. Les identifiants et le tenant sont des valeurs de la base ;
 * le handler travaille dans le tenant des lignes.
 *
 * @api
 */
final readonly class ComputeEmbedding
{
    /**
     * @param class-string               $className
     * @param non-empty-list<int|string> $ids
     */
    public function __construct(
        public string $entityManager,
        public string $className,
        public string $field,
        public array $ids,
        public int|string|null $tenant = null,
    ) {
    }
}
