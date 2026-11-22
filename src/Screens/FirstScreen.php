<?php

namespace Rishadblack\SyllooAppBase\Screens;

use Rishadblack\SyllooAppBase\Contracts\DeviceEntries;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Language\Language;
use Rishadblack\SyllooAppBase\Pin\Presence;

/**
 * Which shared screen the device must show before an app page may open: `activate`, `state`, `sign_in`, `lock`, or
 * null when it is ready. One decision for every face: the web middleware `sylloo.device` (RequireDeviceReady) and the
 * phone's native screens (sylloo-app-native's ReadyDevice) both ask here, so the order and the rules live once.
 */
class FirstScreen
{
    public const ACTIVATE = 'activate';

    public const STATE = 'state';

    public const SIGN_IN = 'sign_in';

    public const LOCK = 'lock';

    public function __construct(protected DeviceEntries $entries, protected Presence $presence) {}

    /**
     * What every face asks before an app page or native screen opens (base 1.1.0): the person's language is applied,
     * then {@see self::name()}. The web middleware `sylloo.device` and sylloo-app-native's `NeedsReadyDevice` /
     * `ReadyDevice` both call it, so the two steps never drift apart.
     */
    public function screenFirst(): ?string
    {
        Language::apply();

        return $this->name();
    }

    /**
     * The screen name (a key of ScreenRoutes), or null when the device is ready. When ready, the idle time starts
     * again, as each page the person opens does. A look that is not the person's doing (a screen's timed poll) passes
     * `$touch = false`: it locks when the idle time passed but never starts it again (audit H2, else a polling screen
     * never locks).
     */
    public function name(bool $touch = true): ?string
    {
        $entry = $this->entries->current();
        $screen = $entry->state->screen();

        if ($screen === DeviceState::NOT_ACTIVATED) {
            return self::ACTIVATE;
        }

        if ($screen !== null) {
            return self::STATE;
        }

        if (! $this->presence->isSignedIn($entry->id)) {
            return self::SIGN_IN;
        }

        if ($this->presence->lockWhenIdle($entry->state)) {
            return self::LOCK;
        }

        if ($touch) {
            $this->presence->touch();
        }

        return null;
    }
}
