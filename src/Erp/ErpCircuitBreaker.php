<?php

namespace Rishadblack\SyllooAppBase\Erp;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * One shared "the ERP is offline until T" for every background call (push, pull, images, serial checks), so a till
 * without internet stops dialling on every trigger. After {@see self::FAILURES_TO_OPEN} failed connections in a row
 * the breaker opens for 30 seconds, doubling up to 10 minutes while the ERP stays away. The heartbeat, the sign-in and
 * the activation always try (they are the probes), and any answer from the ERP closes it again.
 */
class ErpCircuitBreaker
{
    public const FAILURES_CACHE_KEY = 'pos.erp_breaker.failures';

    public const OPEN_UNTIL_CACHE_KEY = 'pos.erp_breaker.open_until';

    public const FAILURES_TO_OPEN = 3;

    public const FIRST_COOL_DOWN_SECONDS = 30;

    public const MAX_COOL_DOWN_SECONDS = 600;

    /**
     * Until when background calls are not made, or null while the ERP counts as reachable.
     */
    public static function openUntil(): ?Carbon
    {
        $until = Cache::get(self::OPEN_UNTIL_CACHE_KEY);

        return $until ? Carbon::parse($until) : null;
    }

    public static function isOpen(): bool
    {
        return self::openUntil()?->isFuture() === true;
    }

    /**
     * A connection to the ERP failed (or it answered that it is overloaded). The cool-down grows with each failure.
     */
    public static function recordFailure(): void
    {
        $failures = (int) Cache::get(self::FAILURES_CACHE_KEY, 0) + 1;
        Cache::forever(self::FAILURES_CACHE_KEY, $failures);

        if ($failures < self::FAILURES_TO_OPEN) {
            return;
        }

        $coolDown = min(self::MAX_COOL_DOWN_SECONDS, self::FIRST_COOL_DOWN_SECONDS * 2 ** ($failures - self::FAILURES_TO_OPEN));

        Cache::forever(self::OPEN_UNTIL_CACHE_KEY, now()->addSeconds($coolDown)->toIso8601String());
    }

    /**
     * The ERP asked to wait (429 with Retry-After): no background calls until then, at most the longest cool-down.
     */
    public static function openFor(int $seconds): void
    {
        $seconds = max(1, min($seconds, self::MAX_COOL_DOWN_SECONDS));

        if (self::openUntil()?->gt(now()->addSeconds($seconds))) {
            return;
        }

        Cache::forever(self::OPEN_UNTIL_CACHE_KEY, now()->addSeconds($seconds)->toIso8601String());
    }

    /**
     * The ERP answered: background calls run again.
     */
    public static function recordSuccess(): void
    {
        Cache::forget(self::FAILURES_CACHE_KEY);
        Cache::forget(self::OPEN_UNTIL_CACHE_KEY);
    }
}
