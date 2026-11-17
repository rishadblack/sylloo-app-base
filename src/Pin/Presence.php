<?php

namespace Rishadblack\SyllooAppBase\Pin;

use Rishadblack\SyllooAppBase\Contracts\LockSource;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Events\DeviceLocked;

/**
 * Who works on the device now, and whether it is locked (plan §4.2 "PIN and lock": the idle lock). Kept in the
 * session of the app's own window, never in a page. A person is "in" after a PIN or a browser sign-in; after the idle
 * time (or "Lock now") the device is locked and only that person's PIN (or "Sign out and switch") opens it again.
 *
 * Where the lock is kept and its idle minutes are the bound {@see LockSource} (the base's own: {@see PresenceLockSource}).
 */
class Presence
{
    public const SESSION_KEY = 'sylloo-app-base.presence';

    /** Key of the device store with the idle minutes the person chose (PIN and lock settings). */
    public const IDLE_SETTING = 'idle_minutes';

    public function __construct(protected ?LockSource $locks = null) {}

    /**
     * The lock source in use: the bound one, else the base's own.
     */
    public function locks(): LockSource
    {
        return $this->locks ?? (app()->bound(LockSource::class) ? app(LockSource::class) : new PresenceLockSource);
    }

    public function enter(string $entryId, string $memberKey, bool $offline = false): void
    {
        session()->put(self::SESSION_KEY, [
            'entry' => $entryId,
            'member' => $memberKey,
            'offline' => $offline,
            'locked' => false,
            'last_seen' => now()->timestamp,
        ]);
    }

    /**
     * @return array{entry: string, member: string, offline: bool, locked: bool, last_seen: int}|null
     */
    public function current(): ?array
    {
        $presence = session()->get(self::SESSION_KEY);

        return is_array($presence) && is_string($presence['entry'] ?? null) && is_string($presence['member'] ?? null)
            ? [
                'entry' => $presence['entry'],
                'member' => $presence['member'],
                'offline' => (bool) ($presence['offline'] ?? false),
                'locked' => $this->locks()->isLocked(),
                'last_seen' => (int) ($presence['last_seen'] ?? 0),
            ]
            : null;
    }

    /**
     * The member key of the person in, on this entry (any entry when null), or null.
     */
    public function memberKey(?string $entryId = null): ?string
    {
        $presence = $this->current();

        return $presence !== null && ($entryId === null || $presence['entry'] === $entryId) ? $presence['member'] : null;
    }

    public function isSignedIn(?string $entryId = null): bool
    {
        return $this->memberKey($entryId) !== null;
    }

    public function isLocked(): bool
    {
        return $this->locks()->isLocked();
    }

    /**
     * Lock now (the idle time passed, "Lock now", the app went to the background on the phone).
     */
    public function lock(): void
    {
        $presence = $this->current();

        if ($this->isLocked() || ! $this->locks()->lock() || $presence === null) {
            return;
        }

        event(new DeviceLocked($presence['entry'], $presence['member']));
    }

    public function unlock(): void
    {
        $this->locks()->unlock();
        $presence = session()->get(self::SESSION_KEY);

        if (is_array($presence)) {
            session()->put(self::SESSION_KEY, [...$presence, 'last_seen' => now()->timestamp]);
        }
    }

    /**
     * The person did something: the idle time starts again.
     */
    public function touch(): void
    {
        $presence = $this->current();

        if ($presence !== null && ! $this->isLocked()) {
            session()->put(self::SESSION_KEY, [...$presence, 'last_seen' => now()->timestamp]);
        }
    }

    /**
     * Whether the idle time passed since the person last did something.
     */
    public function isIdle(int $minutes): bool
    {
        $presence = $this->current();

        return $presence !== null && $minutes > 0 && now()->timestamp - $presence['last_seen'] >= $minutes * 60;
    }

    /**
     * Lock when the idle time passed. Returns whether the device is locked now.
     */
    public function lockWhenIdle(DeviceState $device): bool
    {
        if ($this->isIdle($this->locks()->idleMinutes($device))) {
            $this->lock();
        }

        return $this->isLocked();
    }

    public function leave(): void
    {
        $this->locks()->unlock();
        session()->forget(self::SESSION_KEY);
    }

    /**
     * The idle minutes of this activation, from the lock source (the base's own: the person's choice, else config
     * `sylloo-app-base.pin.idle_minutes`). 0 never locks by itself.
     */
    public static function idleMinutes(DeviceState $device): int
    {
        return app(self::class)->locks()->idleMinutes($device);
    }
}
