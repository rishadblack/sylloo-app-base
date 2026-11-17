<?php

namespace Rishadblack\SyllooAppBase\Pin;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooAppBase\Contracts\DeviceMember;
use Rishadblack\SyllooAppBase\Contracts\MemberDirectory;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Throwable;

/**
 * The member's PIN on this device (../sylloo-erp-docs/erp/device-activation-plan.md §5.4, §6.5): checked here against
 * the hash the device keeps (the office's `pin_hash`, or the hash kept when the member created the PIN here), never
 * sent except to `POST pin`. Five wrong PINs in a row end that member's sign-in on this device: the device tells the
 * office (`POST sign-out`, when online) and forgets it, so they must use Sign in with browser again. Other members are
 * not touched.
 *
 * One instance works on one activation: its members ({@see MemberDirectory}) and its {@see DeviceState} (offline days).
 */
class PinSignIn
{
    /** Wrong PINs in a row before the member's sign-in on this device ends. */
    public const MAX_WRONG = 5;

    public const FAILURES_CACHE_PREFIX = 'pos.pin_failures.';

    public const CHECKED = 'checked';

    public const WRONG = 'wrong';

    public const SIGNED_OUT = 'signed_out';

    public const NO_PIN = 'no_pin';

    public function __construct(protected MemberDirectory $members, protected DeviceState $device) {}

    /**
     * Why a new PIN may not be used, as a translation key (the base's `sylloo-app-base::device` texts), or null when it is fine: 4–6
     * digits, not one digit repeated (0000, 1111…), and not a run up or down (1234, 9876, 0123…). The office checks it
     * again.
     */
    public static function problemWith(string $pin): ?string
    {
        if (! preg_match('/^\d{4,6}$/', $pin)) {
            return 'sylloo-app-base::device.pin_format';
        }

        if (count(array_unique(str_split($pin))) === 1) {
            return 'sylloo-app-base::device.pin_too_easy';
        }

        $steps = [];

        for ($i = 1; $i < strlen($pin); $i++) {
            $steps[] = (int) $pin[$i] - (int) $pin[$i - 1];
        }

        $steps = array_unique($steps);

        if (count($steps) === 1 && in_array(reset($steps), [1, -1], true)) {
            return 'sylloo-app-base::device.pin_too_easy';
        }

        return null;
    }

    /**
     * Check a member's PIN: {@see self::CHECKED}, {@see self::WRONG}, {@see self::NO_PIN} (no hash here), or
     * {@see self::SIGNED_OUT} after the fifth wrong PIN in a row (their sign-in here ended).
     *
     * @return self::CHECKED|self::WRONG|self::SIGNED_OUT|self::NO_PIN
     */
    public function check(DeviceMember $member, string $pin): string
    {
        $hash = $this->members->pinHash($member);

        if (! filled($hash)) {
            return self::NO_PIN;
        }

        if (Hash::check($pin, (string) $hash)) {
            cache()->forget(self::FAILURES_CACHE_PREFIX.$member->memberKey());

            return self::CHECKED;
        }

        $failures = (int) cache()->get(self::FAILURES_CACHE_PREFIX.$member->memberKey(), 0) + 1;

        if ($failures >= self::MAX_WRONG) {
            $this->endSignIn($member);

            return self::SIGNED_OUT;
        }

        cache()->forever(self::FAILURES_CACHE_PREFIX.$member->memberKey(), $failures);

        return self::WRONG;
    }

    /**
     * Wrong PINs left before the member's sign-in ends.
     */
    public static function triesLeft(DeviceMember $member): int
    {
        return max(0, self::MAX_WRONG - (int) cache()->get(self::FAILURES_CACHE_PREFIX.$member->memberKey(), 0));
    }

    /**
     * Start the wrong-PIN count again (a new browser sign-in of the member).
     */
    public static function forgetFailures(DeviceMember|string $member): void
    {
        cache()->forget(self::FAILURES_CACHE_PREFIX.($member instanceof DeviceMember ? $member->memberKey() : $member));
    }

    /**
     * End the member's sign-in on this device: the office is told when it can be reached, the device forgets it.
     */
    public function endSignIn(DeviceMember $member): void
    {
        if ($member->hasValidToken()) {
            try {
                $this->members->deviceApi($member)->signOut();
            } catch (Throwable $exception) {
                Log::info('PIN sign-in: the office was not told about the ended sign-in: '.$exception->getMessage());
            }
        }

        $member->forgetSignIn();
        cache()->forget(self::FAILURES_CACHE_PREFIX.$member->memberKey());
    }

    /**
     * Keep the hash of a PIN the member just created here, so it works at once: the office's hash from `POST pin`
     * (`pin_hash`), else a hash made here (the office's hash of the same PIN arrives later).
     */
    public function rememberPin(DeviceMember $member, string $pin, mixed $officeHash = null): void
    {
        $this->rememberHash($member, self::isBcryptHash($officeHash) ? $officeHash : Hash::make($pin));
    }

    /**
     * Keep the PIN hash the office sent for the member (`sign-in/finish` and `POST pin` answer `pin_hash`), so the PIN
     * works at once. Nothing changes when it sends none.
     */
    public function rememberHash(DeviceMember $member, mixed $hash): void
    {
        if (! self::isBcryptHash($hash)) {
            return;
        }

        $this->members->rememberPinHash($member, $hash);
    }

    /**
     * Ask the office for the member's PIN hash and photo (`GET me`), so a PIN changed or cleared on another device is
     * known here. A sign-in the office ended is forgotten. Returns whether the office answered.
     */
    public function refresh(DeviceMember $member): bool
    {
        if (! $member->hasValidToken()) {
            return false;
        }

        try {
            $response = $this->members->deviceApi($member)->me();
        } catch (Throwable) {
            return false;
        }

        if ($response->status() === 401 && ! DeviceState::isReleasedReply($response)) {
            $member->forgetSignIn();

            return true;
        }

        $answer = $response->successful() ? $response->json() : null;

        if (! is_array($answer)) {
            return false;
        }

        if (($answer['pin_set'] ?? null) === false) {
            $this->members->forgetPinHash($member);
        } else {
            $this->rememberHash($member, $answer['pin_hash'] ?? null);
        }

        $photo = $answer['member']['photo'] ?? null;

        if ((is_string($photo) || $photo === null) && $photo !== $member->photoUrl()) {
            $this->members->rememberPhoto($member, $photo);
        }

        return true;
    }

    /**
     * Ask the office about every member signed in on this activation (the app opened online).
     */
    public function refreshAll(): void
    {
        foreach ($this->members->signedInMembers() as $member) {
            $this->refresh($member);
        }
    }

    /**
     * Whether a value is a bcrypt hash (the only PIN hash the office sends).
     */
    public static function isBcryptHash(mixed $hash): bool
    {
        return is_string($hash) && preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $hash) === 1;
    }

    /**
     * Whether the member may sign in with the PIN without asking the office: their sign-in here is valid and the
     * offline window of its last confirmation has not passed.
     */
    public static function mayUseOffline(DeviceMember $member): bool
    {
        return $member->hasValidToken() && $member->offlineSignInUntil()?->isFuture() === true;
    }

    /**
     * After the office confirmed the member's sign-in (online), PIN sign-in works offline again for the offline days.
     */
    public function confirmOnline(DeviceMember $member): void
    {
        $member->allowOfflineSignInFor($this->device->offlineLoginDays());
    }
}
