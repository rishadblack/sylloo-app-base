<?php

namespace Rishadblack\SyllooAppBase\Contracts;

use Carbon\CarbonInterface;

/**
 * A person signed in on this device with Sign in with browser (`sign-in/finish` answered `member{id, name, photo}` and a
 * token). Sylloo POS: its `App\Models\User`; Sylloo App: one member of one business.
 */
interface DeviceMember
{
    /**
     * A key unique on this device (the PIN failure count is kept under it). An app with several businesses makes it
     * unique across them (e.g. business id + member id).
     */
    public function memberKey(): string;

    public function memberName(): string;

    /**
     * The member's photo address the office sent (https), or null.
     */
    public function photoUrl(): ?string;

    /**
     * Whether the member's sign-in token on this device is there and has not expired.
     */
    public function hasValidToken(): bool;

    /**
     * Until when a PIN sign-in works without asking the office, or null.
     */
    public function offlineSignInUntil(): ?CarbonInterface;

    /**
     * Allow PIN sign-in offline for the given number of days from now (at most 30), after the office confirmed it.
     */
    public function allowOfflineSignInFor(int $days): void;

    /**
     * End the sign-in on this device: token and offline window go, so the member signs in with the browser again.
     */
    public function forgetSignIn(): void;
}
