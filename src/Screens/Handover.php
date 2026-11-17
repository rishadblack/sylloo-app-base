<?php

namespace Rishadblack\SyllooAppBase\Screens;

/**
 * What one screen hands to the next, kept in the session (never in a page): the member a PIN screen is for, the
 * person who must create a PIN, "forgot PIN" (sign in with the browser, then create a new PIN), and a problem line
 * the next screen shows ("Your sign-in here ended").
 */
final class Handover
{
    public const SESSION_KEY = 'sylloo-app-base.handover';

    public const INTENT_FORGOT_PIN = 'forgot_pin';

    /** The PIN screen asks for a new PIN (config `screens.pin.create_here`). */
    public const INTENT_CREATE_PIN = 'create_pin';

    /** The PIN being chosen replaces a forgotten one ("Set a new PIN"). */
    public const NEW_PIN = 'new_pin';

    /** Sign in starts the browser sign-in as it opens ("Forgot PIN?" with `screens.pin.forgot_starts_browser`). */
    public const START_BROWSER = 'start_browser';

    public static function put(string $key, mixed $value): void
    {
        session()->put(self::SESSION_KEY.'.'.$key, $value);
    }

    public static function get(string $key): mixed
    {
        return session()->get(self::SESSION_KEY.'.'.$key);
    }

    public static function pull(string $key): mixed
    {
        return session()->pull(self::SESSION_KEY.'.'.$key);
    }

    public static function forget(?string $key = null): void
    {
        session()->forget($key === null ? self::SESSION_KEY : self::SESSION_KEY.'.'.$key);
    }

    /**
     * Show a problem line on the next screen.
     */
    public static function problem(string $text): void
    {
        self::put('problem', $text);
    }

    public static function pullProblem(): ?string
    {
        $problem = self::pull('problem');

        return is_string($problem) ? $problem : null;
    }
}
