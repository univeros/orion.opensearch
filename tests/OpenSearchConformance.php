<?php

declare(strict_types=1);

namespace Orion\OpenSearch\Tests;

use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Testing\EngineConformanceTestCase;

/**
 * Runs the case against the OpenSearch node at the URL in {@see urlVariable()}; skipped when it is unset.
 */
abstract class OpenSearchConformance extends EngineConformanceTestCase
{
    abstract protected static function urlVariable(): string;

    protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        return OpenSearchServer::engine(static::urlVariable(), $this);
    }

    protected function nativeMatchAll(): callable
    {
        return OpenSearchServer::matchAll();
    }

    protected function compiledAsNative(array $compiled): ?callable
    {
        return static fn(): mixed => $compiled['body'] ?? null;
    }

    protected function nativeShapes(): array
    {
        return OpenSearchServer::shapes();
    }

    protected function nativeScopeEscapes(): array
    {
        return OpenSearchServer::escapes();
    }
}
