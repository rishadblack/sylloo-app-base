<?php

namespace Rishadblack\SyllooAppBase\Testing;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

/**
 * A pretend office for an app's tests: it answers every device-door call (`/device/api/v1/…`) and every REST door call
 * of the collection (`rest/v1/reads/home`, `lists/attendance`, `actions/attendance/punch`, …) with the example answer
 * of the API collection ({@see OfficeExamples}), and other calls the test names. A test changes one answer, refuses
 * a call with a code, keeps the sign-in pending, or takes the office off the internet:
 *
 *     $office = PretendOffice::start();                       // an employee phone, as in the collection
 *     $office->asMember();                                    // the same answers for a staff member
 *     $office->refuse('activate', 'invalid_code', 422);
 *     $office->pending();                                     // sign-in/finish answers 409 sign_in_pending
 *     $office->down();                                        // nothing answers
 *     $office->rest('lists/customers', ['data' => [...]]);    // a REST door call (`rest/v1/lists/customers`)
 *     $office->read('roster', ['days' => [...]]);             // `rest/v1/reads/roster`: {data, server_time, anchor}
 *     $office->uploads();                                     // `rest/v1/uploads` keeps every file: file-1, file-2, …
 *     $office->assertCalled('sign-in/start', fn (Request $request) => strlen($request['challenge']) === 64);
 */
final class PretendOffice
{
    /** The device-door calls and the example answering them. */
    public const DEVICE_CALLS = [
        'activate' => 'Activate',
        'state' => 'State',
        'sign-in/start' => 'Sign-in Start',
        'sign-in/finish' => 'Sign-in Finish',
        'pin' => 'PIN',
        'me' => 'Me',
        'sign-out' => 'Sign Out',
        'deactivate' => 'Deactivate',
        'released' => 'Released',
        'push-token' => 'Push Token',
    ];

    /** @var array<string, array{status: int, body: array<string, mixed>|Closure(Request): array<string, mixed>}> */
    protected array $answers = [];

    /** @var array<string, array{status: int, body: array<string, mixed>|Closure(Request): array<string, mixed>, headers: array<string, string>}> */
    protected array $restAnswers = [];

    protected bool $down = false;

    protected bool $member = false;

    public static function start(): self
    {
        $office = new self;

        Http::fake(fn (Request $request) => $office->handle($request));

        return $office;
    }

    /**
     * Answer a device-door call (`activate`, `sign-in/finish`, …) with this body.
     *
     * @param  array<string, mixed>|Closure(Request): array<string, mixed>  $body
     */
    public function answer(string $call, array|Closure $body, int $status = 200): self
    {
        $this->answers[trim($call, '/')] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /**
     * Refuse a device-door call with a code (device-api.md: `{message, code}`).
     */
    public function refuse(string $call, string $code, int $status = 403, ?string $message = null): self
    {
        return $this->answer($call, ['message' => $message ?? "Refused: {$code}", 'code' => $code], $status);
    }

    /**
     * The person has not pressed Allow yet.
     */
    public function pending(): self
    {
        return $this->refuse('sign-in/finish', 'sign_in_pending', 409, 'Waiting for the browser.');
    }

    /**
     * Change the default answer of a call: merged over the collection's example.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(string $call, array $changes): self
    {
        return $this->answer($call, array_replace_recursive($this->example($call), $changes));
    }

    /**
     * Nothing answers (no internet), or everything answers again.
     */
    public function down(bool $down = true): self
    {
        $this->down = $down;

        return $this;
    }

    /**
     * Answer `sign-in/finish` and `me` for a staff member (`member`) instead of an employee.
     */
    public function asMember(bool $member = true): self
    {
        $this->member = $member;

        return $this;
    }

    /**
     * Answer a REST door call: the path after `rest/v1/` (`lists/customers`, `actions/hr/punch`, `uploads`), any
     * query left out. Headers such as `X-Rest-Replayed: true` may be added.
     *
     * @param  array<string, mixed>|Closure(Request): array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function rest(string $path, array|Closure $body, int $status = 200, array $headers = []): self
    {
        $call = (string) preg_replace('#^rest/v1/#', '', trim($path, '/'));
        $this->restAnswers[$call] = ['status' => $status, 'body' => $body, 'headers' => $headers];

        return $this;
    }

    /**
     * Answer a read (`rest/v1/reads/<name>`) with this data, in the door's shape `{data, server_time, anchor}`.
     *
     * @param  array<string, mixed>  $data
     */
    public function read(string $name, array $data): self
    {
        return $this->rest('reads/'.$name, fn () => ['data' => $data, ...self::stamp()]);
    }

    /**
     * What every answer of the REST door carries: `server_time` and the signed `anchor` (`{at, sig}`).
     *
     * @return array{server_time: string, anchor: array{at: string, sig: string}}
     */
    public static function stamp(): array
    {
        $now = now()->utc()->toIso8601ZuluString();

        return ['server_time' => $now, 'anchor' => ['at' => $now, 'sig' => hash('sha256', 'pretend-office|'.$now)]];
    }

    /**
     * `rest/v1/uploads` keeps every file and answers a new id (`file-1`, `file-2`, …), the same id again for a key it
     * already had, as the door does.
     */
    public function uploads(): self
    {
        $ids = [];

        return $this->rest('uploads', function (Request $request) use (&$ids) {
            $key = $request->header('Idempotency-Key')[0] ?? (string) count($ids);
            $ids[$key] ??= 'file-'.(count($ids) + 1);

            return ['data' => ['file_id' => $ids[$key]], ...self::stamp()];
        });
    }

    /**
     * The example answer of a device-door call, made usable (as {@see OfficeExamples::answer()}).
     *
     * @return array<string, mixed>
     */
    public function example(string $call): array
    {
        $answer = OfficeExamples::answer(self::DEVICE_CALLS[$call] ?? $call);

        if ($this->member && isset($answer['employee']) && is_array($answer['employee'])) {
            $answer['member'] = array_diff_key($answer['employee'], ['code' => 1]);
            unset($answer['employee']);
        }

        if ($this->member && is_array($answer['device'] ?? null) && ($answer['device']['kind'] ?? null) === 'employee') {
            $answer['device']['kind'] = 'app';
        }

        return $answer;
    }

    public function assertCalled(string $call, ?Closure $check = null): void
    {
        Assert::assertTrue(
            $this->recorded($call, $check) !== [],
            "The office was not called at {$call}".($check !== null ? ' as expected.' : '.'),
        );
    }

    public function assertNotCalled(string $call): void
    {
        Assert::assertSame([], $this->recorded($call), "The office was called at {$call}.");
    }

    /**
     * @return list<Request>
     */
    public function recorded(string $call, ?Closure $check = null): array
    {
        $call = trim($call, '/');

        return array_values(array_map(fn (array $pair) => $pair[0], Http::recorded(
            fn (Request $request) => $this->callOf($request) === $call && ($check === null || $check($request) === true),
        )->all()));
    }

    /**
     * @return PromiseInterface|Closure
     */
    public function handle(Request $request): mixed
    {
        if ($this->down) {
            return Http::failedConnection('The pretend office is down.');
        }

        $call = $this->callOf($request);
        $isDevice = str_contains((string) parse_url($request->url(), PHP_URL_PATH), '/device/api/v1/');

        if ($isDevice) {
            $answer = $this->answers[$call] ?? (isset(self::DEVICE_CALLS[$call]) ? ['status' => 200, 'body' => $this->example($call)] : null);
        } else {
            $example = str_contains((string) parse_url($request->url(), PHP_URL_PATH), '/rest/v1/') ? OfficeExamples::restAnswer($call, $request->method()) : null;
            $answer = $this->restAnswers[$call] ?? ($example !== null ? ['status' => 200, 'body' => $example] : null);
        }

        if ($answer === null) {
            return Http::response(['message' => 'Not found.', 'code' => 'not_found'], 404);
        }

        $body = $answer['body'] instanceof Closure ? ($answer['body'])($request) : $answer['body'];

        return Http::response($body, $answer['status'], $answer['headers'] ?? []);
    }

    /**
     * The call of a request: `sign-in/finish` for the device door; the path after `/rest/v1/` for the REST door
     * (`actions/hr/punch`, `lists/customers`, `uploads`); the path after `/api/v1/` for another door.
     */
    protected function callOf(Request $request): string
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (preg_match('#/device/api/v1/(.+)$#', $path, $match) === 1) {
            return trim($match[1], '/');
        }

        if (preg_match('#/rest/v1/(.+)$#', $path, $match) === 1) {
            return trim($match[1], '/');
        }

        if (preg_match('#/api/v\d+/(.+)$#', $path, $match) === 1) {
            return trim($match[1], '/');
        }

        return trim($path, '/');
    }
}
