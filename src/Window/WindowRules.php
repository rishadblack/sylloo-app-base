<?php

namespace Rishadblack\SyllooAppBase\Window;

use Illuminate\Support\Facades\Cache;

/**
 * The rules of the business window (desktop app, mobile app; device-api.md §4, §6.5; plan §6.1 #5–#7, §7.6): which
 * session-ticket address may be opened, which addresses the window may show (everything else opens in the normal
 * browser), how often it keeps the session alive, and the limit on quiet new tickets. The window itself is the app's
 * (Electron, Android); the decisions are made here, once.
 */
final class WindowRules
{
    /** Paths the staff window may show when the office sent no rules. */
    public const STAFF_PATHS = ['/member', '/livewire', '/build', '/storage', '/docs', '/theme/locale', '/favicon.ico'];

    /** Paths the employee window may show when the office sent no rules. */
    public const EMPLOYEE_PATHS = ['/employee', '/livewire', '/build', '/storage', '/docs', '/theme/locale', '/favicon.ico'];

    public const QUIET_SIGN_INS_CACHE_KEY = 'sylloo-app-base.window.quiet_sign_ins.';

    /**
     * Whether a session-ticket answer's `url` may be opened: https (http outside production), on one of the
     * business's hosts, no port but the office's own ({@see self::isAllowedPort()}), at exactly the entrance path
     * (`/app/session` for staff, `/employee/app/session` for an employee; `/app/session/` is refused, as the desktop
     * app's Electron `isSessionTicketUrl` does), with no user or password in it.
     *
     * @param  list<string|null>  $hosts
     */
    public static function isTicketUrl(mixed $url, array $hosts, string $entrancePath = '/app/session'): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && self::isAllowedScheme($parts['scheme'] ?? '')
            && self::isAllowedPort($parts)
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && in_array(strtolower((string) ($parts['host'] ?? '')), self::lower($hosts), true)
            && (string) ($parts['path'] ?? '') === '/'.trim($entrancePath, '/');
    }

    /**
     * Whether the window may show an address: one of the business's hosts, no port but the office's own
     * ({@see self::isAllowedPort()}), no user or password, and a path under one of the allowed paths (the office's
     * `rules.allowed_paths`, else the door's defaults). Anything else opens in the normal browser.
     *
     * @param  list<string|null>  $hosts
     * @param  list<string>  $allowedPaths
     */
    public static function mayShow(mixed $url, array $hosts, array $allowedPaths): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! self::isAllowedScheme($parts['scheme'] ?? '') || ! self::isAllowedPort($parts)
            || isset($parts['user']) || isset($parts['pass'])
            || ! in_array(strtolower((string) ($parts['host'] ?? '')), self::lower($hosts), true)) {
            return false;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');

        if (str_contains($path, '/../') || str_ends_with($path, '/..')) {
            return false;
        }

        foreach ($allowedPaths as $allowed) {
            $allowed = '/'.trim($allowed, '/');

            if ($path === $allowed || str_starts_with($path, rtrim($allowed, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The allowed paths of a session-ticket answer, or the defaults of the door.
     *
     * @param  array<string, mixed>  $answer
     * @return list<string>
     */
    public static function allowedPaths(array $answer, bool $employee = false): array
    {
        $paths = $answer['rules']['allowed_paths'] ?? null;

        if (is_array($paths) && $paths !== []) {
            return array_values(array_filter($paths, fn ($path) => is_string($path) && str_starts_with($path, '/')));
        }

        return $employee ? self::EMPLOYEE_PATHS : self::STAFF_PATHS;
    }

    /**
     * Seconds between two keep-alive calls of the window.
     */
    public static function keepAliveSeconds(): int
    {
        return max(30, (int) config('sylloo-app-base.window.keep_alive_seconds', 240));
    }

    /**
     * Whether a quiet new ticket may be asked for now (at most `quiet_sign_ins_per_minute` a minute per entry); counts
     * the try when it may. Past the limit the window shows "Sign in with browser" instead of looping.
     */
    public static function mayQuietlySignIn(string $entryId): bool
    {
        $key = self::QUIET_SIGN_INS_CACHE_KEY.$entryId;
        $limit = max(1, (int) config('sylloo-app-base.window.quiet_sign_ins_per_minute', 3));
        $tries = array_values(array_filter((array) Cache::get($key, []), fn ($at) => is_int($at) && $at > now()->timestamp - 60));

        if (count($tries) >= $limit) {
            return false;
        }

        Cache::put($key, [...$tries, now()->timestamp], 120);

        return true;
    }

    /**
     * The port rule (base 1.1.0, decisions.md): an address with a port is refused, as the desktop app's Electron
     * `decide()` does, unless the office address of this app (`erp.base_url`) carries that same port (a local office
     * such as `https://sylloo.test:8443`). The scheme's own port (443 for https, 80 for http) counts as no port, as a
     * browser reads it.
     *
     * @param  array<string, mixed>  $parts  parse_url() of the address.
     */
    public static function isAllowedPort(array $parts): bool
    {
        $port = self::explicitPort($parts);

        if ($port === null) {
            return true;
        }

        $office = parse_url((string) config('erp.base_url', ''));

        return is_array($office) && self::explicitPort($office) === $port;
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    protected static function explicitPort(array $parts): ?int
    {
        if (! isset($parts['port'])) {
            return null;
        }

        $port = (int) $parts['port'];
        $default = ['https' => 443, 'http' => 80][strtolower((string) ($parts['scheme'] ?? ''))] ?? null;

        return $port === $default ? null : $port;
    }

    protected static function isAllowedScheme(string $scheme): bool
    {
        $scheme = strtolower($scheme);

        return $scheme === 'https' || ($scheme === 'http' && ! app()->isProduction());
    }

    /**
     * @param  list<string|null>  $hosts
     * @return list<string>
     */
    protected static function lower(array $hosts): array
    {
        return array_values(array_map('strtolower', array_filter($hosts, fn ($host) => is_string($host) && $host !== '')));
    }
}
