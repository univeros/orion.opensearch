<?php

declare(strict_types=1);

namespace Orion\OpenSearch\Tests;

use InvalidArgumentException;
use Orion\Adapter\Capability;
use Orion\OpenSearch\OpenSearchEngine;
use PHPUnit\Framework\TestCase;

final class ConnectTest extends TestCase
{
    public function testAnEngineConnectsToTheUrlItIsGiven(): void
    {
        $engine = OpenSearchEngine::connect('http://127.0.0.1:59210');

        self::assertTrue($engine->supports(Capability::NativeAliases));
        self::assertFalse($engine->supports(Capability::HybridFusion));
    }

    public function testConnectingNeedsAUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OpenSearchEngine::connect('');
    }
}
