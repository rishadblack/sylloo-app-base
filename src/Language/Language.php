<?php

namespace Rishadblack\SyllooAppBase\Language;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

/**
 * The language the app shows (English, Bangla, Finnish; config `sylloo-app-base.languages`). Chosen once per install,
 * before or after activation, so it is kept in a small file of the app's local disk, not in an activation.
 */
final class Language
{
    public const DISK = 'local';

    public const PATH = 'sylloo-app-base/language';

    /**
     * @return array<string, string> code => name
     */
    public static function choices(): array
    {
        return array_filter((array) config('sylloo-app-base.languages', []), 'is_string');
    }

    public static function isChoice(mixed $code): bool
    {
        return is_string($code) && array_key_exists($code, self::choices());
    }

    /**
     * The chosen language, or null (the app's own locale).
     */
    public static function chosen(): ?string
    {
        $code = rescue(fn () => Storage::disk(self::DISK)->exists(self::PATH) ? trim((string) Storage::disk(self::DISK)->get(self::PATH)) : null, null, false);

        return self::isChoice($code) ? $code : null;
    }

    public static function choose(string $code): void
    {
        if (! self::isChoice($code)) {
            return;
        }

        Storage::disk(self::DISK)->put(self::PATH, $code);
        App::setLocale($code);
    }

    /**
     * Use the chosen language for this request.
     */
    public static function apply(): void
    {
        $code = self::chosen();

        if ($code !== null) {
            App::setLocale($code);
        }
    }

    public static function current(): string
    {
        return App::getLocale();
    }
}
