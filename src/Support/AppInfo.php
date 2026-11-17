<?php

namespace Rishadblack\SyllooAppBase\Support;

use Rishadblack\SyllooAppBase\Device\Activation;

/**
 * What the app is (config `sylloo-app-base.app`): its name, the kind it activates as, its platform, its version and its
 * deep-link scheme. Read in one place, so every call and every screen says the same.
 */
final class AppInfo
{
    public static function name(): string
    {
        $name = config('sylloo-app-base.app.name');

        return is_string($name) && $name !== '' ? $name : (string) config('app.name', 'Sylloo');
    }

    /**
     * `pos` or `app`: what the app sends to `POST activate`.
     */
    public static function kind(): string
    {
        $kind = config('sylloo-app-base.app.kind');

        return is_string($kind) && $kind !== '' ? $kind : 'app';
    }

    public static function platform(): string
    {
        $platform = config('sylloo-app-base.app.platform');

        return is_string($platform) && $platform !== '' ? $platform : Activation::platform();
    }

    /**
     * The running version (config `sylloo-app-base.app.version`, else `nativephp.version`), at most 20 characters.
     */
    public static function version(): string
    {
        $version = config('sylloo-app-base.app.version');

        if (! is_string($version) || $version === '') {
            $version = (string) config('nativephp.version');
        }

        return mb_substr($version, 0, 20);
    }

    public static function linkScheme(): string
    {
        $scheme = config('sylloo-app-base.app.link_scheme');

        return is_string($scheme) && $scheme !== '' ? strtolower($scheme) : 'sylloo-app';
    }

    /**
     * The app's word for itself in the texts (config `sylloo-app-base.app.device_word`): `device` (default), `terminal`
     * (the till) or `phone`; an app may add its own word to the texts' `words`.
     */
    public static function deviceWord(): string
    {
        $word = config('sylloo-app-base.app.device_word');

        return is_string($word) && preg_match('/^[a-z_]+$/', $word) === 1 ? $word : 'device';
    }

    public static function isMultiBusiness(): bool
    {
        return (bool) config('sylloo-app-base.app.multi_business');
    }

    /**
     * Whether a version is older than a minimum (`v` prefixes ignored); false when either is empty.
     */
    public static function isOlderThan(?string $minimum, ?string $version = null): bool
    {
        $version ??= self::version();

        return is_string($minimum) && $minimum !== '' && $version !== ''
            && version_compare(ltrim($version, 'vV'), ltrim($minimum, 'vV'), '<');
    }
}
