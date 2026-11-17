<?php

namespace Rishadblack\SyllooAppBase\Erp;

use Rishadblack\SyllooAppBase\Support\Texts;

/**
 * The ERP's refusals (device-api.md §1): every refusal answers `{message, code, …extra}`; the app reads `code`, never
 * `message`, and shows the base's sentence for the code (`sylloo-app-base::device.refused_<code>`, an app may override it by publishing the texts), else the ERP's
 * `message`, else `device.unexpected`.
 */
final class Refusal
{
    public const INVALID_CODE = 'invalid_code';

    public const TOO_MANY_ATTEMPTS = 'too_many_attempts';

    public const PLAN_ENDED = 'plan_ended';

    public const LIVE_NOT_AVAILABLE = 'live_not_available';

    public const BUSINESS_SUSPENDED = 'business_suspended';

    public const NOT_IN_PLAN_POS = 'not_in_plan_pos';

    public const NOT_IN_PLAN_APP = 'not_in_plan_app';

    public const TOO_MANY_PENDING = 'too_many_pending';

    public const DEVICE_LIMIT_REACHED_POS = 'device_limit_reached_pos';

    public const DEVICE_LIMIT_REACHED_APP = 'device_limit_reached_app';

    public const DEVICE_RELEASED = 'device_released';

    public const APP_TOO_OLD = 'app_too_old';

    public const SIGN_IN_PENDING = 'sign_in_pending';

    public const SIGN_IN_REFUSED = 'sign_in_refused';

    public const SIGN_IN_EXPIRED = 'sign_in_expired';

    public const MEMBER_NOT_ALLOWED = 'member_not_allowed';

    public const NOT_ALLOWED_ON_TERMINAL = 'not_allowed_on_terminal';

    public const SESSION_EXPIRED = 'session_expired';

    public const SIGNED_IN_ELSEWHERE = 'signed_in_elsewhere';

    public const VALIDATION_FAILED = 'validation_failed';

    /**
     * The `code` of an answer, or null.
     *
     * @param  array<string, mixed>  $answer
     */
    public static function code(array $answer): ?string
    {
        return is_string($answer['code'] ?? null) ? $answer['code'] : null;
    }

    /**
     * The refusal in plain words: the app's text for the code, else the ERP's message, else "unexpected". For the
     * general codes (`refused`, `not_found`, `forbidden`,
     * `validation_failed`, and the REST door's `no_permission` and `feature_off`) the ERP's own message says more, so it wins.
     *
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>  $replace  more words for the text (`:allowed` of the device limit)
     */
    public static function text(array $answer, array $replace = []): string
    {
        $code = self::code($answer);

        $general = in_array($code, ['refused', 'not_found', 'forbidden', 'validation_failed', 'no_permission', 'feature_off'], true);
        $own = $code !== null ? self::appTextKey($code) : null;

        return match (true) {
            $own !== null => (string) __($own, [...Texts::parameters(), ...$replace]),
            $general && is_string($answer['message'] ?? null) && $answer['message'] !== '' => $answer['message'],
            $code !== null && Texts::has('refused_'.$code) => Texts::get('refused_'.$code, $replace),
            is_string($answer['message'] ?? null) && $answer['message'] !== '' => $answer['message'],
            default => Texts::get('unexpected'),
        };
    }

    /**
     * The app's own text key for a refusal code (config `sylloo-app-base.texts.refusals`): a prefix (`device.refused_`:
     * the app's `device.refused_<code>`, used when that key exists) or a map (`['invalid_code' => 'pos.bad_code']`).
     * Null when the app has none for this code (the base's text is used).
     */
    public static function appTextKey(string $code): ?string
    {
        $refusals = config('sylloo-app-base.texts.refusals');

        $key = match (true) {
            is_string($refusals) && $refusals !== '' => $refusals.$code,
            is_array($refusals) && is_string($refusals[$code] ?? null) => $refusals[$code],
            default => null,
        };

        return $key !== null && trans()->has($key) ? $key : null;
    }

    /**
     * Whether the code says all the plan's places of this kind are in use (`device_limit_reached_pos` / `_app`).
     */
    public static function isDeviceLimit(?string $code, string $kind): bool
    {
        return $code === 'device_limit_reached_'.$kind;
    }

    /**
     * The All devices in use list of a `device_limit_reached_*` refusal (from `activate` and `sign-in/finish`): how many
     * the plan allows and the active devices (at most 50).
     *
     * @param  array<string, mixed>  $answer
     * @return array{allowed: ?int, devices: list<array{name: string, last_seen_at: ?string, last_user: ?string}>}
     */
    public static function limitFrom(array $answer): array
    {
        return [
            'allowed' => is_numeric($answer['allowed'] ?? null) ? (int) $answer['allowed'] : null,
            'devices' => collect(is_array($answer['devices'] ?? null) ? $answer['devices'] : [])
                ->filter(fn ($device) => is_array($device) && is_string($device['name'] ?? null))
                ->map(fn (array $device) => [
                    'name' => $device['name'],
                    'last_seen_at' => is_string($device['last_seen_at'] ?? null) ? $device['last_seen_at'] : null,
                    'last_user' => is_string($device['last_user'] ?? null) ? $device['last_user'] : null,
                ])->take(50)->values()->all(),
        ];
    }
}
