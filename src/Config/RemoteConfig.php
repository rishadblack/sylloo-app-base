<?php

namespace Rishadblack\SyllooAppBase\Config;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Rishadblack\SyllooAppBase\Support\AppInfo;
use Rishadblack\SyllooAppBase\Update\AppUpdateService;
use Throwable;

/**
 * The signed remote config: a JSON file in the app's public releases repo (config('erp.remote_config.url'); the till's
 * pos-config.json). An app keeps it under its own {@see self::PATH} (a subclass may name another). With it
 * every till can be moved to a new ERP or socket address, told the latest and the oldest supported version, and
 * shown a short notice, without a new release.
 *
 * The file is `{"payload": {...}, "signature": "<base64 Ed25519>"}`; the signature covers the payload as
 * {@see self::signedBytes()} encodes it, made with the release key (php artisan release:sign-config). The till takes
 * a file only when the signature matches the public key built into the app, every address in it is https on an
 * allowed domain and its `serial` is higher than that of the file it holds (an old signed file can not be replayed).
 * The last good file is kept and applied on every request; offline or with a bad file the till keeps it, and without
 * one the till uses the addresses it was built with.
 *
 * Payload (every section but `schema` and `serial` optional):
 *
 *     schema: 1, serial: 7, issued_at: "2026-11-10T10:00:00Z",
 *     erp: {base_url: "https://sylloo.dev", image_hosts: ["sylloo.com", "sylloo.dev"]},
 *     reverb: {host: "ws.sylloo.dev", port: 443, scheme: "https", key: "..."},
 *     release: {latest_version: "2.6.0", minimum_version: "2.5.0", notes: "..."},
 *     notice: {message: "...", level: "info"|"warning"|"danger"}
 */
class RemoteConfig
{
    public const SCHEMA = 1;

    public const DISK = 'local';

    public const PATH = 'remote-config/remote-config.json';

    public const NOTICE_LEVELS = ['info', 'warning', 'danger'];

    /**
     * The verified payload of the kept file, read once per request (false: not read yet).
     *
     * @var array<string, mixed>|null|false
     */
    protected array|null|false $payload = false;

    /**
     * Why the last file was refused (for release:sign-config).
     */
    public ?string $lastRefusal = null;

    public static function isEnabled(): bool
    {
        return (bool) config('erp.remote_config.enabled') && (string) config('erp.remote_config.public_key') !== '';
    }

    /**
     * The exact bytes the signature covers.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function signedBytes(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function sign(array $payload, string $secretKey): string
    {
        return base64_encode(sodium_crypto_sign_detached(self::signedBytes($payload), $secretKey));
    }

    /**
     * Download the remote file and take it when it is valid and newer than the kept one. True when it was taken.
     */
    public function refresh(): bool
    {
        if (! self::isEnabled()) {
            return false;
        }

        try {
            $response = Http::acceptJson()->timeout(10)->connectTimeout(5)->get((string) config('erp.remote_config.url'));
        } catch (Throwable $exception) {
            Log::info('Remote config: could not download it: '.$exception->getMessage());

            return false;
        }

        if (! $response->successful()) {
            Log::info('Remote config: download failed', ['status' => $response->status()]);

            return false;
        }

        $payload = $this->verify($response->json());

        if ($payload === null) {
            return false;
        }

        $kept = $this->payload();

        if ($kept !== null && $payload['serial'] <= $kept['serial']) {
            if ($payload['serial'] < $kept['serial']) {
                Log::warning('Remote config: refused an older file', ['serial' => $payload['serial'], 'kept_serial' => $kept['serial']]);
            }

            return false;
        }

        Storage::disk(self::DISK)->put(static::PATH, $response->body());
        $this->payload = $payload;
        $this->apply();

        Log::info('Remote config: took serial '.$payload['serial']);

        if ($this->newerVersionAvailable()) {
            app(AppUpdateService::class)->checkForUpdates();
        }

        return true;
    }

    /**
     * Put the kept file's addresses into this request's config. Called for every request (AppServiceProvider), so
     * the ERP calls, image downloads and the socket of the page all use them.
     */
    public function apply(): void
    {
        $payload = $this->payload();

        if ($payload === null) {
            return;
        }

        if (isset($payload['erp']['base_url'])) {
            config(['erp.base_url' => rtrim($payload['erp']['base_url'], '/')]);
        }

        if (isset($payload['erp']['image_hosts'])) {
            config(['erp.image_hosts' => $payload['erp']['image_hosts']]);
        }

        if (isset($payload['reverb'])) {
            config([
                'broadcasting.connections.reverb.key' => $payload['reverb']['key'],
                'broadcasting.connections.reverb.options.host' => $payload['reverb']['host'],
                'broadcasting.connections.reverb.options.port' => $payload['reverb']['port'],
                'broadcasting.connections.reverb.options.scheme' => $payload['reverb']['scheme'],
                'broadcasting.connections.reverb.options.useTLS' => $payload['reverb']['scheme'] === 'https',
            ]);
        }
    }

    /**
     * The kept, verified payload, or null when there is none (or the remote config is off).
     *
     * @return array<string, mixed>|null
     */
    public function payload(): ?array
    {
        if ($this->payload !== false) {
            return $this->payload;
        }

        $this->payload = null;

        if (! self::isEnabled()) {
            return null;
        }

        try {
            $disk = Storage::disk(self::DISK);

            if ($disk->exists(static::PATH)) {
                $this->payload = $this->verify(json_decode((string) $disk->get(static::PATH), true));

                // A kept file this build can not trust (signed with another key, or damaged) is dropped once instead of
                // being refused again on every request; the next download replaces it.
                if ($this->payload === null) {
                    $disk->delete(static::PATH);
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Remote config: could not read the kept file: '.$exception->getMessage());
        }

        return $this->payload;
    }

    /**
     * Remove the kept file (LocalDataReset): the till goes back to the addresses it was built with until the next
     * download.
     */
    public function forget(): void
    {
        Storage::disk(self::DISK)->delete(static::PATH);
        $this->payload = false;
    }

    /**
     * The socket address the page connects to, when the remote config sets one.
     *
     * @return array{key: string, host: string, port: int, scheme: string}|null
     */
    public function reverb(): ?array
    {
        return $this->payload()['reverb'] ?? null;
    }

    /**
     * @return array{message: string, level: string}|null
     */
    public function notice(): ?array
    {
        $notice = $this->payload()['notice'] ?? null;

        return $notice && $notice['message'] !== '' ? $notice : null;
    }

    public function latestVersion(): ?string
    {
        return $this->payload()['release']['latest_version'] ?? null;
    }

    public function newerVersionAvailable(): bool
    {
        $latest = $this->latestVersion();

        return $latest !== null && version_compare($latest, AppInfo::version(), '>');
    }

    /**
     * The oldest version still supported, when this one is older.
     */
    public function unsupportedVersionMinimum(): ?string
    {
        $minimum = $this->payload()['release']['minimum_version'] ?? null;

        return $minimum !== null && version_compare(AppInfo::version(), $minimum, '<') ? $minimum : null;
    }

    /**
     * The payload of a downloaded or kept file when its signature and contents are valid, otherwise null (logged).
     *
     * @return array<string, mixed>|null
     */
    public function verify(mixed $document): ?array
    {
        if (! is_array($document) || ! is_array($document['payload'] ?? null) || ! is_string($document['signature'] ?? null)) {
            return $this->refuse('it is not a signed config file');
        }

        $payload = $document['payload'];
        $signature = base64_decode($document['signature'], true);
        $publicKey = base64_decode((string) config('erp.remote_config.public_key'), true);

        try {
            $signed = $signature !== false && $publicKey !== false
                && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES && strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                && sodium_crypto_sign_verify_detached($signature, self::signedBytes($payload), $publicKey);
        } catch (Throwable) {
            $signed = false;
        }

        if (! $signed) {
            return $this->refuse('the signature does not match');
        }

        $problem = $this->contentProblem($payload);

        return $problem === null ? $payload : $this->refuse($problem);
    }

    /**
     * What is wrong with a signed payload, or null when the till can take it.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function contentProblem(array $payload): ?string
    {
        if (($payload['schema'] ?? null) !== self::SCHEMA) {
            return 'unknown schema';
        }

        if (! is_int($payload['serial'] ?? null) || $payload['serial'] < 1) {
            return 'serial must be a positive whole number';
        }

        if (isset($payload['erp'])) {
            $baseUrl = $payload['erp']['base_url'] ?? null;

            if ($baseUrl !== null && ! $this->isAllowedHttpsUrl($baseUrl)) {
                return 'erp.base_url must be an https address on an allowed domain, without a path';
            }

            $imageHosts = $payload['erp']['image_hosts'] ?? null;

            if ($imageHosts !== null && (! is_array($imageHosts) || ! array_is_list($imageHosts) || collect($imageHosts)->contains(fn ($host) => ! is_string($host) || ! $this->isAllowedHost($host)))) {
                return 'erp.image_hosts must list allowed domains';
            }
        }

        if (isset($payload['reverb'])) {
            $reverb = $payload['reverb'];

            if (! is_string($reverb['host'] ?? null) || ! $this->isAllowedHost($reverb['host'])
                || ! is_int($reverb['port'] ?? null) || $reverb['port'] < 1 || $reverb['port'] > 65535
                || ($reverb['scheme'] ?? null) !== 'https'
                || ! is_string($reverb['key'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $reverb['key'])) {
                return 'reverb needs an allowed host, a port, scheme https and a key';
            }
        }

        if (isset($payload['release'])) {
            foreach (['latest_version', 'minimum_version'] as $field) {
                $version = $payload['release'][$field] ?? null;

                if ($version !== null && (! is_string($version) || ! preg_match('/^\d+\.\d+\.\d+$/', $version))) {
                    return "release.{$field} must look like 2.6.0";
                }
            }

            $notes = $payload['release']['notes'] ?? null;

            if ($notes !== null && (! is_string($notes) || mb_strlen($notes) > 500)) {
                return 'release.notes must be text of at most 500 characters';
            }
        }

        if (isset($payload['notice'])) {
            $notice = $payload['notice'];

            if (! is_string($notice['message'] ?? null) || mb_strlen($notice['message']) > 300
                || ! in_array($notice['level'] ?? null, self::NOTICE_LEVELS, true)) {
                return 'notice needs a message of at most 300 characters and a level (info, warning or danger)';
            }
        }

        return null;
    }

    protected function isAllowedHttpsUrl(mixed $url): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === 'https'
            && array_intersect_key($parts, array_flip(['user', 'pass', 'query', 'fragment'])) === []
            && in_array($parts['path'] ?? '', ['', '/'], true)
            && $this->isAllowedHost((string) ($parts['host'] ?? ''));
    }

    /**
     * A domain from config('erp.remote_config.allowed_domains') or one of its subdomains.
     */
    protected function isAllowedHost(string $host): bool
    {
        $host = Str::lower(trim($host));

        if ($host === '' || ! preg_match('/^[a-z0-9.-]+$/', $host)) {
            return false;
        }

        foreach ((array) config('erp.remote_config.allowed_domains') as $domain) {
            $domain = Str::lower(trim((string) $domain, '. '));

            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                return true;
            }
        }

        return false;
    }

    protected function refuse(string $reason): null
    {
        $this->lastRefusal = $reason;
        Log::warning("Remote config refused: {$reason}");

        return null;
    }
}
