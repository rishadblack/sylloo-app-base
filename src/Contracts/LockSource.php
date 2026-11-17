<?php

namespace Rishadblack\SyllooAppBase\Contracts;

use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Pin\PresenceLockSource;

/**
 * Where the idle lock is kept, and after how many idle minutes it closes (plan §4.2 "PIN and lock"). The base keeps it
 * in its presence session ({@see PresenceLockSource}); an app with its own lock binds
 * its own source, so the base's screens, middleware and Presence use the same lock as the app's pages:
 *
 *     $this->app->bind(LockSource::class, fn () => new SessionKeyLockSource('till_locked_at', fn () => IdleLock::minutes()));
 */
interface LockSource
{
    public function isLocked(): bool;

    /**
     * Close the lock. Returns false when there is nobody to lock (nobody is in).
     */
    public function lock(): bool;

    public function unlock(): void;

    /**
     * Idle minutes before the lock closes by itself; 0 never.
     */
    public function idleMinutes(DeviceState $device): int;
}
