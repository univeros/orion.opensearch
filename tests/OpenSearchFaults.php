<?php

declare(strict_types=1);

namespace Orion\OpenSearch\Tests;

use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Testing\FaultInjectionTestCase;

/**
 * Runs the case against the OpenSearch node at the URL in {@see urlVariable()}; skipped when it is unset.
 */
abstract class OpenSearchFaults extends FaultInjectionTestCase
{
    abstract protected static function urlVariable(): string;

    protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        return OpenSearchServer::engine(static::urlVariable(), $this);
    }
}
