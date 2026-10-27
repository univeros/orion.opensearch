<?php

declare(strict_types=1);

namespace Orion\OpenSearch\Tests;

/**
 * OpenSearch 2.19 with the same client; set ORION_OPENSEARCH2_URL.
 */
final class OpenSearch2ConformanceTest extends OpenSearchConformance
{
    protected static function urlVariable(): string
    {
        return 'ORION_OPENSEARCH2_URL';
    }
}
