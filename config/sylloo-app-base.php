<?php

/*
 * rishadblack/sylloo-app-base: what an app tells the shared base about itself (plan §4.4 "Config"). The office address,
 * the offline days, the image hosts and the signed remote config stay under the `erp` key (config/erp.php), which the
 * till already reads.
 */
return [

    /*
     * The app. `kind` is what it sends to `POST activate` (`pos` or `app`; the office decides `employee` from the code).
     * `platform` null = from the system (windows, macos, linux); a phone passes `android`. `version` null =
     * config('nativephp.version'). `link_scheme` is the deep-link scheme (`sylloo-app://signed-in`,
     * `sylloo-app://activate?code=…`).
     */
    'app' => [
        'name' => null,
        'kind' => 'app',
        'platform' => null,
        'version' => null,
        'link_scheme' => 'sylloo-app',
        'multi_business' => false,
        // The device name sent at activation; null = the computer's name (a phone passes its model).
        'device_name' => null,
        // Where "Renew plan" and help links open when the office sent no address.
        'portal_url' => 'https://sylloo.eu',
        // The app's word for itself in the texts (:device): device, terminal (the till) or phone; see texts `words`.
        'device_word' => 'device',
    ],

    /*
     * Texts. Every app says the same thing (the base's texts in English, Bangla and Finnish); an app changes any text in
     * lang/vendor/sylloo-app-base/<lang>/device.php (only the keys it names). `locale`: the language of the office-side
     * texts (update notices, the device test); null = the app's language.
     */
    'texts' => [
        'locale' => null,
        // The app's own texts for refusal codes: a prefix ('device.refused_': its device.refused_<code>, when that key
        // exists) or a map [code => translation key]. null = the base's texts.
        'refusals' => null,
    ],

    /*
     * The states the app knows besides the shared ones (an app also registers its own state screens in code,
     * Device\StateRegistry). A shared state not listed here still shows; this list only adds names for the state route.
     */
    'states' => [],

    /*
     * PIN and lock. `offline` false: every PIN sign-in asks the office first (no offline days).
     * `idle_minutes` is the default; the person may change it in PIN and lock settings (`idle_choices`).
     */
    'pin' => [
        'offline' => true,
        'idle_minutes' => 5,
        'idle_choices' => [1, 2, 5, 10, 15, 30],
    ],

    /*
     * The web faces' routes (Livewire full pages). `home` is where a person lands after sign-in: a route name or a path.
     * `screens`: per screen (activate, sign_in, create_pin, pin, lock, state, businesses, pin_settings, about,
     * language) its `path` and route `name` (defaults /device/<screen>, sylloo.<screen>), or false to draw it yourself
     * (the base's redirects still use its `name`). An app with its own pages sets `enabled` false (no route is added),
     * or keeps its addresses:
     *
     *     'prefix' => '', 'screens' => ['activate' => ['path' => 'activate', 'name' => 'activate'], 'sign_in' => ['path' => 'login', 'name' => 'login']],
     */
    'routes' => [
        'enabled' => true,
        'prefix' => 'device',
        'middleware' => ['web'],
        'home' => '/',
        'screens' => [],
    ],

    /*
     * The look of the shared screens' web faces. `layout`: the layout they render in (null = `layout` below, the base's
     * own); an app's layout gets `$slot`, `$screen` and `$step`. `classes`: extra CSS classes per part (screen, icon,
     * header, title, text, todo, notice, question, card, rows, lines, fields, buttons, button), for every screen (`*`) or
     * one (`activate`, …), and per button (`buttons` => [action => classes]). `questions`: `inline` (a card in the
     * screen) or `alert` (a dialog, see `notices`). `activate.device_name`:
     * Activate asks "Name this device".
     */
    'screens' => [
        'layout' => null,
        'classes' => [
            '*' => [],
            'buttons' => [],
        ],
        'questions' => 'inline',
        // How toasts and dialog questions are shown: alert (livewire-alert) or events (browser events `notify` and
        // `confirm-dialog`, for an app that draws its own; payload in Screens\Notices\EventScreenNotices). An app may
        // also bind its own Contracts\ScreenNotices.
        'notices' => 'alert',
        // The text a button shows while its action runs, by action (a text or a translation key): ['activate' => 'Activating…'].
        'loading' => [],
        // The view that draws a screen's body instead of the base's, by screen: ['state' => 'pos::device.state-body'].
        'views' => [],
        // How dates (:date) are written: PHP date letters in the app's language.
        'date_format' => 'd M Y',
        // The business (logo and name) above the screen: false, true, or a list of screens (['state', 'sign_in', 'pin',
        // 'create_pin']); the sign-in screens also show the device's name and LIVE / PRACTICE.
        'business_header' => false,
        'activate' => [
            'device_name' => true,
            // An empty device name is refused ("Give this device a name") instead of taking the default name.
            'device_name_required' => false,
            // "Use another code" asks first (the base's question) when the app asks nothing at that moment.
            'ask_use_another_code' => true,
            // An activated device opening Activate: confirm (its confirm card) or leave (on to Sign in, or to the state
            // screen when it is released or blocked).
            'when_activated' => 'confirm',
        ],
        'sign_in' => [
            // The plan's devices are all in use at sign-in: problem (one line) or list (the devices in use, step limit).
            'device_limit' => 'problem',
        ],
        'state' => [
            // A check without an answer: toast (a short message) or screen (the "Can't reach Sylloo" state screen).
            'unreachable' => 'toast',
            // Releasing runs its first release step as the screen opens (a device with nothing waiting is clean at once).
            'release_on_open' => false,
            // "Check again" that changed nothing says "Nothing changed yet".
            'still_blocked' => false,
        ],
        'pin' => [
            // The PIN screen asks for a new PIN itself (no separate Create PIN screen).
            'create_here' => false,
            // A person without a PIN here: create (choose one now) or refuse ("Sign in with your browser").
            'no_pin' => 'create',
            // "Forgot PIN?" starts the browser sign-in at once (instead of showing Sign in first).
            'forgot_starts_browser' => false,
        ],
    ],

    /*
     * Icons. The screens name Bootstrap Icons; `set` draws them as `bootstrap` (bi bi-<name>, needs bootstrap-icons) or
     * `tabler` (ti ti-<name>, needs @tabler/icons-webfont). `map`: a name → the app's own class (`'lock' => 'ti ti-lock'`).
     */
    'icons' => [
        'set' => 'bootstrap',
        'map' => [],
    ],

    /*
     * The base's layout of the shared screens (logo, the three steps, LIVE / PRACTICE, the practice bar, online mark);
     * `screens.layout` wins when set.
     */
    'layout' => 'sylloo-app-base::layouts.device',

    /*
     * The languages of the language choice (all three have every text of the base).
     */
    'languages' => [
        'en' => 'English',
        'bn' => 'বাংলা',
        'fi' => 'Suomi',
    ],

    /*
     * The REST client (plan §8.5). `waiting.actions`: the actions (`<area>/<name>`) a write made offline may wait for.
     * `waiting.disk` / `waiting.folder`: where the files of a waiting write (a selfie, a receipt) are kept, encrypted,
     * until they are uploaded. `saved.lists`: the lists kept on the device. Tables are made by the base's migration.
     */
    'rest' => [
        'timeout' => 15,
        'page_size' => 200,
        'waiting' => [
            'actions' => [],
            'max_tries' => 10,
            'table' => 'sylloo_waiting_writes',
            'disk' => 'local',
            'folder' => 'sylloo-waiting',
        ],
        'saved' => [
            'lists' => [],
            'lists_table' => 'sylloo_saved_lists',
            'rows_table' => 'sylloo_saved_rows',
        ],
        'connection' => null,
    ],

    /*
     * The business window (desktop app, mobile app): a quiet new ticket at most this many times a minute, and a
     * keep-alive every this many seconds.
     */
    'window' => [
        'quiet_sign_ins_per_minute' => 3,
        'keep_alive_seconds' => 240,
        // Connection problems (base 1.1.0, Window\ConnectionProblems): where "Sylloo is not answering" points to.
        'status_page' => 'status.sylloo.app',
    ],

    /*
     * Release signing (base 1.1.0): `php artisan release:sign-update` (latest.yml) and `release:sign-config` (the
     * remote config file). `key_path` null = SYLLOO_UPDATE_KEY_PATH, else ~/.sylloo/update-signing.key (never in a
     * repository). `public_key` = the update key built into the app; null = read from `public_key_file` (null = the
     * NativePHP Electron plugin's `src/server/updateSignature.ts`, `export const UPDATE_PUBLIC_KEY = '…'`).
     * `latest_yml` null = the Electron build's `dist/latest.yml`. `commands` false while an app keeps its own copies.
     */
    'release' => [
        'commands' => true,
        'key_path' => env('SYLLOO_UPDATE_KEY_PATH'),
        'public_key' => null,
        'public_key_file' => null,
        'latest_yml' => null,
    ],

    /*
     * Run the base's migrations (waiting list, saved data) with the app's. An existing app that does not use them (or
     * publishes them: --tag=sylloo-app-base-migrations) sets false.
     */
    'migrations' => true,
];
