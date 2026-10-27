<?php

declare(strict_types=1);

namespace Orion\OpenSearch\Tests;

use InvalidArgumentException;
use JsonSerializable;
use Orion\Adapter\UnsupportedClause;
use Orion\OpenSearch\QueryCompiler;
use Orion\Query\Clause;
use Orion\Query\Direction;
use Orion\Query\Filter;
use Orion\Query\Fusion;
use Orion\Query\Query;
use Orion\Query\Sort;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function json_decode;
use function json_encode;

final class QueryCompilerTest extends TestCase
{
    public function testALexicalQueryCompilesToStructuredDsl(): void
    {
        $query = Query::on('products')
            ->match('title', 'running shoe')
            ->filter(Filter::range('price', lte: 100))
            ->filter(Filter::bool(must: [Filter::term('in_stock', true)], should: [Filter::term('brand', 'acme')]))
            ->facet('brand', size: 5)
            ->sort(Sort::SCORE, Direction::Desc)
            ->highlight('title')
            ->paginate(page: 2, size: 20);

        self::assertSame(
            '{"from":20,"size":20,"query":{"bool":{"must":[{"match":{"title":{"query":"running shoe"}}}],"filter":[{"range":{"price":{"lte":100}}},'
            . '{"bool":{"must":[{"term":{"in_stock":true}}],"should":[{"term":{"brand":"acme"}}],"must_not":[],"minimum_should_match":1}}]}},'
            . '"sort":[{"_score":{"order":"desc"}}],"aggs":{"brand":{"terms":{"field":"brand","size":5}}},'
            . '"highlight":{"encoder":"html","pre_tags":["<em>"],"post_tags":["<\/em>"],"fields":{"title":{}}}}',
            json_encode((new QueryCompiler())->compile($query)),
        );
    }

    public function testAMatchOverSeveralWeightedFieldsIsAMultiMatch(): void
    {
        $body = (new QueryCompiler())->compile(Query::on('products')->match(['title' => 3.0, 'description' => 1.0], 'shoe'));

        self::assertSame(['multi_match' => ['query' => 'shoe', 'fields' => ['title^3', 'description'], 'type' => 'best_fields']], $body['query']['bool']['must'][0]);
    }

    public function testAWeightedClauseCarriesItsBoost(): void
    {
        $body = (new QueryCompiler())->compile(Query::on('products')->withClauses([Clause::match('title', 'shoe')->weighted(['title' => 0.25]), Clause::prefix('sku', 'A')->weighted(['sku' => 2.0])]));

        self::assertSame([['match' => ['title' => ['query' => 'shoe', 'boost' => 0.25]]], ['prefix' => ['sku' => ['value' => 'A', 'boost' => 2.0]]]], $body['query']['bool']['must']);
    }

    public function testAVectorQueryIsAKnnQueryPreFilteringTheNeighbours(): void
    {
        $body = (new QueryCompiler())->compile(Query::on('products')->vector('embedding', [0.1, 0.2], k: 5)->filter(Filter::term('brand', 'acme')));

        self::assertSame(['knn' => ['embedding' => ['vector' => [0.1, 0.2], 'k' => 5, 'filter' => ['bool' => ['filter' => [['term' => ['brand' => 'acme']]]]]]]], $body['query']);
        self::assertArrayNotHasKey('knn', $body);
    }

    public function testAnUnfilteredVectorQueryCarriesNoFilter(): void
    {
        $body = (new QueryCompiler())->compile(Query::on('products')->vector('embedding', [0.1, 0.2], k: 5));

        self::assertSame(['knn' => ['embedding' => ['vector' => [0.1, 0.2], 'k' => 5]]], $body['query']);
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function vectorPages(): iterable
    {
        yield 'a page wider than k' => [5, 1, 20, 5];
        yield 'a page inside k' => [5, 2, 2, 2];
        yield 'a page reaching past k' => [5, 2, 3, 2];
        yield 'a page beyond k' => [5, 3, 3, 0];
    }

    /**
     * The plugin returns k neighbours per shard, so the page is cut at k itself: what Elasticsearch's global k gives.
     */
    #[DataProvider('vectorPages')]
    public function testAVectorQueryNeverPagesPastK(int $k, int $page, int $size, int $expected): void
    {
        $body = (new QueryCompiler())->compile(Query::on('products')->vector('embedding', [0.1, 0.2], k: $k)->paginate($page, $size));

        self::assertSame(($page - 1) * $size, $body['from']);
        self::assertSame($expected, $body['size']);
    }

    public function testAnEmptyQueryMatchesEverything(): void
    {
        self::assertEquals(['bool' => ['must' => [['match_all' => new stdClass()]]]], (new QueryCompiler())->compile(Query::on('products'))['query']);
    }

    public function testCompilingIsPure(): void
    {
        $query = Query::on('products')->match('title', 'shoe')->filter(Filter::terms('brand', ['a', 'b']));

        self::assertSame(json_encode((new QueryCompiler())->compile($query)), json_encode((new QueryCompiler())->compile($query)));
    }

    public function testHybridRetrievalIsLeftToCore(): void
    {
        $this->expectException(UnsupportedClause::class);

        (new QueryCompiler())->compile(Query::on('products')->match('title', 'x')->vector('embedding', [0.1], k: 2)->fuse(Fusion::reciprocalRank()));
    }

    public function testScopeFiltersEveryKnnQueryAndTheQueryItself(): void
    {
        $scoped = (new QueryCompiler())->scope([
            'query' => ['bool' => ['should' => [
                ['knn' => ['a' => ['vector' => [1.0], 'k' => 2, 'filter' => ['term' => ['x' => 1]]]]],
                ['knn' => ['b' => ['vector' => [1.0], 'k' => 2]]],
            ]]],
        ], [Filter::term('org_id', 'org_1')]);

        $scope = '{"term":{"org_id":"org_1"}}';
        self::assertSame(
            '{"query":{"bool":{"must":[{"bool":{"should":['
            . '{"knn":{"a":{"vector":[1],"k":2,"filter":{"bool":{"filter":[{"term":{"x":1}},' . $scope . ']}}}}},'
            . '{"knn":{"b":{"vector":[1],"k":2,"filter":{"bool":{"filter":[' . $scope . ']}}}}}'
            . ']}}],"filter":[' . $scope . ']}}}',
            json_encode($scoped),
        );
    }

    /**
     * A k-NN query anywhere in the body gathers neighbours; unscoped, whether a tenant's document is among them would
     * tell where the other tenants' vectors lie.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function knnPlacements(): iterable
    {
        $knn = ['knn' => ['v' => ['vector' => [1.0], 'k' => 1]]];
        $scoped = '{"knn":{"v":{"vector":[1],"k":1,"filter":{"bool":{"filter":[{"term":{"org_id":"org_1"}}]}}}}}';
        yield 'in a post filter' => [['query' => ['match_all' => new stdClass()], 'post_filter' => $knn], '"post_filter":' . $scoped];
        yield 'in a rescore query' => [['query' => ['match_all' => new stdClass()], 'rescore' => ['query' => ['rescore_query' => $knn]]], '"rescore_query":' . $scoped];
        yield 'in a highlight query' => [['query' => ['match_all' => new stdClass()], 'highlight' => ['fields' => ['t' => ['highlight_query' => $knn]]]], '"highlight_query":' . $scoped];
        yield 'as decoded JSON objects' => [['query' => json_decode('{"bool":{"should":[{"knn":{"v":{"vector":[1.0],"k":1}}}]}}')], '"should":[' . $scoped . ']'];
        yield 'inside another k-NN query\'s filter' => [
            ['query' => ['knn' => ['w' => ['vector' => [0.0], 'k' => 3, 'filter' => $knn]]]],
            '"filter":{"bool":{"filter":[' . $scoped . ',{"term":{"org_id":"org_1"}}]}}',
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('knnPlacements')]
    public function testScopeReachesAKnnQueryAnywhereInTheBody(array $body, string $expected): void
    {
        $scoped = (new QueryCompiler())->scope($body, [Filter::term('org_id', 'org_1')]);

        self::assertStringContainsString($expected, (string) json_encode($scoped));
    }

    public function testScopeKeepsEmptyObjectsAsObjects(): void
    {
        $scoped = (new QueryCompiler())->scope(['query' => ['match_all' => new stdClass()], 'highlight' => ['fields' => ['title' => new stdClass()]]], [Filter::term('org_id', 'org_1')]);

        self::assertStringContainsString('"match_all":{}', (string) json_encode($scoped));
        self::assertStringContainsString('"title":{}', (string) json_encode($scoped));
    }

    public function testAReferenceHiddenInASerializableObjectIsStillRefused(): void
    {
        $lookup = new class implements JsonSerializable {
            /**
             * @return array<string, string>
             */
            public function jsonSerialize(): array
            {
                return ['index' => 'products', 'id' => 'globex:p1', 'path' => 'brand'];
            }
        };

        $this->expectException(InvalidArgumentException::class);

        (new QueryCompiler())->scope(['query' => ['terms' => ['brand' => $lookup]]], [Filter::term('org_id', 'org_1')]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function escapes(): iterable
    {
        yield 'global aggregation' => [['query' => ['match_all' => new stdClass()], 'aggs' => ['g' => ['global' => new stdClass()]]]];
        yield 'any aggregation' => [['aggs' => ['b' => ['terms' => ['field' => 'brand']]]]];
        yield 'suggester' => [['suggest' => ['s' => ['text' => 'x', 'term' => ['field' => 'title']]]]];
        yield 'a search pipeline' => [['search_pipeline' => 'p', 'query' => ['match_all' => new stdClass()]]];
        yield 'an ext section' => [['ext' => ['rerank' => ['query_context' => ['query_text' => 'x']]], 'query' => ['match_all' => new stdClass()]]];
        yield 'derived fields' => [['derived' => ['x' => ['type' => 'keyword', 'script' => 'emit("a")']], 'query' => ['match_all' => new stdClass()]]];
        yield 'script fields' => [['script_fields' => ['x' => ['script' => 'doc']]]];
        yield 'terms lookup of a foreign document' => [['query' => ['terms' => ['brand' => ['index' => 'products', 'id' => 'globex:p1', 'path' => 'brand']]]]];
        yield 'terms lookup nested in bool' => [['query' => ['bool' => ['filter' => [['terms' => ['brand' => ['index' => 'products', 'id' => 'globex:p1', 'path' => 'brand']]]]]]]];
        yield 'more like a foreign document' => [['query' => ['more_like_this' => ['fields' => ['title'], 'like' => [['_index' => 'products', '_id' => 'globex:p1']]]]]];
        yield 'percolate a foreign document' => [['query' => ['percolate' => ['field' => 'q', 'index' => 'products', 'id' => 'globex:p1']]]];
        yield 'an indexed shape' => [['query' => ['geo_shape' => ['area' => ['indexed_shape' => ['index' => 'shapes', 'id' => 'globex:s1', 'path' => 'area']]]]]];
        yield 'a wrapped query, uninspectable' => [['query' => ['wrapper' => ['query' => 'eyJtYXRjaF9hbGwiOnt9fQ==']]]];
        yield 'a lookup in the k-NN filter' => [['query' => ['knn' => ['v' => ['vector' => [1.0], 'k' => 1, 'filter' => ['terms' => ['brand' => ['index' => 'products', 'id' => 'globex:p1', 'path' => 'brand']]]]]]]];
        yield 'a lookup in the rescore query' => [['query' => ['match_all' => new stdClass()], 'rescore' => ['query' => ['rescore_query' => ['more_like_this' => ['like' => [['_id' => 'globex:p1']]]]]]]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('escapes')]
    public function testAScopedNativeBodyMayNotReadOutsideTheScope(array $body): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new QueryCompiler())->scope($body, [Filter::term('org_id', 'org_1')]);
    }

    public function testAScopedBodyMayStillUseOrdinaryTermsAndQueries(): void
    {
        $body = ['query' => ['bool' => ['must' => [['terms' => ['brand' => ['a', 'b']]], ['match' => ['title' => 'index']]]]]];

        $scoped = (new QueryCompiler())->scope($body, [Filter::term('org_id', 'org_1')]);

        self::assertSame(json_encode($body['query']), json_encode($scoped['query']['bool']['must'][0]));
    }

    public function testAnUnscopedNativeBodyIsLeftAlone(): void
    {
        $body = ['suggest' => ['s' => ['text' => 'x', 'term' => ['field' => 'title']]]];

        self::assertSame($body, (new QueryCompiler())->scope($body, []));
    }

    public function testHighlightsAreHtmlEncodedByTheEngine(): void
    {
        self::assertSame('html', (new QueryCompiler())->compile(Query::on('products')->match('title', 'x')->highlight('title'))['highlight']['encoder']);
    }

    public function testTheEscapeHatchIsAndedWithTheScope(): void
    {
        $scoped = (new QueryCompiler())->scope(['query' => ['function_score' => ['query' => ['match' => ['title' => 'x']]]]], [Filter::term('org_id', 'org_1')]);

        self::assertSame(
            '{"bool":{"must":[{"function_score":{"query":{"match":{"title":"x"}}}}],"filter":[{"term":{"org_id":"org_1"}}]}}',
            json_encode($scoped['query']),
        );
    }
}
