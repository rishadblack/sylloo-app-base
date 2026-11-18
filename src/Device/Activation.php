<?php

namespace Rishadblack\SyllooAppBase\Device;

use Illuminate\Support\Str;
use Rishadblack\SyllooAppBase\Support\AppInfo;

/**
 * The Activate step (../sylloo-erp-docs/erp/device-activation-plan.md §5.1, device-api.md §1 `POST activate`): the
 * business code (16 digits, typed or pasted with or without dashes, or from `sylloo-app://activate?code=…`) and the
 * device's name. Send {@see self::payload()} with DeviceApi::activateDevice(); a 201 answer that
 * {@see self::isComplete()} is kept with DeviceState::rememberActivation(); a refusal is read with Erp\Refusal.
 */
final class Activation
{
    /**
     * The digits of a typed code, without dashes or spaces.
     */
    public static function digitsOf(mixed $code): string
    {
        return Str::of((string) $code)->replaceMatches('/\D/', '')->toString();
    }

    /**
     * Whether the digits are a business code (16 digits).
     */
    public static function isCode(string $digits): bool
    {
        return preg_match('/^\d{16}$/', $digits) === 1;
    }

    /**
     * The code in four groups of four (`1234-5678-1234-5678`), as far as it goes.
     */
    public static function formatted(string $digits): string
    {
        return implode('-', str_split(substr(self::digitsOf($digits), 0, 16), 4) ?: ['']);
    }

    /**
     * The scheme of the activation QR code the office draws for every kind of device (ERP Modules/Device
     * `PortalDeviceController::code()`, portal `RevealDeviceCode::LINK_PREFIX`): always `sylloo-app`, also for a till
     * whose own link scheme is `sylloo-pos`.
     */
    public const QR_SCHEME = 'sylloo-app';

    /**
     * The code of an activation link (`<scheme>://activate?code=<16 digits>`, the portal's QR code), or null for any
     * other link. The app's own scheme and {@see self::QR_SCHEME} are read; the code may carry dashes or spaces.
     */
    public static function codeFromLink(mixed $url, string $scheme): ?string
    {
        if (is_array($url)) {
            $url = $url['url'] ?? ($url[0] ?? null);
        }

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));
        $schemes = array_unique([strtolower($scheme), self::QR_SCHEME]);

        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), $schemes, true) || strtolower($parts['host'] ?? '') !== 'activate') {
            return null;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';

        return self::codeOfDigitsOnly($code);
    }

    /**
     * What a scanned QR code holds: the code and whether it came as an activation link (`link` true: the office's QR,
     * so the app may activate at once), as the 16 digits alone (`link` false: the app fills the field), or no code
     * (`code` null: any other text, a web address with digits in it included).
     *
     * @return array{code: ?string, link: bool}
     */
    public static function codeFromScan(mixed $text, string $scheme): array
    {
        $link = self::codeFromLink($text, $scheme);

        if ($link !== null) {
            return ['code' => $link, 'link' => true];
        }

        return ['code' => is_string($text) ? self::codeOfDigitsOnly($text) : null, 'link' => false];
    }

    /**
     * The 16 digits of a text that holds only digits, dashes and spaces, else null.
     */
    private static function codeOfDigitsOnly(string $text): ?string
    {
        if (preg_match('/^[\d\s-]+$/', trim($text)) !== 1) {
            return null;
        }

        $digits = self::digitsOf($text);

        return self::isCode($digits) ? $digits : null;
    }

    /**
     * The computer's name, the default name of the device (at most 60 characters), or the fallback.
     */
    public static function computerName(string $fallback): string
    {
        $name = trim((string) (getenv('COMPUTERNAME') ?: gethostname() ?: ''));

        return $name !== '' ? mb_substr($name, 0, 60) : $fallback;
    }

    /**
     * The platform name the ERP knows: windows, macos, linux (or the system's own name); an app on a phone passes its own.
     */
    public static function platform(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'Darwin' => 'macos',
            'Linux' => 'linux',
            default => strtolower(PHP_OS_FAMILY),
        };
    }

    /**
     * The activation body: the code, the kind (`pos` or `app`), this install's random id, the name, the system and the
     * app version (AppInfo::version()), plus what the app adds (a till's queue counts).
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function payload(string $code, string $kind, string $deviceUid, string $name, array $extra = [], ?string $platform = null): array
    {
        return [
            'code' => $code,
            'kind' => $kind,
            'device_uid' => $deviceUid,
            'name' => $name,
            'platform' => $platform ?? self::platform(),
            'os' => mb_substr(trim(PHP_OS_FAMILY.' '.php_uname('r')), 0, 100),
            'app_version' => AppInfo::version(),
            ...$extra,
        ];
    }

    /**
     * Whether an activation answer holds what every app keeps (audit A11): the key and the mode. An app checks its own
     * parts on top (a till: `terminal.desktop_pos_id`).
     *
     * @param  array<string, mixed>  $answer
     */
    public static function isComplete(array $answer): bool
    {
        return is_string($answer['device_key'] ?? null) && trim($answer['device_key']) !== ''
            && in_array($answer['mode'] ?? null, ['live', 'practice'], true);
    }
}
