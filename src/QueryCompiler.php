<?php

declare(strict_types=1);

namespace Orion\OpenSearch;

use InvalidArgumentException;
use LogicException;
use Orion\Adapter\Capability;
use Orion\Adapter\UnsupportedClause;
use Orion\Query\BoolFilter;
use Orion\Query\Clause;
use Orion\Query\ClauseKind;
use Orion\Query\ExistsFilter;
use Orion\Query\Facet;
use Orion\Query\Filter;
use Orion\Query\Query;
use Orion\Query\RangeFilter;
use Orion\Query\Sort;
use Orion\Query\TermFilter;
use Orion\Query\TermsFilter;
use Orion\Support\Assert;
use stdClass;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_array;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function rtrim;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * The neutral query AST compiled to an OpenSearch search body: a pure function, so the same query always compiles
 * to the same DSL (and `compile_query` can show it without executing). Values are placed in structured queries only,
 * never in a query string. The Query DSL is Elasticsearch 7.10's with OpenSearch's additions; k-NN is the bundled
 * plugin's `knn` query inside `query`, its filter applied while the neighbours are gathered.
 */
final class QueryCompiler
{
    /** Top-level search keys that only narrow, page, sort or present the scoped documents. */
    private const array SCOPED_KEYS = [
        'query', 'size', 'from', 'sort', '_source', 'highlight', 'post_filter', 'track_total_hits',
        'min_score', 'search_after', 'rescore', 'collapse', 'timeout',
    ];

    /**
     * Keys that, anywhere in a scoped body, read a document by reference (terms lookup, more-like-this documents,
     * percolation, indexed shapes) or hide a query from inspection (`wrapper`): each would let a query use another
     * tenant's document even though only the tenant's documents come back.
     */
    private const array REFERENCE_KEYS = ['index', '_index', 'more_like_this', 'percolate', 'indexed_shape', 'wrapper'];

    /** How deep a native body may nest, as JSON. */
    private const int MAX_DEPTH = 64;

    public function __construct(private readonly string $engine = OpenSearchEngine::NAME)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function compile(Query $query): array
    {
        if ($query->vector !== null && $query->clauses !== []) {
            throw UnsupportedClause::missing(Capability::HybridFusion, 'hybrid retrieval', $this->engine);
        }
        $filters = array_map($this->filter(...), $query->filters);
        $from = ($query->page->page - 1) * $query->page->size;
        $body = ['from' => $from, 'size' => $query->page->size];
        if ($query->vector !== null) {
            $k = $query->vector->k;
            // The plugin gathers k neighbours per shard; cutting the page at k keeps the global-k promise on any index.
            $body['size'] = min($query->page->size, max(0, $k - $from));
            $body['query'] = ['knn' => [$query->vector->field => [
                'vector' => $query->vector->vector,
                'k' => $k,
                ...($filters === [] ? [] : ['filter' => ['bool' => ['filter' => $filters]]]),
            ]]];
        } else {
            $body['query'] = ['bool' => array_filter([
                'must' => array_map($this->clause(...), $query->clauses),
                'filter' => $filters,
            ], static fn(array $part): bool => $part !== []) ?: ['must' => [['match_all' => new stdClass()]]]];
        }
        if ($query->sorts !== []) {
            $body['sort'] = array_map(static fn(Sort $sort): array => [$sort->field => ['order' => $sort->direction->value]], $query->sorts);
        }
        if ($query->facets !== []) {
            $aggregations = [];
            foreach ($query->facets as $facet) {
                $aggregations[$facet->field] = self::facet($facet);
            }
            $body['aggs'] = $aggregations;
        }
        if ($query->highlights !== []) {
            $fields = [];
            foreach ($query->highlights as $field) {
                $fields[$field] = new stdClass();
            }
            // The html encoder escapes the stored text, so a fragment's only markup is the <em> around matches.
            $body['highlight'] = ['encoder' => 'html', 'pre_tags' => ['<em>'], 'post_tags' => ['</em>'], 'fields' => $fields];
        }

        return $body;
    }

    /**
     * A native body restricted by neutral filters, for the scoped escape hatch. Under a scope only keys that act
     * within the matched documents are accepted; anything that reads index-wide (aggregations, suggesters, search
     * pipelines, derived fields, scripts) is refused, because the scope is what keeps one tenant from another. The
     * body is first taken as the JSON it would be sent as (objects as objects, lists as lists), so what is scoped is
     * what the engine reads. The filters are ANDed with the query and, wherever a `knn` query sits (the query, a post
     * filter, a rescore, a highlight query, another k-NN query's filter), into its own filter, so the neighbours are
     * gathered within the scope: gathered across it, whether a tenant's document came back would tell where the
     * other tenants' vectors lie.
     *
     * @param array<array-key, mixed> $body
     * @param array<Filter> $scope
     * @return array<array-key, mixed>
     */
    public function scope(array $body, array $scope): array
    {
        if ($scope === []) {
            return $body;
        }
        foreach ($body as $key => $_) {
            if (!in_array($key, self::SCOPED_KEYS, true)) {
                throw new InvalidArgumentException(sprintf('A scoped native query cannot use %s: it reads outside the scope. Allowed: %s.', Assert::show((string) $key), implode(', ', self::SCOPED_KEYS)));
            }
        }
        $body = (array) json_decode(json_encode($body, JSON_THROW_ON_ERROR), false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        self::refuseReferences($body);
        $filters = array_map($this->filter(...), array_values($scope));
        $body = self::filterKnn($body, $filters);
        $body['query'] = ['bool' => ['must' => [$body['query'] ?? ['match_all' => new stdClass()]], 'filter' => $filters]];

        return $body;
    }

    /**
     * The node with every `knn` query in it, at any depth, its filter ANDed with the scope; objects stay objects.
     *
     * @param list<array<string, mixed>> $filters
     */
    private static function filterKnn(mixed $node, array $filters): mixed
    {
        if (!is_array($node) && !$node instanceof stdClass) {
            return $node;
        }
        $scoped = [];
        foreach ((array) $node as $key => $value) {
            $scoped[$key] = $key === 'knn' ? self::filterKnnFields($value, $filters) : self::filterKnn($value, $filters);
        }

        return $node instanceof stdClass ? (object) $scoped : $scoped;
    }

    /**
     * @param mixed $knn the `knn` query: one field, its vector, k and optional filter (anything else is left for the
     *                   engine to refuse)
     * @param list<array<string, mixed>> $filters
     */
    private static function filterKnnFields(mixed $knn, array $filters): mixed
    {
        if (!$knn instanceof stdClass) {
            return self::filterKnn($knn, $filters);
        }
        $scoped = [];
        foreach ((array) $knn as $field => $entry) {
            if (!$entry instanceof stdClass) {
                $scoped[$field] = self::filterKnn($entry, $filters);
                continue;
            }
            $entry = (array) $entry;
            $existing = isset($entry['filter']) ? [self::filterKnn($entry['filter'], $filters)] : [];
            $entry['filter'] = ['bool' => ['filter' => [...$existing, ...$filters]]];
            $scoped[$field] = (object) $entry;
        }

        return (object) $scoped;
    }

    /**
     * @param array<array-key, mixed> $node decoded JSON: arrays and stdClass only
     */
    private static function refuseReferences(array $node): void
    {
        foreach ($node as $key => $value) {
            if (in_array($key, self::REFERENCE_KEYS, true)) {
                throw new InvalidArgumentException(sprintf('A scoped native query cannot use %s: it reads documents outside the scope by reference.', Assert::show((string) $key)));
            }
            if (is_array($value) || $value instanceof stdClass) {
                self::refuseReferences((array) $value);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function filter(Filter $filter): array
    {
        return match (true) {
            $filter instanceof TermFilter => ['term' => [$filter->field => $filter->value]],
            $filter instanceof TermsFilter => ['terms' => [$filter->field => $filter->values]],
            $filter instanceof RangeFilter => ['range' => [$filter->field => array_filter(
                ['gt' => $filter->gt, 'gte' => $filter->gte, 'lt' => $filter->lt, 'lte' => $filter->lte],
                static fn($bound): bool => $bound !== null,
            )]],
            $filter instanceof ExistsFilter => ['exists' => ['field' => $filter->field]],
            $filter instanceof BoolFilter => ['bool' => [
                'must' => array_map($this->filter(...), $filter->must),
                'should' => array_map($this->filter(...), $filter->should),
                'must_not' => array_map($this->filter(...), $filter->mustNot),
                // In filter context a `should` beside `must` is ignored unless told otherwise.
                ...($filter->should === [] ? [] : ['minimum_should_match' => 1]),
            ]],
            default => throw new LogicException('Unknown filter ' . $filter::class),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function clause(Clause $clause): array
    {
        if ($clause->kind === ClauseKind::Match && count($clause->fields) > 1) {
            $fields = [];
            foreach ($clause->fields as $field => $weight) {
                $fields[] = $weight === Clause::DEFAULT_WEIGHT ? $field : $field . '^' . self::weight($weight);
            }

            return ['multi_match' => ['query' => $clause->value, 'fields' => $fields, 'type' => 'best_fields']];
        }
        $weight = $clause->fields[$clause->field];
        $boost = $weight === Clause::DEFAULT_WEIGHT ? [] : ['boost' => $weight];

        return match ($clause->kind) {
            ClauseKind::Match => ['match' => [$clause->field => ['query' => $clause->value, ...$boost]]],
            ClauseKind::Term => ['term' => [$clause->field => ['value' => $clause->value, ...$boost]]],
            ClauseKind::Prefix => ['prefix' => [$clause->field => ['value' => $clause->value, ...$boost]]],
            ClauseKind::Phrase => ['match_phrase' => [$clause->field => ['query' => $clause->value, ...$boost]]],
        };
    }

    /**
     * A weight in a `fields` entry: a plain decimal, never exponent notation, which the engine would not parse.
     */
    private static function weight(float $weight): string
    {
        return rtrim(rtrim(sprintf('%.6F', $weight), '0'), '.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function facet(Facet $facet): array
    {
        return ['terms' => ['field' => $facet->field, 'size' => $facet->size]];
    }
}
