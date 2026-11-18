<?php

namespace Rishadblack\SyllooAppBase\Testing;

use RuntimeException;

/**
 * The shared business-window cases (`resources/testing/window-cases.json`, shipped in every release): one file for
 * the base's `Window\WindowRules`, an app's own window filter (the desktop app's Electron `decide()`), and the mobile
 * app's Kotlin filter. An app's test reads the file through {@see self::path()} (TS / Kotlin) or the methods here
 * (Pest), so a case is added once and every side is held to it.
 *
 * - `cases`: `{url, decide, mayShow, note?}`: `decide` is what a window filter does with a link (`allow`, `signIn`,
 *   `signOut`, `external`, `block`); `mayShow` is `WindowRules::mayShow()` with `hosts` and `allowedPaths`.
 * - `tickets`: `{url, ticket}`: whether the address may open the window (`WindowRules::isTicketUrl()`).
 * - Read as production (http refused) with `officeUrl` as the office address (`erp.base_url`): {@see self::useOffice()}.
 */
final class WindowCases
{
    public const FILE = __DIR__.'/../../resources/testing/window-cases.json';

    /** @var array<string, mixed>|null */
    protected static ?array $cases = null;

    /**
     * The fixture's full path (for a test outside PHP).
     */
    public static function path(): string
    {
        return (string) realpath(self::FILE);
    }

    /**
     * @return array{about: string, officeUrl: string, hosts: list<string>, allowedPaths: list<string>, cases: list<array{url: string, decide: string, mayShow: bool, note?: string}>, tickets: list<array{url: string, ticket: bool}>}
     */
    public static function all(): array
    {
        if (self::$cases === null) {
            $cases = json_decode((string) file_get_contents(self::FILE), true);

            if (! is_array($cases) || ! isset($cases['cases'], $cases['tickets'], $cases['hosts'], $cases['allowedPaths'])) {
                throw new RuntimeException('The window cases file is missing or does not read.');
            }

            self::$cases = $cases;
        }

        /** @var array{about: string, officeUrl: string, hosts: list<string>, allowedPaths: list<string>, cases: list<array{url: string, decide: string, mayShow: bool, note?: string}>, tickets: list<array{url: string, ticket: bool}>} */
        return self::$cases;
    }

    /**
     * @return list<array{url: string, decide: string, mayShow: bool, note?: string}>
     */
    public static function cases(): array
    {
        return self::all()['cases'];
    }

    /**
     * @return list<array{url: string, ticket: bool}>
     */
    public static function tickets(): array
    {
        return self::all()['tickets'];
    }

    /**
     * @return list<string>
     */
    public static function hosts(): array
    {
        return self::all()['hosts'];
    }

    /**
     * @return list<string>
     */
    public static function allowedPaths(): array
    {
        return self::all()['allowedPaths'];
    }

    /**
     * Sets the office address the cases are written for (`erp.base_url`), as the port rule reads it.
     */
    public static function useOffice(): void
    {
        config(['erp.base_url' => self::all()['officeUrl']]);
    }
}
