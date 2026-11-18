<?php

namespace Rishadblack\SyllooAppBase\Update;

use Illuminate\Support\Facades\File;

/**
 * Release signing for any app on the base (`release:sign-update`, `release:sign-config`): where the release key is,
 * which public key the installed apps carry, and the signature over an update. Moved from Sylloo App (desktop-d2.md
 * §6), generic since base 1.1.0. The key never enters a repository; tests make a throw-away key.
 *
 * Config `sylloo-app-base.release`: `key_path` (null = `~/.sylloo/update-signing.key`), `public_key` (the key built
 * into the app; null = read from `public_key_file`, the Electron `updateSignature.ts` with
 * `export const UPDATE_PUBLIC_KEY = '…'`), `latest_yml` (null = the NativePHP Electron build's `dist/latest.yml`).
 */
final class ReleaseSigning
{
    /**
     * The Ed25519 signature over "<version>|<sha512 of the installer>" (base64), as the base's Electron
     * `updateSignature.ts` checks it.
     */
    public static function sign(string $version, string $sha512, string $secretKey): string
    {
        return base64_encode(sodium_crypto_sign_detached("{$version}|{$sha512}", $secretKey));
    }

    /**
     * A top-level value of latest.yml (`version: 1.0.0`), quotes removed.
     */
    public static function topLevel(string $yaml, string $key): ?string
    {
        return preg_match('/^'.preg_quote($key, '/').':\s*[\'"]?([^\'"\r\n]+)[\'"]?\s*$/m', $yaml, $match) ? trim($match[1]) : null;
    }

    /**
     * The private key file: the option, else config `release.key_path`, else `SYLLOO_UPDATE_KEY_PATH`, else
     * `~/.sylloo/update-signing.key`.
     */
    public static function keyPath(?string $path = null): string
    {
        $home = rtrim((string) (getenv('USERPROFILE') ?: getenv('HOME')), '\\/');

        return $path
            ?: (string) (config('sylloo-app-base.release.key_path') ?: getenv('SYLLOO_UPDATE_KEY_PATH') ?: $home.DIRECTORY_SEPARATOR.'.sylloo'.DIRECTORY_SEPARATOR.'update-signing.key');
    }

    /**
     * The secret key in a key file (base64), or null when it is not an Ed25519 signing key.
     */
    public static function secretKey(string $keyPath): ?string
    {
        $secretKey = base64_decode(trim(File::get($keyPath)), true);

        return $secretKey !== false && strlen($secretKey) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ? $secretKey : null;
    }

    public static function publicKeyOf(string $secretKey): string
    {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($secretKey));
    }

    /**
     * The NativePHP Electron folder of the app (`nativephp/electron`).
     */
    public static function electronPath(): string
    {
        $provider = 'Native\\Desktop\\Drivers\\Electron\\ElectronServiceProvider';

        $electronPath = [$provider, 'electronPath'];

        return class_exists($provider) && is_callable($electronPath)
            ? (string) call_user_func($electronPath)
            : base_path('nativephp'.DIRECTORY_SEPARATOR.'electron');
    }

    public static function latestYmlPath(): string
    {
        return (string) (config('sylloo-app-base.release.latest_yml')
            ?: self::electronPath().DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'latest.yml');
    }

    public static function publicKeyFile(): string
    {
        return (string) (config('sylloo-app-base.release.public_key_file')
            ?: implode(DIRECTORY_SEPARATOR, [self::electronPath(), 'electron-plugin', 'src', 'server', 'updateSignature.ts']));
    }

    /**
     * The update public key built into the app, or '' while there is none: config `release.public_key`, else the one
     * written in the given file (or `release.public_key_file`).
     */
    public static function builtInPublicKey(?string $file = null): string
    {
        if ($file === null && is_string($key = config('sylloo-app-base.release.public_key')) && $key !== '') {
            return $key;
        }

        $file ??= self::publicKeyFile();

        if (! File::exists($file) || ! preg_match("/export const UPDATE_PUBLIC_KEY = '([^']*)';/", File::get($file), $match)) {
            return '';
        }

        return $match[1];
    }
}
