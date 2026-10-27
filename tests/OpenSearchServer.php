<?php

declare(strict_types=1);

namespace Orion\OpenSearch\Tests;

use Orion\OpenSearch\OpenSearchEngine;
use PHPUnit\Framework\TestCase;
use stdClass;

use function base64_encode;
use function getenv;
use function json_decode;

/**
 * The OpenSearch node the service-backed suites run against: ORION_OPENSEARCH_URL (3.x) or ORION_OPENSEARCH2_URL
 * (2.19), one client for both.
 */
final class OpenSearchServer
{
    public static function engine(string $variable, TestCase $test): OpenSearchEngine
    {
        $url = getenv($variable);
        if ($url === false) {
            $test->markTestSkipped($variable . ' is not set.');
        }

        return OpenSearchEngine::connect($url);
    }

    public static function matchAll(): callable
    {
        return static fn(): array => ['query' => ['match_all' => new stdClass()], 'size' => 100];
    }

    /**
     * Native bodies the escape hatch accepts under a scope, each matching every seeded document.
     *
     * @return array<string, callable(): array<string, mixed>>
     */
    public static function shapes(): array
    {
        return [
            'bool with should' => static fn(): array => ['query' => ['bool' => ['should' => [['match' => ['title' => 'running']], ['match_all' => new stdClass()]]]], 'size' => 100],
            'function score' => static fn(): array => ['query' => ['function_score' => ['query' => ['match_all' => new stdClass()], 'random_score' => new stdClass()]], 'size' => 100],
            'knn only' => static fn(): array => ['query' => ['knn' => ['embedding' => ['vector' => [1.0, 0.0, 0.0], 'k' => 5]]], 'size' => 100],
            'knn in a bool' => static fn(): array => ['query' => ['bool' => ['should' => [['knn' => ['embedding' => ['vector' => [0.0, 1.0, 0.0], 'k' => 5]]], ['match_all' => new stdClass()]]]], 'size' => 100],
            'knn in a post filter' => static fn(): array => ['query' => ['match_all' => new stdClass()], 'post_filter' => ['knn' => ['embedding' => ['vector' => [0.0, 1.0, 0.0], 'k' => 5]]], 'size' => 100],
            'post filter' => static fn(): array => ['query' => ['match_all' => new stdClass()], 'post_filter' => ['exists' => ['field' => 'sku']], 'size' => 100],
        ];
    }

    /**
     * Native bodies that read across the index and must be refused under a scope.
     *
     * @return array<string, callable(): array<string, mixed>>
     */
    public static function escapes(): array
    {
        return [
            'global aggregation' => static fn(): array => ['query' => ['match_all' => new stdClass()], 'size' => 0, 'aggs' => ['g' => ['global' => new stdClass(), 'aggs' => ['b' => ['terms' => ['field' => 'brand']]]]]],
            'suggester' => static fn(): array => ['suggest' => ['s' => ['text' => 'runing', 'term' => ['field' => 'title']]]],
            'search pipeline' => static fn(): array => ['search_pipeline' => 'p', 'query' => ['match_all' => new stdClass()]],
        ];
    }

    /**
     * Native bodies that would reach the foreign tenant unscoped.
     *
     * @return array<string, callable(): array<string, mixed>>
     */
    public static function isolationShapes(): array
    {
        return [
            'foreign ids' => static fn(): array => ['query' => ['ids' => ['values' => ['globex:p1', 'globex:p4']]], 'size' => 100],
            'the foreign tenant by name' => static fn(): array => ['query' => ['term' => ['org_id' => 'globex']], 'size' => 100],
            'should with match all' => static fn(): array => ['query' => ['bool' => ['should' => [['term' => ['org_id' => 'globex']], ['match_all' => new stdClass()]]]], 'size' => 100],
            'knn filtered to the foreign tenant' => static fn(): array => ['query' => ['knn' => ['embedding' => ['vector' => [0.0, 1.0, 0.0], 'k' => 10, 'filter' => ['term' => ['org_id' => 'globex']]]]], 'size' => 100],
            'post filter' => static fn(): array => ['query' => ['match_all' => new stdClass()], 'post_filter' => ['term' => ['org_id' => 'globex']], 'size' => 100],
            'collapse' => static fn(): array => ['query' => ['match_all' => new stdClass()], 'collapse' => ['field' => 'brand'], 'size' => 100],
        ];
    }

    /**
     * k-NN queries aimed at the foreign tenant's nearest vector, one per placement in the body: scoped, each must
     * still return the tenant's own nearest neighbour.
     *
     * @return array<string, callable(): array<string, mixed>>
     */
    public static function isolationProbes(): array
    {
        $knn = ['knn' => ['embedding' => ['vector' => [0.0, 1.0, 0.0], 'k' => 1]]];

        return [
            'knn at the foreign tenant' => static fn(): array => ['query' => $knn, 'size' => 100],
            'knn at the foreign tenant in a bool' => static fn(): array => ['query' => ['bool' => ['must' => [$knn]]], 'size' => 100],
            'knn at the foreign tenant in a post filter' => static fn(): array => ['query' => ['match_all' => new stdClass()], 'post_filter' => $knn, 'size' => 100],
            'knn at the foreign tenant in a rescore' => static fn(): array => ['query' => ['match_all' => new stdClass()], 'rescore' => ['window_size' => 10, 'query' => ['rescore_query' => $knn, 'query_weight' => 0]], 'size' => 100],
            'knn at the foreign tenant inside a knn filter' => static fn(): array => ['query' => ['knn' => ['embedding' => ['vector' => [0.0, 1.0, 0.0], 'k' => 10, 'filter' => $knn]]], 'size' => 100],
            'knn at the foreign tenant as decoded JSON' => static fn(): array => ['query' => json_decode('{"knn":{"embedding":{"vector":[0.0,1.0,0.0],"k":1}}}'), 'size' => 100],
        ];
    }

    /**
     * @return array<string, callable(): array<string, mixed>>
     */
    public static function isolationEscapes(string $index): array
    {
        return [
            'aggregation' => static fn(): array => ['size' => 0, 'aggs' => ['tenants' => ['terms' => ['field' => 'org_id']]]],
            'global aggregation' => static fn(): array => ['query' => ['match_all' => new stdClass()], 'size' => 0, 'aggs' => ['g' => ['global' => new stdClass(), 'aggs' => ['b' => ['terms' => ['field' => 'brand']]]]]],
            'suggester' => static fn(): array => ['suggest' => ['s' => ['text' => 'globx', 'term' => ['field' => 'title']]]],
            'search pipeline' => static fn(): array => ['search_pipeline' => 'p', 'query' => ['match_all' => new stdClass()]],
            'derived field' => static fn(): array => ['derived' => ['x' => ['type' => 'keyword', 'script' => 'emit("a")']], 'query' => ['match_all' => new stdClass()]],
            'terms lookup of a foreign document' => static fn(): array => ['query' => ['terms' => ['brand' => ['index' => $index, 'id' => 'globex:p1', 'path' => 'brand']]]],
            'more like a foreign document' => static fn(): array => ['query' => ['more_like_this' => ['fields' => ['title'], 'like' => [['_index' => $index, '_id' => 'globex:p1']], 'min_term_freq' => 1, 'min_doc_freq' => 1]]],
            'a wrapped query' => static fn(): array => ['query' => ['wrapper' => ['query' => base64_encode('{"match_all":{}}')]]],
        ];
    }
}
