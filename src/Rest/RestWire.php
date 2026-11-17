<?php

namespace Rishadblack\SyllooAppBase\Rest;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Rishadblack\SyllooAppBase\Erp\ApiService;
use Rishadblack\SyllooAppBase\Erp\OfficeOutcome;
use Rishadblack\SyllooAppBase\Erp\RefusalCode;

/**
 * The wire format of the office's REST door (../sylloo-erp-docs/erp/rest-door.md), in this one class. On the business's
 * host, prefix `rest/v1`:
 *
 * - run an action: `POST rest/v1/actions/<area>/<name>`, the fields as JSON;
 * - read a list: `GET rest/v1/lists/<name>?since=…&page=…&per_page=…&filter[<name>]=…`;
 * - read a gathered answer: `GET rest/v1/reads/<name>` (`home`, `roster`: worked out on each call, no `since`, no pages);
 * - keep a file: `POST rest/v1/uploads`, multipart with one `file`, answering `{data: {file_id}}`.
 *
 * Every call carries `X-Device-Key`, `Authorization: Bearer <token>` and `X-Sylloo-App` ({@see ApiService}); every
 * write (actions, uploads) also `Idempotency-Key` (8–64 of `A-Z a-z 0-9 _ - : .`), only as a header. A second send
 * with the same key answers the first answer again with `X-Rest-Replayed: true`.
 *
 * One answer shape: done `{data, …, server_time, anchor}` (a list: `{data, removed, page, per_page, has_more,
 * server_time, anchor}`; `anchor` = `{at, sig}`, the office's signed time the app keeps for offline time proofs);
 * a refusal `{message, code}` (422 `validation_failed` with `errors`, 422 `refused`, 403 `no_permission` /
 * `feature_off`, 404 `not_found`, 422/409 `key_required` / `key_reused` / `call_in_progress`, 429
 * `too_many_requests`, and the gate's 401/403/426 codes). The app reads `code`, never `message`.
 */
final class RestWire
{
    public const PREFIX = 'rest/v1';

    public const KEY_HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'X-Rest-Replayed';

    /** The multipart field of an upload. */
    public const UPLOAD_FIELD = 'file';

    /**
     * `rest/v1/actions/<area>/<name>` for `<area>/<name>` (lowercase letters, digits, `-` and `_`).
     */
    public static function actionPath(string $action): string
    {
        if (! self::isActionName($action)) {
            throw new InvalidArgumentException("Not an action name: {$action} (use <area>/<name>).");
        }

        return self::PREFIX.'/actions/'.$action;
    }

    public static function isActionName(string $action): bool
    {
        return preg_match('#^[a-z0-9_-]+/[a-z0-9_-]+$#', $action) === 1;
    }

    public static function listPath(string $name): string
    {
        if (preg_match('#^[a-z0-9_-]+$#', $name) !== 1) {
            throw new InvalidArgumentException("Not a list name: {$name}.");
        }

        return self::PREFIX.'/lists/'.$name;
    }

    /**
     * `rest/v1/reads/<name>` (a gathered answer such as `home` or `roster`).
     */
    public static function readPath(string $name): string
    {
        if (preg_match('#^[a-z0-9_-]+$#', $name) !== 1) {
            throw new InvalidArgumentException("Not a read name: {$name}.");
        }

        return self::PREFIX.'/reads/'.$name;
    }

    public static function uploadPath(): string
    {
        return self::PREFIX.'/uploads';
    }

    /**
     * @param  array<string, scalar|null>  $filters
     * @return array<string, mixed>
     */
    public static function listQuery(?string $since, int $page, int $perPage, array $filters = []): array
    {
        return array_filter([
            'since' => $since,
            'page' => max(1, $page),
            'per_page' => min(200, max(1, $perPage)),
            'filter' => $filters !== [] ? $filters : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * A new one-use key (a UUID).
     */
    public static function newKey(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Whether the office takes this as a one-use key: 8–64 of `A-Z a-z 0-9 _ - : .`.
     */
    public static function isKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9_\-:.]{8,64}$/', $key) === 1;
    }

    /**
     * The key of a file of a waiting write: made from the write's key and the field, the same on every send, so a
     * second send keeps the file once.
     */
    public static function fileKey(string $key, string $field): string
    {
        return 'file:'.substr(hash('sha256', $key.'|'.$field), 0, 40);
    }

    /**
     * The headers of a write.
     *
     * @return array<string, string>
     */
    public static function writeHeaders(string $key): array
    {
        if (! self::isKey($key)) {
            throw new InvalidArgumentException('Not a one-use key: 8–64 of A-Z a-z 0-9 _ - : . are needed.');
        }

        return [self::KEY_HEADER => $key];
    }

    /**
     * Read any answer of the door into a result. A replayed answer (`X-Rest-Replayed: true`) is a call already done.
     */
    public static function read(Response $response): RestResult
    {
        $json = rescue(fn () => $response->json(), null, false);
        $answer = is_array($json) ? $json : [];
        $replayed = strtolower($response->header(self::REPLAYED_HEADER)) === 'true';
        $retryAfter = is_numeric($response->header('Retry-After')) ? max(1, (int) $response->header('Retry-After')) : null;

        if ($response->successful() || $replayed) {
            return new RestResult(OfficeOutcome::Ok, $answer, $response->status(), $replayed);
        }

        if (in_array($response->status(), [502, 503, 504], true)) {
            return new RestResult(OfficeOutcome::Unreachable, $answer, $response->status(), retryAfter: $retryAfter);
        }

        $code = match (true) {
            isset($answer['code']) => null,
            $response->status() === 422 => RefusalCode::ValidationFailed->value,
            $response->status() === 429 => RefusalCode::TooManyRequests->value,
            default => null,
        };

        if ($code !== null) {
            $answer['code'] = $code;
        }

        return new RestResult($response->clientError() ? OfficeOutcome::Refused : OfficeOutcome::Unexpected, $answer, $response->status(), retryAfter: $retryAfter);
    }

    /**
     * The saved row of an action answer (`data`; an action that keeps no record answers `null`, read as `[]`).
     *
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>
     */
    public static function data(array $answer): array
    {
        return is_array($answer['data'] ?? null) ? $answer['data'] : [];
    }

    /**
     * The rows of a list answer.
     *
     * @param  array<string, mixed>  $answer
     * @return list<array<string, mixed>>
     */
    public static function rows(array $answer): array
    {
        $rows = $answer['data'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * The ids removed since `since` (a list answer).
     *
     * @param  array<string, mixed>  $answer
     * @return list<string>
     */
    public static function removed(array $answer): array
    {
        $removed = $answer['removed'] ?? [];

        return is_array($removed) ? array_values(array_map('strval', array_filter($removed, 'is_scalar'))) : [];
    }

    /**
     * Whether a list has a next page.
     *
     * @param  array<string, mixed>  $answer
     */
    public static function hasMore(array $answer): bool
    {
        return ($answer['has_more'] ?? false) === true;
    }

    /**
     * The office's time of the answer: the "changed since" mark of the next read (keep the first page's).
     *
     * @param  array<string, mixed>  $answer
     */
    public static function serverTime(array $answer): ?string
    {
        $time = $answer['server_time'] ?? null;

        return is_string($time) && $time !== '' ? $time : null;
    }

    /**
     * The office's signed time next to `server_time` (`{at, sig}`), kept with the device's running timer so a write
     * made offline can prove its time; null when the answer has none.
     *
     * @param  array<string, mixed>  $answer
     * @return array{at: string, sig: string}|null
     */
    public static function anchor(array $answer): ?array
    {
        $anchor = $answer['anchor'] ?? null;

        if (! is_array($anchor) || ! is_string($anchor['at'] ?? null) || ! is_string($anchor['sig'] ?? null) || $anchor['at'] === '' || $anchor['sig'] === '') {
            return null;
        }

        return ['at' => $anchor['at'], 'sig' => $anchor['sig']];
    }

    /**
     * The id of a kept file (an upload answer).
     *
     * @param  array<string, mixed>  $answer
     */
    public static function fileId(array $answer): ?string
    {
        $id = is_array($answer['data'] ?? null) ? ($answer['data']['file_id'] ?? null) : null;

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    /**
     * The key field that names a row.
     *
     * @param  array<string, mixed>  $row
     */
    public static function rowId(array $row): ?string
    {
        return is_scalar($row['id'] ?? null) ? (string) $row['id'] : null;
    }
}
