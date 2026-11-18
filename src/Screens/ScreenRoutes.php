<?php

namespace Rishadblack\SyllooAppBase\Screens;

use Illuminate\Support\Facades\Route;
use Rishadblack\SyllooAppBase\Http\Middleware\UseChosenLanguage;
use Rishadblack\SyllooAppBase\Livewire;

/**
 * Where each shared screen lives in the web face, and where `home` (after sign-in: config
 * `sylloo-app-base.routes.home`, a route name or a path) and `back` lead.
 *
 * Every screen's path and route name can be changed (config `sylloo-app-base.routes.screens.<screen>`: `path`, `name`;
 * `false` leaves the screen out), so an app keeps its addresses (the till: `/activate`, `/login`). A screen the app
 * draws itself is left out of the base's routes but keeps its `name`: the base's redirects then go to the app's route.
 */
final class ScreenRoutes
{
    /** The base's route names (the defaults). */
    public const NAMES = [
        'activate' => 'sylloo.activate',
        'sign_in' => 'sylloo.sign-in',
        'create_pin' => 'sylloo.create-pin',
        'pin' => 'sylloo.pin',
        'lock' => 'sylloo.lock',
        'state' => 'sylloo.state',
        'businesses' => 'sylloo.businesses',
        'pin_settings' => 'sylloo.pin-settings',
        'about' => 'sylloo.about',
        'language' => 'sylloo.language',
        'messages_accept' => 'sylloo.messages-accept',
        'messages' => 'sylloo.messages',
    ];

    /** The base's paths, under `routes.prefix` (the defaults). */
    public const PATHS = [
        'activate' => 'activate',
        'sign_in' => 'sign-in',
        'create_pin' => 'create-pin',
        'pin' => 'pin',
        'lock' => 'lock',
        'state' => 'state',
        'businesses' => 'businesses',
        'pin_settings' => 'settings/pin',
        'about' => 'about',
        'language' => 'language',
        'messages_accept' => 'messages/accept',
        'messages' => 'messages',
    ];

    /** The web face of each screen. */
    public const COMPONENTS = [
        'activate' => Livewire\Activate::class,
        'sign_in' => Livewire\SignIn::class,
        'create_pin' => Livewire\CreatePin::class,
        'pin' => Livewire\Pin::class,
        'lock' => Livewire\Lock::class,
        'state' => Livewire\State::class,
        'businesses' => Livewire\Businesses::class,
        'pin_settings' => Livewire\PinSettings::class,
        'about' => Livewire\About::class,
        'language' => Livewire\Language::class,
        'messages_accept' => Livewire\MessagesAccept::class,
        'messages' => Livewire\Messages::class,
    ];

    /** Screens drawn only when the app turns their feature on (phone SMS: config `messages.enabled`). */
    public const OPTIONAL = [
        'messages_accept' => 'sylloo-app-base.messages.enabled',
        'messages' => 'sylloo-app-base.messages.enabled',
    ];

    /**
     * Each screen with its path, route name and web face; a screen the app left out has `drawn` false.
     *
     * @return array<string, array{path: string, name: string, component: class-string, drawn: bool}>
     */
    public static function screens(): array
    {
        $screens = [];

        foreach (self::COMPONENTS as $screen => $component) {
            $config = config('sylloo-app-base.routes.screens.'.$screen);
            $set = is_array($config) ? $config : [];
            $custom = $set['component'] ?? null;

            $screens[$screen] = [
                'path' => trim(is_string($set['path'] ?? null) ? $set['path'] : self::PATHS[$screen], '/'),
                'name' => is_string($set['name'] ?? null) && $set['name'] !== '' ? $set['name'] : self::NAMES[$screen],
                'component' => is_string($custom) && class_exists($custom) ? $custom : $component,
                'drawn' => $config !== false && ($set['enabled'] ?? true) !== false
                    && (! isset(self::OPTIONAL[$screen]) || config(self::OPTIONAL[$screen], false) === true),
            ];
        }

        return $screens;
    }

    /**
     * The route name of a screen.
     */
    public static function name(string $screen): ?string
    {
        return self::screens()[$screen]['name'] ?? null;
    }

    /**
     * Register the web faces' routes (the base's routes/web.php calls it when `routes.enabled`).
     */
    public static function register(): void
    {
        Route::middleware([...(array) config('sylloo-app-base.routes.middleware', ['web']), UseChosenLanguage::class])
            ->prefix((string) config('sylloo-app-base.routes.prefix', 'device'))
            ->group(function (): void {
                foreach (self::screens() as $screen) {
                    if ($screen['drawn']) {
                        Route::get($screen['path'], $screen['component'])->name($screen['name']);
                    }
                }
            });
    }

    public static function url(string $screen): string
    {
        if ($screen === 'home') {
            $home = (string) config('sylloo-app-base.routes.home', '/');

            return Route::has($home) ? route($home) : url($home);
        }

        if ($screen === 'back') {
            return url()->previous(url('/'));
        }

        // An address of the app's own (a finish hook's "go to /terminal").
        if (str_starts_with($screen, '/')) {
            return url($screen);
        }

        $name = self::name($screen);

        return $name !== null && Route::has($name) ? route($name) : url('/');
    }
}
