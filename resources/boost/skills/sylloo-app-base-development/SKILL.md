---
name: sylloo-app-base-development
description: Use this skill when building or changing a Sylloo app on rishadblack/sylloo-app-base (Sylloo POS, the Sylloo App desktop or Android). Trigger when binding Contracts (DeviceStore, MemberDirectory, ReleaseSteps, DeviceEntries, BrowserOpener), calling the office with OfficeClient / OfficeAnswer / RefusalCode, sign in with browser, PIN and the idle lock (PinSignIn, Presence), device states (DeviceState, StateRegistry, StateDefinition), activation or release (Activation, DeviceRelease), the REST door (RestClient, RestWire, RestResult), the waiting list or saved data, the business window rules (WindowRules), the shared screens and their hooks (Screens\Hooks, Question, the sylloo.* routes, the sylloo.device middleware), the texts sylloo-app-base::device.*, the PIN pad or code field, or testing with PretendOffice / TestDevice.
---

# Sylloo App Base Development

The base holds everything the Sylloo apps share and no business: the app adds its own work on top. Change the base
only in the base's own repository; in an app, extend it.

## Binding the app

```php
// app/Providers/SyllooAppServiceProvider.php
public function register(): void
{
    $this->app->singleton(DeviceStore::class, SettingsDeviceStore::class);
    $this->app->singleton(MemberDirectory::class, UserMemberDirectory::class);
    $this->app->singleton(ReleaseSteps::class, AppReleaseSteps::class);
    $this->app->singleton(BrowserOpener::class, NativeBrowserOpener::class);
}
```

A multi-business app (Android: staff and employee) binds `DeviceEntries` instead and sets
`sylloo-app-base.app.multi_business`. Every screen works on `app(DeviceEntries::class)->current()`.

## Calling the office

```php
$answer = $entry->office($token)->me();

if ($answer->isUnreachable()) { /* offline */ }
if ($answer->isRefused() && $answer->refusal()?->endsSignIn()) { /* sign in again */ }
$text = $answer->text();   // a plain sentence in the person's language
```

## The REST door

```php
$client = RestClient::forDevice($entry->state, $token);   // the activation's business.rest_url (null until named)
$home = $client->read('home');                          // rest/v1/reads/home: {data, server_time, anchor}
$result = $client->run('hr/punch', ['type' => 'in']);             // Idempotency-Key header made for you

if ($result->isUnreachable() && WaitingList::mayWait('hr/punch')) {
    (new WaitingList($entry->id))->add('hr/punch', ['type' => 'in'], files: ['selfie' => $photoPath]);
}
```

A file goes up first with `$client->upload($path)->fileId()`, then into the action as `<field>_file_id`; a waiting
write does this by itself. Read `code` (`$result->refusal()`), never `message`; show `$result->text()`.
`triesLater()` (`call_in_progress`, `too_many_requests`) and `isGate()` (`session_expired`, `device_released`, …) are
not refusals of the write. Lists: page while `hasMore()`, keep the first page's `serverTime()` as the next `since`
(`SavedData` does it).

Send what waits when online: `(new WaitingList($entry->id))->send($client)` (`SendReport::$stoppedBy` names a gate code). Show refused writes
(`->refused()`, each with `reason`) and let the person `retry($id)` or `forget($id)`.

## Adding to a shared screen

```php
// boot()
app(Hooks::class)->add('state.plan_ended', 'pos::hooks.sales-safe');      // view gets the screen's context
app(Hooks::class)->ask('activate', 'reset', fn (array $c) => $needsReset ? new Question(
    __('pos.reset_title'), __('pos.reset_text'), __('pos.reset_yes'), __('pos.reset_no'), true,
    onConfirm: fn () => app(ResetTill::class)->run(),
) : null);
app(StateRegistry::class)->register(
    new StateDefinition('almost_ready', 'pos.almost_ready_title', 'pos.almost_ready_text', 'pos.almost_ready_todo', 'bi bi-shop'),
    fn (DeviceState $device) => ! $device->get('outlet_ready'),
);
Event::listen(PersonSignedIn::class, StartSync::class);
```

Hook places and moments of every screen are listed in the base's `docs/screens.md`. Never copy a screen's view or
logic into the app.

An app with its own look (the till) uses config and hooks only (`docs/screens.md` "Fitting an app"):

```php
// config/sylloo-app-base.php
'app' => ['device_word' => 'terminal'],
'routes' => ['enabled' => true, 'prefix' => '', 'screens' => ['activate' => ['path' => 'activate', 'name' => 'activate'], 'sign_in' => false]],
'screens' => ['layout' => 'layouts.till', 'classes' => ['*' => ['buttons' => 'd-grid gap-2']], 'questions' => 'alert'],
'icons' => ['set' => 'tabler'],

// boot()
app(Hooks::class)->lines('state.releasing', fn (array $c) => [['text' => __('pos.refused'), 'count' => $refused, 'variant' => 'danger']]);
app(Hooks::class)->ask('activate', 'reset', fn (array $c) => new Question(…, detail: trans_choice('pos.sales_safe', $n), lines: […]));
$this->app->bind(LockSource::class, fn () => new SessionKeyLockSource('till_locked_at', fn () => IdleLock::minutes()));
```

## Texts and UI

- `Support\Texts::get('<key>')` (or `__('sylloo-app-base::device.<key>')` with `Texts::parameters()`); the device is
  `:device` / `:Device` / `:devices` in every sentence. Change one text in the app's
  `lang/vendor/sylloo-app-base/<locale>/device.php` (only the changed keys), or publish `--tag=sylloo-app-base-lang`.
- Icons through `Support\Icons::of('arrow-right')` (Bootstrap names; the app's `icons.set` / `icons.map` decide the class).
- wire-bootstrap tags for every field and button; PIN pad and code field are wire-bootstrap's (1.1+):
  `<x-wire-bootstrap::pin-pad wire:model="pin" :min="4" :max="6" global-keys x-on:wb-pin-submit="$wire.run('enter')" />`
  (or `submit="method"` for a Livewire method without arguments),
  `<x-wire-bootstrap::input.code wire:model="code" formatted />` (4 × 4 digits, `1234-5678-9012-3456`). The app loads
  wire-bootstrap's browser layer (`resources/js/wire-bootstrap.js`; `wbCode`, `wbPinPad`).
- Behaviour in Alpine components on `alpine:init`. No inline scripts, no jQuery.

## Testing

```php
beforeEach(function () {
    Sleep::fake();
    $this->office = PretendOffice::start();
    $this->device = TestDevice::bind();
});

it('shows the sales that are safe when the plan ended', function () {
    TestDevice::bind(['plan' => ['active' => false, 'ends_on' => '2026-10-01']]);

    Livewire::test(\Rishadblack\SyllooAppBase\Livewire\State::class)->assertSee('sales are safe');
});

it('refuses an employee who is not allowed', function () {
    $this->office->refuse('sign-in/finish', 'employee_not_allowed');

    Livewire::test(\Rishadblack\SyllooAppBase\Livewire\SignIn::class)
        ->call('run', 'browser')->call('run', 'poll')
        ->assertSee('You may not use the Employee app.');
});
```
