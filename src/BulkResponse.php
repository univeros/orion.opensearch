<?php

declare(strict_types=1);

namespace Orion\OpenSearch;

use Orion\Write\WriteOutcome;

use function array_map;
use function count;
use function is_array;
use function is_scalar;
use function strlen;
use function substr;

/**
 * A `_bulk` response mapped to one outcome per requested document, trusting nothing: outcomes are keyed on the ids
 * Orion sent, not on what came back; a response that does not answer every item fails them all; only a 2xx is
 * applied, a 409 is stale (a newer version is indexed), a 404 is applied only for a delete of a missing document,
 * and anything else fails with the engine's reason, truncated.
 */
final class BulkResponse
{
    public const int MAX_REASON = 300;
    private const int CONFLICT = 409;
    private const int NOT_FOUND = 404;

    /**
     * @param array<string, mixed> $response
     * @param list<string> $ids the requested document ids, in request order
     * @param list<'index'|'delete'> $operations the requested operations, in request order
     * @return list<WriteOutcome>
     */
    public static function outcomes(array $response, array $ids, array $operations): array
    {
        $items = is_array($response['items'] ?? null) ? $response['items'] : [];
        if (count($items) !== count($ids)) {
            return array_map(static fn(string $id): WriteOutcome => WriteOutcome::failed($id, 'The bulk response did not answer every item.'), $ids);
        }
        $outcomes = [];
        foreach ($ids as $i => $id) {
            $item = $items[$i] ?? null;
            $result = is_array($item) && is_array($item[$operations[$i]] ?? null) ? $item[$operations[$i]] : null;
            $outcomes[] = $result === null
                ? WriteOutcome::failed($id, 'The bulk response item does not match the request.')
                : self::outcome($id, $operations[$i], $result);
        }

        return $outcomes;
    }

    /**
     * @param array<array-key, mixed> $result
     */
    private static function outcome(string $id, string $operation, array $result): WriteOutcome
    {
        $status = is_scalar($result['status'] ?? null) ? (int) $result['status'] : 0;
        if ($status >= 200 && $status < 300) {
            return WriteOutcome::applied($id);
        }
        if ($status === self::CONFLICT) {
            return WriteOutcome::stale($id);
        }
        if ($status === self::NOT_FOUND && $operation === 'delete' && !isset($result['error'])) {
            return WriteOutcome::applied($id);
        }

        return WriteOutcome::failed($id, self::reason($result['error'] ?? null, $status));
    }

    private static function reason(mixed $error, int $status): string
    {
        if (is_array($error)) {
            $type = is_scalar($error['type'] ?? null) ? (string) $error['type'] : 'error';
            $reason = is_scalar($error['reason'] ?? null) ? (string) $error['reason'] : '';
            $text = $reason === '' ? $type : $type . ': ' . $reason;
        } else {
            $text = is_scalar($error) && (string) $error !== '' ? (string) $error : 'HTTP status ' . $status;
        }

        return strlen($text) > self::MAX_REASON ? substr($text, 0, self::MAX_REASON) . '…' : $text;
    }
}
