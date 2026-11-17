<?php

namespace Rishadblack\SyllooAppBase\Screens;

/**
 * The look slots of the shared screens' web faces (config `sylloo-app-base.screens`): the layout they render in, the
 * extra CSS classes of each screen part, and how a question is asked.
 *
 *     'screens' => [
 *         'layout' => 'layouts.guest',                              // the app's layout (it receives `$slot`)
 *         'classes' => [
 *             '*' => ['buttons' => 'd-grid gap-2'],                 // every screen
 *             'activate' => ['title' => 'display-6'],               // one screen
 *         ],
 *         'questions' => 'alert',                                   // inline (default) or livewire-alert
 *     ],
 */
final class Look
{
    /** The parts of a screen that take extra classes. */
    public const PARTS = ['screen', 'header', 'icon', 'title', 'text', 'todo', 'notice', 'question', 'card', 'rows', 'lines', 'fields', 'buttons', 'button'];

    public const BASE_LAYOUT = 'sylloo-app-base::layouts.device';

    /**
     * The layout the full pages render in: `screens.layout`, else the older `layout` key, else the base's.
     */
    public static function layout(): string
    {
        foreach (['sylloo-app-base.screens.layout', 'sylloo-app-base.layout'] as $key) {
            $layout = config($key);

            if (is_string($layout) && $layout !== '') {
                return $layout;
            }
        }

        return self::BASE_LAYOUT;
    }

    /**
     * The extra classes of a part: those for every screen, then those for this screen.
     */
    public static function classes(string $screen, string $part): string
    {
        $classes = [];

        foreach (['*', $screen] as $for) {
            $value = config('sylloo-app-base.screens.classes.'.$for.'.'.$part);

            if (is_string($value) && trim($value) !== '') {
                $classes[] = trim($value);
            }
        }

        return implode(' ', $classes);
    }

    /**
     * The extra classes of one button (config `screens.classes.buttons.<action>`), after those of every button.
     */
    public static function buttonClasses(string $screen, string $action): string
    {
        $own = config('sylloo-app-base.screens.classes.buttons.'.$action);

        return trim(self::classes($screen, 'button').' '.(is_string($own) ? trim($own) : ''));
    }

    /**
     * The text a button shows while its action runs (config `screens.loading.<action>`: a text or a translation key),
     * or null for the base's (wire-bootstrap's "Loading").
     */
    public static function loadingText(string $action): ?string
    {
        $text = config('sylloo-app-base.screens.loading.'.$action);

        return is_string($text) && $text !== '' ? (string) __($text) : null;
    }

    /**
     * The view that draws a screen's body (config `screens.views.<screen>`, else the base's
     * `sylloo-app-base::partials.screen-body`). It gets `$screen`, `$view`, `$question` and `$context`.
     */
    public static function bodyView(string $screen): string
    {
        $view = config('sylloo-app-base.screens.views.'.$screen);

        return is_string($view) && $view !== '' ? $view : 'sylloo-app-base::partials.screen-body';
    }

    /**
     * How a question is asked: `inline` (a card in the screen, the default) or `alert` (livewire-alert).
     */
    public static function questions(): string
    {
        return config('sylloo-app-base.screens.questions') === 'alert' ? 'alert' : 'inline';
    }
}
