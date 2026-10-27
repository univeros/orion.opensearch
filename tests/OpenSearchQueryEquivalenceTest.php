<?php

declare(strict_types=1);

namespace Orion\OpenSearch\Tests;

/**
 * OpenSearch 3.x; set ORION_OPENSEARCH_URL, e.g. `http://127.0.0.1:9200`.
 */
final class OpenSearchQueryEquivalenceTest extends OpenSearchEquivalence
{
    protected static function urlVariable(): string
    {
        return 'ORION_OPENSEARCH_URL';
    }
}
