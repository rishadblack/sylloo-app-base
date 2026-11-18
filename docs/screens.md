# The shared screens

Each screen is one logic class (`src/Screens`) and one web face (`src/Livewire`). The web face draws every screen with
`resources/views/partials/screen-body.blade.php` inside the layout `resources/views/layouts/device.blade.php`.

| Screen | Route name | Logic | Steps | Actions | Hook places | Moments |
|---|---|---|---|---|---|---|
| Activate (step 1) | `sylloo.activate` | `ActivateScreen` | `code`, `confirm`, `limit` | `activate`, `continue`, `use_another_code`, `try_again`, `update_now` | `activate.top`, `activate.below_code`, `activate.confirm_card`, `activate.bottom` | `activate`, `use_another_code` |
| Sign in (step 2) | `sylloo.sign-in` | `SignInScreen` | `choose`, `waiting` | `pick` (tile), `browser`, `poll`, `open_again`, `cancel` | `sign_in.top`, `sign_in.below_tiles`, `sign_in.waiting`, `sign_in.bottom` | |
| Create PIN (step 3) | `sylloo.create-pin` | `CreatePinScreen` | `pin` | `save` | `create_pin.bottom` | |
| PIN pad (step 3) | `sylloo.pin` | `PinScreen` | `pad` | `enter` (pad), `forgot`, `someone_else` | `pin.below_pad` | |
| Lock | `sylloo.lock` | `LockScreen` | `pad` | `enter`, `switch_person`, `sign_out` | `lock.below_pad` | `sign_out` |
| State screens | `sylloo.state` | `StateScreen` | the state's name | `check_again`, `renew_plan`, `update_now`, `sign_out`, `poll` (releasing) | `state.top`, `state.<name>`, `state.bottom` | |
| Businesses | `sylloo.businesses` | `BusinessesScreen` | `list` | `pick`, `add` (multi-business apps) | `businesses.bottom` | |
| PIN and lock settings | `sylloo.pin-settings` | `PinSettingsScreen` | `settings` | `change_pin`, `save_idle`, `lock_now`, `deactivate` | `pin_settings.bottom` | `deactivate` |
| About | `sylloo.about`, modal `sylloo-app-base.about` | `AboutScreen` | `about` | `update_now` (with an updater) | `about.rows` | |
| Language | `sylloo.language`, modal `sylloo-app-base.language` | `LanguageScreen` | `choose` | `save` | | |

The layout offers `layout.header` and `layout.footer`.

## Flow

```
Activate ──(code)──▶ confirm card ──Continue──▶ Sign in ──browser──▶ waiting ──Allow──▶ PIN known? ──▶ home
                                                  │                                      └─ no ──▶ Create PIN ──▶ home
                                                  └─ tap a name ──▶ PIN pad ──▶ home
home ──idle time / Lock now──▶ Lock ──PIN──▶ home
any page (sylloo.device) ──device not ready──▶ Activate / state screen / Sign in / Lock
```

- **Offline days**: within them the PIN is checked on the device (works offline); after them the office confirms the
  person first (`GET me`), then the offline days start again. Config `sylloo-app-base.pin.offline` false: always ask.
- **Five wrong PINs** in a row end the person's sign-in on the device (the office is told when it can be reached).
- **Forgot PIN**: sign in with the browser, then Create PIN (even when a PIN is known).
- **Use another code / Deactivate**: asks first (the base's question, or the app's at that moment), then the office
  frees the seat and the release frame runs on the Releasing state screen.
- **State screens** come from `Device\StateRegistry`: the base's (releasing, locked, business paused, plan ended,
  update needed, employee app off, unreachable) and the app's own, checked in that order.

## Adding your part

```php
// A piece at a place: a view (gets the screen's context) or a closure returning text/HTML.
app(Hooks::class)->add('activate.below_code', 'mobile::hooks.scan-qr');
app(Hooks::class)->add('state.plan_ended', fn (array $context) => new HtmlString(view('pos::sales-safe')));

// A question before a step; yes runs onConfirm then the step, no runs onCancel and nothing is sent.
app(Hooks::class)->ask('activate', 'reset', fn (array $context) => $hasOtherData
    ? new Question(__('pos.reset_title'), __('pos.reset_text'), __('pos.reset_yes'), __('pos.reset_no'), true, fn () => $reset())
    : null);

// A state of the app, after the base's.
app(StateRegistry::class)->register(new StateDefinition('waiting_for_hr', 'mobile.hr_title', 'mobile.hr_text'),
    fn (DeviceState $device) => $device->get('hr_approved') === false);
```

The context every hook gets: `screen`, `step`, `entry`, `business`, `device_name`, `mode`, `kind`, `app`, `data`.

## A native face

A native face (Android, later) creates the logic class from the container, keeps `ScreenState::toArray()`, draws
`ScreenView::toArray()` and calls `act()` with the person's action. It does what the state asks: `go` (a screen name of
`ScreenRoutes::NAMES`, `home` or `back`), `open` (the browser), `toast`, `question` (answer with `answer_yes` /
`answer_no`), and runs `poll` every `poll` seconds.

## Fitting an app that has its own look (the till)

Everything below is optional; without it the screens look and speak as before.

| Need | How |
|---|---|
| Its own layout | config `screens.layout` = a view; it gets `$slot`, `$screen`, `$step` (`Screens\Look::layout()`). |
| Its own classes | config `screens.classes` = `['*' => ['title' => 'display-6'], 'activate' => ['buttons' => 'd-grid']]`; parts: `screen`, `icon`, `title`, `text`, `todo`, `notice`, `question`, `card`, `rows`, `lines`, `fields`, `buttons`, `button`. |
| Its own icons | config `icons.set` = `tabler` (`ti ti-*`, Bootstrap names are translated by `Support\Icons::TABLER`), and/or `icons.map` = `['lock' => 'my my-lock']`. With a set other than `bootstrap` the app does not need `bootstrap-icons`. |
| Questions as livewire-alert | config `screens.questions` = `alert` (default `inline`); the alert calls `answerQuestion(['yes' => bool])`. |
| A question with counts | `new Question(…, detail: '14 sales are safe on this terminal', lines: [['text' => …, 'count' => 14, 'variant' => 'success']])`. |
| Counted lines on a screen | `app(Hooks::class)->lines('state.releasing', fn (array $context) => [['text' => 'Refused', 'count' => 1, 'variant' => 'danger'], ['text' => 'A shift is still open']])` at any hook place; a native face reads them with `Screen::placeLines($state)`. Line: `text`, `count`, `hint`, `variant` (default `secondary`), `icon`. |
| Device name on Activate | config `screens.activate.device_name` (default true): the field "Name this :device", filled with `app.device_name` / the computer's name, at most 60 characters, sent as `name`. |
| All devices in use | the `limit` step lists the office's `devices` (name, last used, by whom) and "Devices in use (n of allowed)"; place `activate.limit` for the app's own lines. |
| Its own word | config `app.device_word` = `device`, `terminal` or `phone` (or the app's own word, below). The texts use `:device` / `:Device` / `:devices` (and in Bangla and Finnish the case forms `:device_gen`, `:device_ill`, …), filled by `Support\Texts`. |
| Change a text | the app's `lang/vendor/sylloo-app-base/<locale>/device.php` with only the keys it changes (Laravel merges per key). A new word: `'words' => ['till' => ['device' => 'till', 'devices' => 'tills']]` there, then `app.device_word` = `till`. Keep the shared sentences where you can (plan §4.2). |
| Office texts in one language | config `texts.locale` (null = the app's locale): the update notices and the connection-test notices. |
| Its own addresses | config `routes.prefix` and `routes.screens.<screen>` = `['path' => 'activate', 'name' => 'activate']`; `false` or `'enabled' => false` leaves a screen out (the app draws it; `ScreenRoutes::url()` then uses the route with that name). Screens: `ScreenRoutes::NAMES`. |
| Without the base's routes or tables | config `routes.enabled` false and `migrations` false (an app with its own `/activate`, `/login`, … and its own device table). |
| Its own idle lock | bind `Contracts\LockSource`, e.g. `new Pin\SessionKeyLockSource('till_locked_at', fn () => (int) Setting::get('idle_lock_minutes', 10))`; Presence, the lock screen and the middleware then use it. Default `Pin\PresenceLockSource`. |

## Fitting an app that has its own flow (the till's behaviour)

Each hook is optional; without it the screen behaves as before. Register them in a service provider's `boot()` on
`app(Screens\Hooks::class)`.

| Need | How |
|---|---|
| Its own notices | config `screens.notices` = `events`: toasts dispatch `notify` `{type, title, message}`, dialog questions (`screens.questions` = `alert`) dispatch `confirm-dialog` `{componentId, title, message, detail, lines, method: 'answerQuestion', params: [{yes: true}], confirmLabel, cancelLabel, danger, cancelMethod: 'answerQuestion', cancelParams: [{yes: false}], icon}`. Or bind `Contracts\ScreenNotices`. Default `alert` (livewire-alert). |
| Extra fields at activation | `->payload('activate', fn (array $context) => ['pending_orders' => 3])` (the base's fields win). |
| Refuse an activation answer | `->check('activate.answer', fn (array $answer, array $context) => isset($answer['terminal']['desktop_pos_id']) ? null : __('pos.no_terminal'))`: nothing is kept, the text shows. An answer without `device_key` / `mode` is never kept. |
| Work around keeping it | `->step('activate.before_keep', fn (array $answer, array $context) => …)` (accepted, nothing kept yet: the till's wipe) and `->step('activate.after_keep', …)` (kept, before `DeviceActivated`: terminal fields, workers, profile, reply note). |
| "Use another code" without the base's question | config `screens.activate.ask_use_another_code` false (an app question at `use_another_code` still asks). |
| Its own refusal texts | config `texts.refusals` = `'device.refused_'` (its `device.refused_<code>` when that key exists) or a map `[code => key]`. |
| Hear every refusal | `->refused(fn (array $refusal, array $context) => SyncErrorLog::add(…))`; `$refusal` = `call`, `code`, `status`, `message`, `answer` (Activate, Sign in, PIN). |
| "Can't reach Sylloo" as a screen | config `screens.state.unreachable` = `screen` (default `toast`). |
| Its checks on "Check again" / poll | `->step('state.check', fn (array $context) => app(Heartbeat::class)->run())`, before the base reads the state again. |
| Dates | config `screens.date_format` (PHP date letters, default `d M Y`, in the app's language; a closure formats it itself); `StateScreen::formatDate()`. |
| Its sentences per state | `->sentences('plan_ended', fn (array $context) => $context['date'] ? ['text' => …, 'todo' => null] : 'The plan ended.')`; the context adds `plan`, `date` (formatted), `device_state`, `replace`. |
| The business above the screen | config `screens.business_header` = true or `['state']` (logo and name; part `header` for classes). |
| A deep link on Sign in | NativePHP's `OpenedFromURL` reaches `Livewire\SignIn::openedFromUrl()`; `->step('sign_in.link', fn (string $url, array $context) => …)` hears every link; `<scheme>://signed-in` asks the office at once. |
| Its own sign-in finish | `->finish('signed_in', fn (DeviceMember $member, bool $offline, ?SignedInPerson $person, array $context) => '/terminal')`: the app logs in, starts its session and workers, and returns where to go (a screen name, `home`, an address starting with `/`, or null to stay). The base's presence and `PersonSignedIn` are then the app's job. |
| Create the PIN on the PIN screen | config `screens.pin.create_here` true: step `create` of the PIN screen (no Create PIN screen). |
| One button's look | config `screens.classes.buttons.<action>` (classes) and `screens.loading.<action>` (its text while it runs). |
| Its own body of a screen | config `screens.views.<screen>` = a view; it gets `$screen`, `$view` (ScreenView), `$question`, `$context`, and calls `run('<action>')`. |
| An activated device leaves Activate | config `screens.activate.when_activated` = `leave` (default `confirm`): an activated device goes to Sign in, a releasing one (or one with a state screen) to its state screen. |
| A name must be typed | config `screens.activate.device_name_required` true: an empty name shows `device_name_required` instead of taking the default name. |
| Work on Continue / "Use another code" | `->step('activate.continue', fn (array $context) => …)` (before Sign in opens: the till's "Terminal activated" notice) and `->step('use_another_code', fn (array $context) => …)` (after the office's deactivate, before the activation is forgotten: the till's `released`). |
| A toast after an answer | a `Question`'s `onConfirm` / `onCancel` may return a text or `['text' => …, 'variant' => …]`: the screen shows it as a toast (the till's "Nothing changed" after "No"). |
| Where a refusal goes | `->goOnRefusal(fn (array $refusal, array $context) => 'state')` (a screen name, `home`, a `/path`, or null for the base's own handling); runs on Activate, Sign in, PIN (`call` = `me`) and Create PIN, after the `refused` steps. |
| Somewhere first after a new PIN (1.1.0) | `->afterPinSaved(fn (DeviceMember $member, array $context) => '/fingerprint-offer')` (a screen name or an app address, null = home): asked once the new PIN is saved and the person went in the usual way (not after a `signed_in` finisher chose a place). |
| A fingerprint instead of the PIN (1.1.0) | the app's own code, after its prompt said yes: `app(PinScreen::class)->enterVerified($state)` (pad step only; valid sign-in, a PIN here and the office's offline rule still needed). Not an action, so no page can call it. |
| The plan's devices at sign in | config `screens.sign_in.device_limit` = `list` (default `problem`): step `limit` lists the devices in use, "Try again" goes back. Refusal texts get `:allowed` (`Refusal::text($answer, $replace)`, `OfficeAnswer::text($replace)`). |
| Releasing cleans at once | config `screens.state.release_on_open` true: the first release step runs when Releasing opens (the base's otherwise waits for the poll). Button `try_again` on a state: on Releasing the next release step, elsewhere "Check again". |
| "Nothing changed yet" | config `screens.state.still_blocked` true: a toast when the office answered a check and the state stayed. "Renew plan" without any address says where to renew (`renew_no_link`). |
| Business, device and mode above Sign in / PIN | config `screens.business_header` = `['state', 'sign_in', 'pin']`: screens other than `state` also show the device's name and LIVE / PRACTICE. Name tiles show two initials. |
| Work on a tap and on a kept browser sign-in | `->step('sign_in.pick', fn (DeviceMember $member, array $context) => …)` (the till asks the office for the PIN hash; the member is read again after it) and `->step('sign_in.kept', fn (DeviceMember $member, SignedInPerson $person, array $context) => …)` (the till pulls its staff list). The context of the waiting step has `browser_url` (the till's "Copy the link"); its text gets `:host`. |
| "Forgot PIN?" starts the browser at once | config `screens.pin.forgot_starts_browser` true: Sign in opens already waiting; the PIN chosen after it is titled `new_pin_title` ("Set a new PIN"). |
| No PIN here: browser first | config `screens.pin.no_pin` = `refuse` (default `create`): the pad stays, and a PIN typed by a person without one here shows `pin_none`. |

Sign in also checks the office's answer to `sign-in/start` itself: a missing request id, or a browser address that is not
a web page (https; http outside production), shows `unexpected` and opens nothing; no answer at all shows
`sign_in_unreachable`. A finished sign-in answered OK but without a person shows `unexpected`.
