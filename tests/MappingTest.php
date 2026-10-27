<?php

declare(strict_types=1);

namespace Orion\OpenSearch\Tests;

use InvalidArgumentException;
use Orion\IndexName;
use Orion\OpenSearch\Mapping;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\IndexSchema;
use Orion\Schema\LiveSchema;
use Orion\Schema\MigrationPlan;
use Orion\Schema\VectorMetric;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class MappingTest extends TestCase
{
    public function testEveryNeutralTypeRoundTripsWithoutDrift(): void
    {
        $schema = IndexSchema::for('products')
            ->text('title', language: 'en', boost: 3.0)
            ->text('description', folding: true)
            ->text('body', language: 'de', folding: true)
            ->keyword('sku', filterable: true, sortable: true)
            ->keyword('brand', facetable: true)
            ->keyword('note')
            ->integer('stock', filterable: true)
            ->float('price', filterable: true, sortable: true, facetable: true)
            ->boolean('in_stock', filterable: true, facetable: true)
            ->date('released', sortable: true)
            ->geo('at', filterable: true, sortable: true)
            ->vector('embedding', dims: 3, metric: VectorMetric::DotProduct)
            ->object('address', Field::keyword('city', filterable: true))
            ->nested('variants', Field::keyword('size', filterable: true), Field::integer('qty', sortable: true));

        $live = new LiveSchema(IndexName::of('products'), Mapping::fields(Mapping::create($schema, 'opensearch')['mappings']));

        self::assertTrue(MigrationPlan::between($schema, $live)->isEmpty());
    }

    public function testAVectorIsAKnnVectorOnTheLuceneEngineAndTurnsKnnOnForTheIndex(): void
    {
        $body = Mapping::create(IndexSchema::for('products')->vector('embedding', dims: 3, metric: VectorMetric::Cosine), 'opensearch');

        self::assertSame(
            ['type' => 'knn_vector', 'dimension' => 3, 'method' => ['name' => 'hnsw', 'space_type' => 'cosinesimil', 'engine' => 'lucene']],
            $body['mappings']['properties']['embedding'],
        );
        self::assertTrue($body['settings']['index']['knn']);
        self::assertArrayNotHasKey('settings', Mapping::create(IndexSchema::for('products')->keyword('sku'), 'opensearch'));
    }

    /**
     * @return iterable<string, array{VectorMetric, string}>
     */
    public static function metrics(): iterable
    {
        yield 'cosine' => [VectorMetric::Cosine, 'cosinesimil'];
        yield 'dot product' => [VectorMetric::DotProduct, 'innerproduct'];
        yield 'euclidean' => [VectorMetric::Euclidean, 'l2'];
    }

    #[DataProvider('metrics')]
    public function testEveryMetricIsASpaceTypeAndReadsBack(VectorMetric $metric, string $spaceType): void
    {
        $body = Mapping::create(IndexSchema::for('products')->vector('embedding', dims: 2, metric: $metric), 'opensearch');

        self::assertSame($spaceType, $body['mappings']['properties']['embedding']['method']['space_type']);
        self::assertSame($metric, Mapping::fields($body['mappings'])[0]->metric);
    }

    public function testASpaceTypeOnTheFieldReadsBackToo(): void
    {
        $field = Mapping::fields(['properties' => ['embedding' => ['type' => 'knn_vector', 'dimension' => 2, 'space_type' => 'l2', 'method' => ['name' => 'hnsw', 'engine' => 'lucene']]]])[0];

        self::assertSame(VectorMetric::Euclidean, $field->metric);
        self::assertSame(2, $field->dims);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function foreignVectors(): iterable
    {
        yield 'an unknown space type' => [['type' => 'knn_vector', 'dimension' => 2, 'method' => ['name' => 'hnsw', 'space_type' => 'hamming', 'engine' => 'lucene']]];
        yield 'no space type' => [['type' => 'knn_vector', 'dimension' => 2, 'method' => ['name' => 'hnsw', 'engine' => 'lucene']]];
        yield 'no method, so the engine is the server default' => [['type' => 'knn_vector', 'dimension' => 2, 'space_type' => 'l2']];
        yield 'the faiss engine' => [['type' => 'knn_vector', 'dimension' => 2, 'method' => ['name' => 'hnsw', 'space_type' => 'l2', 'engine' => 'faiss']]];
        yield 'a byte vector' => [['type' => 'knn_vector', 'dimension' => 2, 'data_type' => 'byte', 'method' => ['name' => 'hnsw', 'space_type' => 'l2', 'engine' => 'lucene']]];
        yield 'a model-backed vector' => [['type' => 'knn_vector', 'model_id' => 'm1']];
    }

    /**
     * Only what Orion creates reads back as a vector; another engine or data type fails a plan closed.
     *
     * @param array<string, mixed> $property
     */
    #[DataProvider('foreignVectors')]
    public function testAKnnVectorOrionWouldNotHaveCreatedIsUnknown(array $property): void
    {
        self::assertSame(FieldType::Unknown, Mapping::fields(['properties' => ['v' => $property]])[0]->type);
    }

    public function testAFloatVectorOnLuceneReadsBackWhenSaidSo(): void
    {
        $field = Mapping::fields(['properties' => ['v' => ['type' => 'knn_vector', 'dimension' => 2, 'data_type' => 'float', 'method' => ['name' => 'hnsw', 'space_type' => 'cosinesimil', 'engine' => 'lucene']]]])[0];

        self::assertSame(FieldType::Vector, $field->type);
    }

    public function testMappingsAreStrict(): void
    {
        self::assertSame('strict', Mapping::create(IndexSchema::for('products')->keyword('sku'), 'opensearch')['mappings']['dynamic']);
    }

    public function testNativeSettingsMergeOverTheGeneratedBody(): void
    {
        $body = Mapping::create(IndexSchema::for('products')->keyword('sku')->native('opensearch', ['settings' => ['number_of_replicas' => 0]]), 'opensearch');

        self::assertSame(0, $body['settings']['number_of_replicas']);
    }

    public function testNativeSettingsCannotLoosenTheMappingOrForgeItsMeta(): void
    {
        $body = Mapping::create(IndexSchema::for('products')->keyword('sku')->native('opensearch', ['mappings' => [
            'dynamic' => true,
            '_meta' => ['orion' => ['fields' => ['forged' => ['type' => 'keyword']]]],
            'properties' => ['sku' => ['type' => 'keyword', 'ignore_above' => 64]],
        ]]), 'opensearch');

        self::assertSame('strict', $body['mappings']['dynamic']);
        self::assertSame(['sku' => ['type' => 'keyword']], $body['mappings']['_meta']['orion']['fields']);
        self::assertSame(64, $body['mappings']['properties']['sku']['ignore_above']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function knnOff(): iterable
    {
        yield 'nested' => [['index' => ['knn' => false]]];
        yield 'flat with the index prefix' => [['index.knn' => false]];
        yield 'flat without it' => [['knn' => 'false']];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('knnOff')]
    public function testNativeSettingsCannotTurnKnnOff(array $settings): void
    {
        $body = Mapping::create(IndexSchema::for('products')->vector('embedding', 3)->native('opensearch', ['settings' => $settings]), 'opensearch');

        self::assertTrue($body['settings']['index']['knn']);
        self::assertArrayNotHasKey('index.knn', $body['settings']);
        self::assertArrayNotHasKey('knn', $body['settings']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function orgKeyOverrides(): iterable
    {
        yield 'retyped as text' => [['properties' => ['org_id' => ['type' => 'text']]]];
        yield 'normalized' => [['properties' => ['org_id' => ['type' => 'keyword', 'normalizer' => 'lowercase']]]];
        yield 'shadowed by a derived field' => [['derived' => ['org_id' => ['type' => 'keyword', 'script' => "emit('acme')"]]]];
        yield 'copied into from another field' => [['properties' => ['title' => ['type' => 'text', 'copy_to' => 'org_id']]]];
        yield 'copied into from a nested field' => [['properties' => ['meta' => ['properties' => ['owner' => ['type' => 'keyword', 'copy_to' => ['x', 'org_id']]]]]]];
    }

    /**
     * @param array<string, mixed> $mappings
     */
    #[DataProvider('orgKeyOverrides')]
    public function testNativeMappingsCannotTouchTheOrgKey(array $mappings): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('org_id');

        Mapping::create(IndexSchema::for('products')->text('title')->orgKey('org_id')->native('opensearch', ['mappings' => $mappings]), 'opensearch');
    }

    public function testNativeMappingsMayStillNameTheOrgKeyElsewhere(): void
    {
        $body = Mapping::create(IndexSchema::for('products')->text('title')->orgKey('org_id')->native('opensearch', ['mappings' => ['_source' => ['excludes' => ['org_id']]]]), 'opensearch');

        self::assertSame(['org_id'], $body['mappings']['_source']['excludes']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function pipelines(): iterable
    {
        yield 'a default ingest pipeline, dotted' => [['index.default_pipeline' => 'p']];
        yield 'a final ingest pipeline, nested' => [['index' => ['final_pipeline' => 'p']]];
        yield 'a default search pipeline, without the prefix' => [['search' => ['default_pipeline' => 'p']]];
        yield 'a default search pipeline, half dotted' => [['index' => ['search.default_pipeline' => 'p']]];
    }

    /**
     * An ingest pipeline rewrites what Orion stamped on a document, a search pipeline what the scope left of a query.
     *
     * @param array<string, mixed> $settings
     */
    #[DataProvider('pipelines')]
    public function testNativeSettingsCannotNameAPipeline(array $settings): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pipeline');

        Mapping::create(IndexSchema::for('products')->keyword('sku')->native('opensearch', ['settings' => $settings]), 'opensearch');
    }

    public function testNativeSettingsAreSettingsAndMappingsOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mapping::create(IndexSchema::for('products')->keyword('sku')->native('opensearch', ['aliases' => ['all' => new stdClass()]]), 'opensearch');
    }

    public function testNativeTypesWithoutANeutralEquivalentAreUnknown(): void
    {
        $fields = Mapping::fields(['properties' => [
            'ratio' => ['type' => 'float'],
            'title' => ['type' => 'text', 'analyzer' => 'my_custom'],
            'tags' => ['type' => 'percolator'],
        ]]);

        self::assertSame([FieldType::Unknown, FieldType::Unknown, FieldType::Unknown], [$fields[0]->type, $fields[1]->type, $fields[2]->type]);
    }

    public function testWithoutMetaDocValuesMeanSortableAndFacetable(): void
    {
        $field = Mapping::fields(['properties' => ['sku' => ['type' => 'keyword']]])[0];

        self::assertTrue($field->filterable);
        self::assertTrue($field->sortable);
        self::assertTrue($field->facetable);
    }

    public function testVectorFieldsAreLeftOutOfTheSource(): void
    {
        $schema = IndexSchema::for('products')->text('title')->vector('embedding', 3)->with(Field::object('variants', Field::vector('vec', 2)));

        self::assertSame(['embedding', 'variants.vec'], Mapping::create($schema, 'opensearch')['mappings']['_source']['excludes']);
        self::assertArrayNotHasKey('_source', Mapping::create(IndexSchema::for('products')->text('title'), 'opensearch')['mappings']);
    }
}
