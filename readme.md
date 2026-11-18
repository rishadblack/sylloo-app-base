# Sylloo App Base

`rishadblack/sylloo-app-base` is the shared base of the Sylloo apps: Sylloo POS (the till), the Sylloo App (desktop)
and the Sylloo App for Android. It holds everything the apps share and nothing of any business:

- the office calls of a device (activate, state, sign in with browser, PIN, me, sign out, deactivate, released, push
  token) as named results, with the "office is down" breaker;
- a REST client for the office's REST door (`rest/v1`: lists, actions and uploads in one shape, one-use keys), the one
  waiting list for writes made offline (with their photos) and the saved-data frame;
- the device state, activation, release frame, PIN and idle lock, updates and the signed remote config;
- the shared screens, each written once as plain logic, with web faces in Livewire 4 and wire-bootstrap: Activate,
  Sign in, Create PIN, PIN pad, Lock, the state screens, Businesses, PIN and lock settings, About, Language;
- texts in English, Bangla and Finnish;
- test helpers (a pretend office answering with the API collection's examples) and Laravel Boost guidelines.

Each app adds its own storage, its own screens and its own work.

## Install

```bash
composer require rishadblack/sylloo-app-base
php artisan wire-bootstrap:install     # once, if the app does not load wire-bootstrap yet
php artisan sylloo-app-base:install
npm install bootstrap-icons sweetalert2 && npm run build
php artisan migrate
```

The PIN pad and the grouped code field are wire-bootstrap's own (`<x-wire-bootstrap::pin-pad>`,
`<x-wire-bootstrap::input.code>`, 1.1 or newer), so the app loads wire-bootstrap's browser layer
(`vendor/rishadblack/wire-bootstrap/resources/js/wire-bootstrap.js`, which `wire-bootstrap:install` imports) and
styles them with wire-bootstrap's theme layer or its own CSS on the `.wb-code-*` / `.wb-pin-*` classes.

Then bind the app's storage in a service provider (see `resources/stubs/SyllooAppServiceProvider.stub`):

```php
$this->app->singleton(DeviceStore::class, MyDeviceStore::class);       // key/value of the activation
$this->app->singleton(MemberDirectory::class, MyMemberDirectory::class); // the people signed in here
$this->app->singleton(ReleaseSteps::class, MyReleaseSteps::class);     // what a release removes
$this->app->singleton(BrowserOpener::class, NativeBrowserOpener::class); // optional: opens the system browser
```

Put the `sylloo.device` middleware on your own pages. The shared screens live under `/device/…` (route names
`sylloo.activate`, `sylloo.sign-in`, `sylloo.create-pin`, `sylloo.pin`, `sylloo.lock`, `sylloo.state`,
`sylloo.businesses`, `sylloo.pin-settings`, `sylloo.about`, `sylloo.language`). Phone SMS adds
`sylloo.messages-accept` and `sylloo.messages` when config `messages.enabled` is on (`docs/screens.md`, Phone SMS).

## Add your part without copying a screen

```php
app(Hooks::class)->add('state.plan_ended', 'pos::hooks.sales-safe');            // a piece at a place
app(Hooks::class)->ask('activate', 'reset', fn (array $context) => $question);  // a question before a step
app(StateRegistry::class)->register(new StateDefinition('almost_ready', …), fn (DeviceState $d) => …); // a state
Event::listen(PersonSignedIn::class, …);                                        // what happened
```

## The business window

`Window\WindowRules` decides which ticket address may open the window and what it may show (no other port than the
office's, exactly `/app/session`). The cases every side must agree on ship in `resources/testing/window-cases.json`
(`Testing\WindowCases::path()` for a test outside PHP). `Window\ConnectionProblems` handles a page that can not load:
bind `Contracts\WindowConnection` to your window, call `check()` on the page's beat and `tryAgain()` from "Try again",
and draw `<x-sylloo-app-base::window.offline-bar :offline="$offline" />` and
`<x-sylloo-app-base::window.problem :status="$status" :message="$message" />`.

## Release signing

`php artisan release:sign-update [latest.yml]` and `php artisan release:sign-config <file>` sign a release and the
remote config with the release key (`SYLLOO_UPDATE_KEY_PATH`, else `~/.sylloo/update-signing.key`; never in a
repository). The public key the app carries comes from config `sylloo-app-base.release.public_key` or the Electron
`updateSignature.ts`.

## Documentation

- `docs/architecture.md`: the areas and how they fit
- `docs/screens.md`: each screen, its steps, actions, hook places and moments
- `docs/rest-client.md`: the REST door, the waiting list and saved data
- `docs/testing.md`: PretendOffice, TestDevice and the other helpers
- `docs/decisions.md`: the technical choices and why

## Checks

```bash
composer test       # Pest
composer analyse    # PHPStan level 6 (Larastan)
composer lint       # Pint
cd electron && npm test   # the Electron parts (Vitest)
```

## Licence

MIT. See `license.md`.
