<?php

namespace Rishadblack\SyllooAppBase\Support;

/**
 * The icons of the shared screens, by name (plan §4.2 "Look"). The screens name Bootstrap Icons (`arrow-right`, or the
 * class `bi bi-arrow-right`); the app chooses the set they are drawn with (config `sylloo-app-base.icons.set`:
 * `bootstrap`, the default, or `tabler`, as the till uses) and may map any name to its own class (`icons.map`). A
 * class of another set (`ti ti-x`, `fa fa-x`) is drawn as given. With `tabler` (or every name mapped) the app needs no
 * bootstrap-icons font.
 */
final class Icons
{
    /** Bootstrap Icons names → Tabler Icons names, for the names the base uses. */
    public const TABLER = [
        'arrow-counterclockwise' => 'rotate',
        'arrow-repeat' => 'refresh',
        'arrow-right' => 'arrow-right',
        'arrow-right-circle' => 'circle-arrow-right',
        'backspace' => 'backspace',
        'box-arrow-right' => 'logout',
        'box-arrow-up-right' => 'external-link',
        'browser-chrome' => 'brand-chrome',
        'calendar-x' => 'calendar-x',
        'check-circle' => 'circle-check',
        'check-lg' => 'check',
        'check2-circle' => 'circle-check',
        'cloud-arrow-down' => 'cloud-download',
        'cone-striped' => 'traffic-cone',
        'display' => 'device-desktop',
        'exclamation-octagon' => 'alert-octagon',
        'exclamation-triangle' => 'alert-triangle',
        'hourglass-split' => 'hourglass',
        'info-circle' => 'info-circle',
        'lock' => 'lock',
        'pause-circle' => 'player-pause',
        'plus-lg' => 'plus',
        'power' => 'power',
        'shield-lock' => 'shield-lock',
        'slash-circle' => 'ban',
        'translate' => 'language',
        'wifi' => 'wifi',
        'wifi-off' => 'wifi-off',
    ];

    /**
     * The class of an icon: a Bootstrap name (`arrow-right`) or class (`bi bi-arrow-right`) in the app's set, or
     * another set's class as given. Null for none.
     */
    public static function of(?string $icon): ?string
    {
        if ($icon === null || trim($icon) === '') {
            return null;
        }

        $icon = trim($icon);
        $name = preg_match('/^bi bi-([a-z0-9-]+)$/', $icon, $match) === 1 ? $match[1] : $icon;

        if (preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
            return $icon;
        }

        $map = config('sylloo-app-base.icons.map', []);

        if (is_array($map) && is_string($map[$name] ?? null) && $map[$name] !== '') {
            return $map[$name];
        }

        return match (self::set()) {
            'tabler' => 'ti ti-'.(self::TABLER[$name] ?? $name),
            default => 'bi bi-'.$name,
        };
    }

    public static function set(): string
    {
        $set = config('sylloo-app-base.icons.set', 'bootstrap');

        return is_string($set) && $set !== '' ? $set : 'bootstrap';
    }
}
