<?php

declare(strict_types=1);

namespace Orion\OpenSearch\Tests;

/**
 * OpenSearch 3.x; set ORION_OPENSEARCH_URL.
 */
final class OpenSearchSchemaTest extends OpenSearchSchema
{
    protected static function urlVariable(): string
    {
        return 'ORION_OPENSEARCH_URL';
    }
}
