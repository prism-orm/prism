<?php

declare(strict_types=1);

namespace Prism\Embedding\OpenAi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Embedding\OpenAi\OpenAiCompatibleProvider;
use Prism\Orm\Exception\EmbeddingException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * L'adaptateur au format OpenAI, sans réseau (ADR 0018).
 */
final class OpenAiCompatibleProviderTest extends TestCase
{
    /**
     * @param list<list<float>> $vectors
     */
    private static function response(array $vectors, bool $reversed = false): MockResponse
    {
        $data = array_map(static fn (int $i, array $v): array => ['object' => 'embedding', 'index' => $i, 'embedding' => $v], array_keys($vectors), $vectors);

        return new MockResponse((string) json_encode(['object' => 'list', 'data' => $reversed ? array_reverse($data) : $data, 'model' => 'm']));
    }

    public function testPostsBatchesAndKeepsTheOrderOfTheIndexes(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];
            $body = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '', true);
            $count = \is_array($body) && \is_array($body['input'] ?? null) ? \count($body['input']) : 0;

            return self::response(array_map(static fn (int $i): array => [(float) $i, 1.0], range(1, $count)), reversed: true);
        });
        $provider = new OpenAiCompatibleProvider($client, 'http://ollama:11434/v1/', 'nomic-embed-text:v1.5', 2, batchSize: 2);

        $vectors = $provider->embed(['a', 'b', 'c']);

        self::assertSame([[1.0, 1.0], [2.0, 1.0], [1.0, 1.0]], $vectors, 'two requests, each in index order');
        self::assertCount(2, $requests);
        self::assertSame(['POST', 'http://ollama:11434/v1/embeddings'], [$requests[0][0], $requests[0][1]]);
        self::assertSame(['model' => 'nomic-embed-text:v1.5', 'input' => ['a', 'b']], json_decode(\is_string($requests[0][2]['body'] ?? null) ? $requests[0][2]['body'] : '', true));
        self::assertSame(['nomic-embed-text:v1.5', 2], [$provider->model(), $provider->dimensions()]);
    }

    public function testSendsTheKeyAndTheDimensionWhenAsked(): void
    {
        $sent = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = $options;

            return self::response([[0.5, 0.5]]);
        });
        new OpenAiCompatibleProvider($client, 'https://api.openai.com/v1', 'text-embedding-3-small', 2, 'sk-test', sendDimensions: true)->embed(['a']);

        $headers = \is_array($sent['headers'] ?? null) ? $sent['headers'] : [];
        self::assertContains('Authorization: Bearer sk-test', $headers);
        self::assertSame(['model' => 'text-embedding-3-small', 'input' => ['a'], 'dimensions' => 2], json_decode(\is_string($sent['body'] ?? null) ? $sent['body'] : '', true));
    }

    // nomic-embed-text : un préfixe pour les documents, un autre pour les questions
    public function testPrefixesEachText(): void
    {
        $sent = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = $options;

            return self::response([[0.5, 0.5], [0.5, 0.5]]);
        });
        new OpenAiCompatibleProvider($client, 'http://ollama:11434/v1', 'nomic-embed-text:v1.5', 2, prefix: 'search_query: ')->embed(['partial payment', 'stock']);

        $body = json_decode(\is_string($sent['body'] ?? null) ? $sent['body'] : '', true);
        self::assertIsArray($body);
        self::assertSame(['search_query: partial payment', 'search_query: stock'], $body['input'] ?? null);
    }

    /**
     * @return iterable<string, array{MockResponse, string}>
     */
    public static function failures(): iterable
    {
        yield 'HTTP error' => [new MockResponse('{"error":{"message":"Rate limit reached"}}', ['http_code' => 429]), 'The embedding provider "m" returned an unusable response: HTTP 429, {"error":{"message":"Rate limit reached"}}.'];
        yield 'missing embeddings' => [self::response([[1.0, 0.0]]), 'The embedding provider "m" returned an unusable response: expected 2 embeddings in "data", got 1.'];
        yield 'wrong dimension' => [self::response([[1.0], [1.0]]), 'The embedding provider "m" returned an unusable response: embedding 0 has 1 dimensions, 2 expected.'];
        yield 'not JSON' => [new MockResponse('<html>gateway</html>'), 'The embedding provider "m" failed: '];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testUnusableResponsesRaiseAnEmbeddingException(MockResponse $response, string $message): void
    {
        $this->expectException(EmbeddingException::class);
        $this->expectExceptionMessage($message);
        new OpenAiCompatibleProvider(new MockHttpClient($response), 'http://x/v1', 'm', 2)->embed(['a', 'b']);
    }

    public function testTransportErrorRaisesAnEmbeddingException(): void
    {
        $client = new MockHttpClient(static fn (): never => throw new TransportException('Connection refused'));

        $this->expectException(EmbeddingException::class);
        $this->expectExceptionMessage('The embedding provider "m" failed: Connection refused');
        new OpenAiCompatibleProvider($client, 'http://x/v1', 'm', 2)->embed(['a']);
    }
}
