<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Embedding;

use Prism\Bundle\Attribute\AsEmbeddingProvider;
use Prism\Orm\Vector\EmbeddingProvider;
use Prism\Orm\Vector\HashingEmbeddingProvider;

// un fournisseur de l'application, enregistré par son attribut
#[AsEmbeddingProvider('keywords')]
final readonly class KeywordProvider implements EmbeddingProvider
{
    public function model(): string
    {
        return 'test/keywords';
    }

    public function dimensions(): int
    {
        return 8;
    }

    public function embed(array $texts): array
    {
        return new HashingEmbeddingProvider(8)->embed($texts);
    }
}
