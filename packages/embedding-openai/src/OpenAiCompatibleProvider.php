<?php

declare(strict_types=1);

namespace Prism\Embedding\OpenAi;

use Prism\Orm\Exception\EmbeddingException;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Vector\EmbeddingProvider;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fournisseur d'embeddings pour l'API POST <baseUrl>/embeddings au format d'OpenAI (ADR 0018),
 * que parlent aussi Mistral, Ollama (/v1), vLLM, LocalAI et Text Embeddings Inference.
 *
 *     new OpenAiCompatibleProvider(HttpClient::create(), 'http://ollama:11434/v1', 'nomic-embed-text:v1.5', 768);
 *
 * Les textes partent par lots de $batchSize, une requête par lot. Le modèle est stocké par ligne
 * (RG-09.10) ; la dimension n'est pas envoyée, sauf $sendDimensions (modèles qui la réduisent,
 * comme text-embedding-3-*), mais elle est vérifiée sur chaque réponse. $prefix précède chaque
 * texte, pour les modèles qui distinguent documents et questions : « search_document: » et
 * « search_query: » pour nomic-embed-text, deux fournisseurs du même modèle.
 *
 * @api
 */
final readonly class OpenAiCompatibleProvider implements EmbeddingProvider
{
    /**
     * @throws InvalidArgumentException dimension ou taille de lot inférieure à 1
     */
    public function __construct(
        private HttpClientInterface $http,
        private string $baseUrl,
        private string $model,
        private int $dimensions,
        #[\SensitiveParameter]
        private ?string $apiKey = null,
        private int $batchSize = 64,
        private bool $sendDimensions = false,
        private string $prefix = '',
    ) {
        if ($dimensions < 1) {
            throw InvalidArgumentException::invalidVector(\sprintf('the dimension must be at least 1, got %d', $dimensions));
        }
        if ($batchSize < 1) {
            throw InvalidArgumentException::batchSize($batchSize);
        }
    }

    public function model(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function embed(array $texts): array
    {
        $vectors = [];
        foreach (array_chunk($texts, max(1, $this->batchSize)) as $batch) {
            array_push($vectors, ...$this->request($batch));
        }

        return $vectors;
    }

    /**
     * @param non-empty-list<string> $texts
     *
     * @return non-empty-list<list<float>>
     *
     * @throws EmbeddingException
     */
    private function request(array $texts): array
    {
        $body = ['model' => $this->model, 'input' => array_map(fn (string $text): string => $this->prefix . $text, $texts)];
        if ($this->sendDimensions) {
            $body['dimensions'] = $this->dimensions;
        }
        try {
            $response = $this->http->request('POST', rtrim($this->baseUrl, '/') . '/embeddings', array_filter([
                'json' => $body,
                'auth_bearer' => $this->apiKey,
            ], static fn (mixed $option): bool => null !== $option));
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw EmbeddingException::invalidResponse($this->model, \sprintf('HTTP %d, %s', $status, mb_substr(trim($response->getContent(false)), 0, 300)));
            }
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw EmbeddingException::failed($this->model, $e);
        }

        return $this->vectors($data, \count($texts));
    }

    /**
     * Les vecteurs de la réponse, dans l'ordre de leur « index ».
     *
     * @param array<mixed> $data
     *
     * @return non-empty-list<list<float>>
     *
     * @throws EmbeddingException
     */
    private function vectors(array $data, int $expected): array
    {
        $items = $data['data'] ?? null;
        if (!\is_array($items) || \count($items) !== $expected) {
            throw EmbeddingException::invalidResponse($this->model, \sprintf('expected %d embeddings in "data", got %s', $expected, \is_array($items) ? (string) \count($items) : 'none'));
        }
        $vectors = [];
        foreach ($items as $position => $item) {
            $embedding = \is_array($item) ? ($item['embedding'] ?? null) : null;
            $index = \is_array($item) && \is_int($item['index'] ?? null) ? $item['index'] : $position;
            if (!\is_array($embedding) || \count($embedding) !== $this->dimensions) {
                throw EmbeddingException::invalidResponse($this->model, \sprintf('embedding %s has %s dimensions, %d expected', $index, \is_array($embedding) ? (string) \count($embedding) : 'no', $this->dimensions));
            }
            $vector = [];
            foreach ($embedding as $value) {
                $vector[] = \is_int($value) || \is_float($value) ? (float) $value : throw EmbeddingException::invalidResponse($this->model, \sprintf('embedding %s holds a non-numeric value', $index));
            }
            $vectors[$index] = $vector;
        }
        ksort($vectors);
        $vectors = array_values($vectors);
        if ([] === $vectors || \count($vectors) !== $expected) {
            throw EmbeddingException::invalidResponse($this->model, 'duplicate "index" values');
        }

        return $vectors;
    }
}
