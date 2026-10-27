# Prism ORM — embedding-openai

The embedding provider of [Prism](https://github.com/prism-orm/prism) for the `POST /v1/embeddings` API in the OpenAI format. The same format serves OpenAI, Mistral, Ollama (`/v1`), vLLM, LocalAI and Text Embeddings Inference.

```bash
composer require prism-orm/embedding-openai symfony/http-client
```

```php
use Prism\Embedding\OpenAi\OpenAiCompatibleProvider;
use Symfony\Component\HttpClient\HttpClient;

$provider = new OpenAiCompatibleProvider(
    HttpClient::create(),
    baseUrl: 'http://ollama:11434/v1',
    model: 'nomic-embed-text:v1.5',
    dimensions: 768,
    apiKey: null,     // 'sk-…' for OpenAI or Mistral
    batchSize: 64,    // texts per request
);
```

The provider sends the texts in batches, one request per batch, and checks the number and the dimension of the vectors it receives. An HTTP error, a network error or an unusable response raises `Prism\Orm\Exception\EmbeddingException`: the rows stay stale, and a `ComputeEmbedding` message is retried.

In a Symfony application, the bundle configures it:

```yaml
prism:
    embeddings:
        providers:
            default:
                openai: { base_url: 'http://ollama:11434/v1', model: 'nomic-embed-text:v1.5', dimensions: 768 }
```

This package is a read-only split of the Prism monorepo: open issues and pull requests there.
