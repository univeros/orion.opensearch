<?php

declare(strict_types=1);

namespace Orion\OpenSearch\Tests;

use Orion\OpenSearch\BulkResponse;
use Orion\Write\WriteStatus;
use PHPUnit\Framework\TestCase;

use function array_map;

final class BulkResponseTest extends TestCase
{
    public function testStatusesMapToOutcomesKeyedByTheRequestedIds(): void
    {
        $outcomes = BulkResponse::outcomes(['items' => [
            ['index' => ['_id' => 'a', 'status' => 201]],
            ['index' => ['_id' => 'b', 'status' => 409, 'error' => ['type' => 'version_conflict_engine_exception', 'reason' => 'x']]],
            ['index' => ['_id' => 'c', 'status' => 400, 'error' => ['type' => 'strict_dynamic_mapping_exception', 'reason' => 'y']]],
            ['delete' => ['_id' => 'd', 'status' => 404, 'result' => 'not_found']],
            ['index' => ['_id' => 'e', 'status' => 404, 'error' => ['type' => 'index_not_found_exception', 'reason' => 'z']]],
            ['index' => ['_id' => 'f', 'status' => 429]],
            ['index' => ['_id' => 'spoofed', 'status' => 200]],
        ]], ['a', 'b', 'c', 'd', 'e', 'f', 'g'], ['index', 'index', 'index', 'delete', 'index', 'index', 'index']);

        self::assertSame(['a', 'b', 'c', 'd', 'e', 'f', 'g'], array_map(static fn($o): string => $o->id, $outcomes));
        self::assertSame(
            [WriteStatus::Applied, WriteStatus::Stale, WriteStatus::Failed, WriteStatus::Applied, WriteStatus::Failed, WriteStatus::Failed, WriteStatus::Applied],
            array_map(static fn($o): WriteStatus => $o->status, $outcomes),
        );
        self::assertSame('strict_dynamic_mapping_exception: y', $outcomes[2]->error);
    }

    public function testAResponseThatDoesNotAnswerEveryItemFailsThemAll(): void
    {
        $outcomes = BulkResponse::outcomes(['items' => [['index' => ['_id' => 'a', 'status' => 201]]]], ['a', 'b'], ['index', 'index']);

        self::assertSame([WriteStatus::Failed, WriteStatus::Failed], array_map(static fn($o): WriteStatus => $o->status, $outcomes));
    }

    public function testAnItemWithoutItsOperationFails(): void
    {
        $outcomes = BulkResponse::outcomes(['items' => [['update' => ['status' => 200]]]], ['a'], ['index']);

        self::assertSame(WriteStatus::Failed, $outcomes[0]->status);
    }

    public function testErrorReasonsAreTruncated(): void
    {
        $outcomes = BulkResponse::outcomes(['items' => [['index' => ['status' => 400, 'error' => ['type' => 't', 'reason' => str_repeat('v', 5000)]]]]], ['a'], ['index']);

        self::assertLessThanOrEqual(BulkResponse::MAX_REASON + 10, strlen((string) $outcomes[0]->error));
    }
}
