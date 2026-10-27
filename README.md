# orion-search/opensearch

Orion's engine adapter for OpenSearch 2.19 and 3.x, on the official `opensearch-project/opensearch-php` client
(one client major speaks to both server lines).

```sh
composer require orion-search/opensearch
```

```php
use OpenSearch\GuzzleClientFactory;
use Orion\OpenSearch\OpenSearchEngine;
use Orion\Registry\EngineRegistry;

$engines = new EngineRegistry(opensearch: new OpenSearchEngine((new GuzzleClientFactory())->create(['base_uri' => $url])));
// or, from a URL: OpenSearchEngine::connect($url)
```

- Mappings are `dynamic: strict`: a document carrying an undeclared field fails alone instead of growing the mapping.
- Writes and deletes are externally versioned with the outbox offset; a version conflict is a stale write, never a
  failure, so replayed and live writes converge.
- `integer` maps to `long`, `float` to `double`; any other native type inspects as unknown and fails a plan closed.
- A vector is a `knn_vector` on the Lucene engine (HNSW, the metric as its space type) and turns `index.knn` on for
  the index; the neutral vector query is the k-NN plugin's `knn` query, filters applied while the neighbours are
  gathered. Adding a vector field to an index created without one needs a reindex.
- `IndexSchema::native('opensearch', ['settings' => [...], 'mappings' => [...]])` merges over the generated body.
- `raw()` takes `callable(): array` returning a search body; scope filters are ANDed into it and into every `knn`
  query; search pipelines, aggregations, suggesters and derived fields are refused under a scope.
- Native hybrid search needs a search pipeline whose normalisation is not Orion's deterministic fusion, so
  `HybridFusion` is reported false and core fuses.

Proven by `Orion\Testing\EngineConformanceTestCase` against OpenSearch 3.9 and 2.19.

Connecting it and how it compares with the other engines: the [Engines guide](../../docs/guides/engines.md#opensearch).
