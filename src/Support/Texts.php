<?php

namespace Rishadblack\SyllooAppBase\Support;

/**
 * The base's texts (`sylloo-app-base::device.<key>`, English, Bangla and Finnish: every app says the same thing, plan
 * §4.2) with the parameters every text may use:
 *
 * - `:app`: the app's name (config `sylloo-app-base.app.name`);
 * - `:device` / `:devices`: the app's word for itself (config `sylloo-app-base.app.device_word`: `device`, `terminal`,
 *   `phone`), in the text's language, from the texts' `words.<word>`; `:Device` starts with a capital. A language that
 *   bends its nouns adds forms there (`:device_gen`, `:device_part`, `:device_ill`, `:device_ine`, `:device_ade`,
 *   `:device_ela`, `:device_loc`, `:devices_part`); a form a language does not name is the plain word.
 *
 * An app changes any text, or adds a word of its own, in `lang/vendor/sylloo-app-base/<lang>/device.php`: only the
 * keys it names change (Laravel merges the file over the base's).
 */
final class Texts
{
    public const NAMESPACE = 'sylloo-app-base::device';

    /** Every form of the device word a text may use. */
    public const FORMS = ['device', 'devices', 'device_gen', 'device_part', 'device_ill', 'device_ine', 'device_ade', 'device_ela', 'device_loc', 'devices_part'];

    /**
     * A text of the base with the shared parameters (and the given ones, which win).
     *
     * @param  array<string, mixed>  $replace
     */
    public static function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $replace = array_map(fn ($value) => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? self::digits((string) $value, $locale) : $value, $replace);
        $text = __(self::NAMESPACE.'.'.$key, [...self::parameters($locale), ...$replace], $locale);

        return is_string($text) ? $text : self::NAMESPACE.'.'.$key;
    }

    /**
     * The digits of a text in the language's own digits (Bangla: ০–৯; phone check 2026-11-20: "5 tries left", the PIN
     * pad and the version stayed 0–9 in Bangla). Other languages keep 0–9. Only for showing: never for what is typed or
     * sent.
     */
    public static function digits(string $text, ?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'bn' ? numberEnToBn($text) : $text;
    }

    /**
     * Whether the base (or the app's own file) has this text.
     */
    public static function has(string $key, ?string $locale = null): bool
    {
        return trans()->has(self::NAMESPACE.'.'.$key, $locale);
    }

    /**
     * A text for the office side of the app (update notices, the device test), in config
     * `sylloo-app-base.texts.locale` when set, else the app's language.
     *
     * @param  array<string, mixed>  $replace
     */
    public static function office(string $key, array $replace = []): string
    {
        return self::get($key, $replace, self::officeLocale());
    }

    public static function officeLocale(): ?string
    {
        $locale = config('sylloo-app-base.texts.locale');

        return is_string($locale) && $locale !== '' ? $locale : null;
    }

    /**
     * The parameters every text gets: `app` and the device word in each form.
     *
     * @return array<string, string>
     */
    public static function parameters(?string $locale = null): array
    {
        return ['app' => AppInfo::name(), ...self::deviceWords($locale)];
    }

    /**
     * The app's word for itself in every form, in this language.
     *
     * @return array<string, string>
     */
    public static function deviceWords(?string $locale = null): array
    {
        $word = AppInfo::deviceWord();
        $forms = trans(self::NAMESPACE.'.words.'.$word, [], $locale);
        $forms = is_array($forms) ? array_filter($forms, 'is_string') : [];
        $one = $forms['device'] ?? $word;
        $many = $forms['devices'] ?? $one;
        $words = [];

        foreach (self::FORMS as $form) {
            $words[$form] = $forms[$form] ?? (str_starts_with($form, 'devices') ? $many : $one);
        }

        return $words;
    }
}
