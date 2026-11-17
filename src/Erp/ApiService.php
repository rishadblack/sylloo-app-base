<?php

namespace Rishadblack\SyllooAppBase\Erp;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Support\AppInfo;
use Throwable;

/**
 * Calls to the ERP for one activation of this device (../sylloo-erp-docs/erp/device-api.md): the device key in
 * `X-Device-Key`, the app version in `X-Sylloo-App`, a member's sign-in token as a bearer token. Every call goes through
 * the shared {@see ErpCircuitBreaker}, background calls are tried again on a dropped connection or a server error, and a
 * 401 `device_released` marks the device as releasing.
 *
 * By default the calls go to the device API (`<erp.base_url>/device/api/`); an app may point an instance elsewhere
 * (the till's `pos/api/`).
 */
class ApiService
{
    protected string $baseUrl;

    protected ?string $activationKey;

    protected ?string $bearerToken;

    /**
     * Seconds to open a connection to the ERP, and to wait for its answer, on background calls. Three tries of
     * {@see self::TIMEOUT_SECONDS} with their backoff stay under the 60 s timeout of the queue worker (config/nativephp.php),
     * so a pull page is never killed half way.
     */
    public const CONNECT_TIMEOUT_SECONDS = 5;

    public const TIMEOUT_SECONDS = 15;

    /**
     * Tries of a background call, the first one included.
     */
    public const TRIES = 3;

    /**
     * Seconds a call the user waits on (sign-in, activation) is given: one try, then the user can carry on.
     */
    public const INTERACTIVE_TIMEOUT_SECONDS = 5;

    /**
     * A 429 asking to wait at most this long is waited for and tried again; a longer wait opens the breaker.
     */
    public const MAX_RETRY_AFTER_SECONDS = 5;

    /**
     * Whether the call skips the shared offline breaker ({@see ErpCircuitBreaker}): the heartbeat, sign-in and
     * activation always try, so they find out the ERP is back.
     */
    protected bool $probe = false;

    /**
     * Headers this instance adds to every call (e.g. the REST door's one-use key).
     *
     * @var array<string, string>
     */
    protected array $extraHeaders = [];

    /**
     * Requests carry the device key of the given activation (none without one) and the given sign-in token.
     */
    public function __construct(protected ?DeviceState $device = null, ?string $bearerToken = null)
    {
        $this->baseUrl = config('erp.base_url').'/device/api/';
        $this->activationKey = $device?->deviceKey();
        $this->bearerToken = $bearerToken;
    }

    /**
     * Calls from this instance go to the device API (`device/api/v1/…`, ../sylloo-erp-docs/erp/device-api.md §1).
     */
    public function onDeviceApi(): static
    {
        $this->baseUrl = config('erp.base_url').'/device/api/';

        return $this;
    }

    /**
     * Calls from this instance go to the given base address (e.g. a business host's `/app/api/`).
     */
    public function onBaseUrl(string $baseUrl): static
    {
        $this->baseUrl = $baseUrl;

        return $this;
    }

    /**
     * A copy whose calls carry these headers too.
     *
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): static
    {
        $copy = clone $this;
        $copy->extraHeaders = [...$this->extraHeaders, ...$headers];

        return $copy;
    }

    /**
     * Calls from this instance always try, even while background calls wait for the ERP to come back.
     */
    public function asProbe(): static
    {
        $this->probe = true;

        return $this;
    }

    /**
     * Prepare HTTP request with proper headers.
     *
     * @param  bool  $idempotent  a POST that only reads (a pull): retried like a GET, since sending it twice changes nothing
     */
    protected function prepareRequest(bool $idempotent = false): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::TIMEOUT_SECONDS)
            ->retry(
                self::TRIES,
                self::backoffMilliseconds(...),
                fn (Throwable $exception, mixed $request = null, ?string $method = null) => self::shouldRetry($exception, $request, $idempotent ? 'GET' : $method),
                throw: false,
            );

        return $this->withCredentials($request);
    }

    /**
     * Milliseconds before the next try: doubling from 200 ms, with up to 100 ms of jitter, or what a 429 asks for.
     */
    public static function backoffMilliseconds(int $attempt, Throwable $exception): int
    {
        if ($exception instanceof RequestException && $exception->response->status() === 429) {
            return self::retryAfterSeconds($exception->response) * 1000;
        }

        return 200 * 2 ** max(0, $attempt - 1) + random_int(0, 100);
    }

    /**
     * Seconds a 429 asks to wait (Retry-After in seconds), 1 when it does not say.
     */
    public static function retryAfterSeconds(Response $response): int
    {
        $retryAfter = $response->header('Retry-After');

        return is_numeric($retryAfter) ? max(1, (int) $retryAfter) : 1;
    }

    /**
     * Send one call: refused without dialling while the breaker is open (unless this is a probe), and every outcome
     * tells the breaker whether the ERP can be reached.
     *
     * @param  Closure(): Response  $send
     *
     * @throws ConnectionException
     */
    protected function send(Closure $send): Response
    {
        $this->assertSecureBaseUrl();

        if (! $this->probe && ErpCircuitBreaker::isOpen()) {
            throw new ConnectionException('The ERP is not reachable; calls wait until '.ErpCircuitBreaker::openUntil()?->toIso8601String());
        }

        try {
            $response = $send();
        } catch (ConnectionException $exception) {
            ErpCircuitBreaker::recordFailure();

            throw $exception;
        }

        // The ERP released this device (any call with a key that no longer works): it cleans itself (DeviceRelease).
        if ($response->status() === 401 && $this->activationKey && DeviceState::isReleasedReply($response)) {
            $this->deviceReleased();
        }

        if ($response->status() === 429) {
            ErpCircuitBreaker::openFor(self::retryAfterSeconds($response));
        } elseif (in_array($response->status(), [502, 503, 504], true)) {
            ErpCircuitBreaker::recordFailure();
        } else {
            ErpCircuitBreaker::recordSuccess();
        }

        return $response;
    }

    /**
     * The ERP answered 401 `device_released` to a call with this device's key: the device starts releasing.
     */
    protected function deviceReleased(): void
    {
        $this->device?->markReleasing();
    }

    /**
     * A production build only talks to the ERP over https: the device key and sign-in tokens never travel in the
     * clear, whatever the installed .env says.
     *
     * @throws ConnectionException
     */
    protected function assertSecureBaseUrl(): void
    {
        if (app()->isProduction() && Str::lower((string) parse_url((string) $this->baseUrl, PHP_URL_SCHEME)) !== 'https') {
            Log::error('ERP calls refused: the ERP address is not https', ['base_url' => $this->baseUrl]);

            throw new ConnectionException('The ERP address must use https.');
        }
    }

    /**
     * The device key in `X-Device-Key` (and in the older `activation-key` header, which the ERP still reads), the app
     * version in `X-Sylloo-App`, and the member's sign-in token as a bearer token.
     */
    protected function withCredentials(PendingRequest $request): PendingRequest
    {
        $request = $request->withHeaders([...$this->extraHeaders, 'X-Sylloo-App' => AppInfo::version()]);

        if ($this->activationKey) {
            $request = $request->withHeaders([
                'X-Device-Key' => $this->activationKey,
                'activation-key' => $this->activationKey,
            ]);
        }

        if ($this->bearerToken) {
            $request = $request->withToken($this->bearerToken);
        }

        return $request;
    }

    /**
     * Only a dropped connection or a server error (5xx) is worth another attempt: a refused
     * request (wrong password, validation, licence) would only be refused again. A POST that may
     * have reached the ERP (it timed out, the connection broke while waiting for the answer, or a
     * gateway answered 5xx while the ERP may still be working on it) is not sent again here, so a
     * sale is never sent twice at once; the push sends it later. A 429 was refused before any work
     * and may be tried again.
     */
    public static function shouldRetry(Throwable $exception, mixed $request = null, ?string $method = null): bool
    {
        $isPost = strtoupper((string) $method) === 'POST';

        if ($exception instanceof ConnectionException) {
            return ! $isPost || self::neverConnected($exception);
        }

        if ($exception instanceof RequestException && $exception->response->status() === 429) {
            return self::retryAfterSeconds($exception->response) <= self::MAX_RETRY_AFTER_SECONDS;
        }

        return ! $isPost && $exception instanceof RequestException && $exception->response->serverError();
    }

    /**
     * The request never reached the ERP: the host was not found, the connection was refused, or
     * opening it timed out (cURL errors 6 and 7, and 28 before the connection was open).
     */
    public static function neverConnected(ConnectionException $exception): bool
    {
        $message = $exception->getMessage();

        return (bool) preg_match('/cURL error (6|7):/', $message)
            || (str_contains($message, 'cURL error 28:') && preg_match('/(Connection|Resolving) timed out/i', $message));
    }

    /**
     * A GET tried once with a short timeout, for a question the user is waiting on.
     */
    public function getOnce(string $uri, int|float $timeoutSeconds): Response
    {
        return $this->send(fn () => $this->withCredentials(
            Http::baseUrl($this->baseUrl)->acceptJson()->timeout($timeoutSeconds)->connectTimeout($timeoutSeconds)
        )->get($uri));
    }

    /**
     * Generic GET request.
     *
     * @param  array<string, mixed>  $params
     */
    public function get(string $uri, array $params = []): Response
    {
        return $this->send(fn () => $this->prepareRequest()->get($uri, $params));
    }

    /**
     * Generic POST request.
     *
     * @param  array<string, mixed>  $data
     */
    public function post(string $uri, array $data = []): Response
    {
        return $this->send(fn () => $this->prepareRequest()->post($uri, $data));
    }

    /**
     * A POST that only reads (a pull page, get-update): retried on a timeout or a server error like a GET.
     *
     * @param  array<string, mixed>  $data
     */
    public function postIdempotent(string $uri, array $data = []): Response
    {
        return $this->send(fn () => $this->prepareRequest(idempotent: true)->post($uri, $data));
    }

    /**
     * A multipart POST with one file (the REST door's uploads). Like {@see self::postIdempotent()} it is tried again
     * on a timeout or a server error: an upload carries a one-use key, so a second send keeps the file once.
     *
     * @param  array<string, mixed>  $data
     */
    public function upload(string $uri, string $field, string $contents, string $filename, array $data = []): Response
    {
        return $this->send(fn () => $this->prepareRequest(idempotent: true)->attach($field, $contents, $filename)->post($uri, $data));
    }

    /**
     * A POST tried once with a short timeout, for a question the user is waiting on.
     *
     * @param  array<string, mixed>  $data
     */
    public function postOnce(string $uri, array $data, int|float $timeoutSeconds, int|float|null $connectTimeoutSeconds = null): Response
    {
        return $this->send(fn () => $this->withCredentials(
            Http::baseUrl($this->baseUrl)->acceptJson()->timeout($timeoutSeconds)->connectTimeout($connectTimeoutSeconds ?? $timeoutSeconds)
        )->post($uri, $data));
    }

    /**
     * Generic PUT request.
     *
     * @param  array<string, mixed>  $data
     */
    public function put(string $uri, array $data = []): Response
    {
        return $this->send(fn () => $this->prepareRequest()->put($uri, $data));
    }

    /**
     * Generic DELETE request.
     *
     * @param  array<string, mixed>  $data
     */
    public function delete(string $uri, array $data = []): Response
    {
        return $this->send(fn () => $this->prepareRequest()->delete($uri, $data));
    }
}
