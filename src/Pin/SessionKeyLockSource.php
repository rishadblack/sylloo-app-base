<?php

namespace Rishadblack\SyllooAppBase\Pin;

use Closure;
use Rishadblack\SyllooAppBase\Contracts\LockSource;
use Rishadblack\SyllooAppBase\Device\DeviceState;

/**
 * A lock kept as one session key (the time it closed), with the idle minutes from the app's own setting: for an app
 * that had its lock before the base (the till: `till_locked_at`, setting `idle_lock_minutes`, 10 by default).
 *
 *     new SessionKeyLockSource('till_locked_at', fn () => IdleLock::minutes());
 */
class SessionKeyLockSource implements LockSource
{
    /**
     * @param  Closure(): int|null  $minutes  the idle minutes (else `$defaultMinutes`)
     */
    public function __construct(
        protected string $sessionKey,
        protected ?Closure $minutes = null,
        protected int $defaultMinutes = 10,
    ) {}

    public function isLocked(): bool
    {
        return session()->has($this->sessionKey);
    }

    public function lock(): bool
    {
        session()->put($this->sessionKey, now()->toIso8601String());

        return true;
    }

    public function unlock(): void
    {
        session()->forget($this->sessionKey);
    }

    public function idleMinutes(DeviceState $device): int
    {
        $minutes = $this->minutes !== null ? ($this->minutes)() : $this->defaultMinutes;

        return max(0, (int) $minutes);
    }
}
