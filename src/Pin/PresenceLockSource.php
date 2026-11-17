<?php

namespace Rishadblack\SyllooAppBase\Pin;

use Rishadblack\SyllooAppBase\Contracts\LockSource;
use Rishadblack\SyllooAppBase\Device\DeviceState;

/**
 * The base's own lock: a flag in the presence session ({@see Presence::SESSION_KEY}), and the idle minutes the person
 * chose in PIN and lock settings, else config `sylloo-app-base.pin.idle_minutes`.
 */
class PresenceLockSource implements LockSource
{
    public function isLocked(): bool
    {
        $presence = session()->get(Presence::SESSION_KEY);

        return is_array($presence) && ($presence['locked'] ?? false) === true;
    }

    public function lock(): bool
    {
        $presence = session()->get(Presence::SESSION_KEY);

        if (! is_array($presence) || ! is_string($presence['member'] ?? null)) {
            return false;
        }

        session()->put(Presence::SESSION_KEY, [...$presence, 'locked' => true]);

        return true;
    }

    public function unlock(): void
    {
        $presence = session()->get(Presence::SESSION_KEY);

        if (is_array($presence)) {
            session()->put(Presence::SESSION_KEY, [...$presence, 'locked' => false, 'last_seen' => now()->timestamp]);
        }
    }

    public function idleMinutes(DeviceState $device): int
    {
        $chosen = $device->get(Presence::IDLE_SETTING);

        return is_numeric($chosen) ? max(0, (int) $chosen) : max(0, (int) config('sylloo-app-base.pin.idle_minutes', 5));
    }
}
