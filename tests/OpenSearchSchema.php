<?php

declare(strict_types=1);

namespace Orion\OpenSearch\Tests;

use Orion\Adapter\RequiresReindex;
use Orion\IndexName;
use Orion\OpenSearch\OpenSearchEngine;
use Orion\Schema\IndexSchema;
use Orion\Schema\VectorMetric;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function random_bytes;

/**
 * What the k-NN plugin needs of the lifecycle, checked live: `index.knn` is a static setting, so a vector can join an
 * index only if the index was created with one, and every neutral metric is a space type the Lucene engine takes.
 */
abstract class OpenSearchSchema extends TestCase
{
    private OpenSearchEngine $engine;
    private IndexName $index;

    abstract protected static function urlVariable(): string;

    protected function setUp(): void
    {
        $this->engine = OpenSearchServer::engine(static::urlVariable(), $this);
        $this->index = IndexName::of('orion_schema_' . bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        if (isset($this->engine)) {
            $this->engine->drop($this->index);
        }
    }

    public function testAVectorCannotJoinAnIndexCreatedWithoutOne(): void
    {
        $this->engine->ensure(IndexSchema::for($this->index->value)->keyword('sku'));
        $plan = $this->engine->plan(IndexSchema::for($this->index->value)->keyword('sku')->vector('embedding', 3));
        self::assertFalse($plan->isEmpty());

        $this->expectException(RequiresReindex::class);
        $this->expectExceptionMessage('index.knn');

        $this->engine->apply($plan);
    }

    public function testAVectorJoinsAnIndexCreatedWithOneInPlace(): void
    {
        $this->engine->ensure(IndexSchema::for($this->index->value)->vector('embedding', 3));
        $desired = IndexSchema::for($this->index->value)->vector('embedding', 3)->vector('other', 2, VectorMetric::Euclidean);

        $this->engine->apply($this->engine->plan($desired));

        self::assertTrue($this->engine->plan($desired)->isEmpty());
    }

    public function testEveryMetricIsASpaceTypeTheEngineTakes(): void
    {
        $schema = IndexSchema::for($this->index->value)
            ->vector('cosine', 2, VectorMetric::Cosine)
            ->vector('dot', 2, VectorMetric::DotProduct)
            ->vector('euclidean', 2, VectorMetric::Euclidean);

        $this->engine->ensure($schema);

        self::assertTrue($this->engine->plan($schema)->isEmpty());
    }
}
