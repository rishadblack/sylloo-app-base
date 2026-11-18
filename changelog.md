# Changelog

All notable changes to `rishadblack/sylloo-app-base` are documented in this file.

## 1.2.0 - 2026-11-18

### This device and "Use another activation code" (owner, 2026-11-18)

- Activate opened again on an activated device shows "This device": the business, LIVE / PRACTICE, the device's name
  and kind, with "Back" and "Use another activation code" (Practice to Live, or another business). It always warns
  first (`use_another_warning`).
- "Use another code" (also on the confirm card right after activating) now runs the full release,
  `DeviceRelease::deactivate()` as "Deactivate this device": the office frees the place, everybody is signed out, what
  waits is sent, everything of the activation is removed, then Activate asks for the new code. Offline nothing changes
  (`use_another_offline`). Before, it only told the office and forgot the key, leaving members, PINs and data behind.
- A releasing device opening Activate always goes to its Releasing screen.
- Sign in has a quiet "This device" button (action `this_device`; config `screens.sign_in.this_device`, on by
  default), so the card opens without signing in. In an app with `when_activated` = `leave` the card opens through
  it (`Handover::THIS_DEVICE`) or `?details=1`.
- New texts (en, bn, fi): `this_device_title`, `this_device_text`, `back`, `use_another_activation_code`,
  `use_another_warning`, `use_another_offline`.

### Business key (owner, 2026-11-18)

- `DeviceState::businessKey()`: the business's fixed key the ERP sends (`business.key` in `activate` and `GET state`),
  kept under `business_key` (forgotten with the activation). The same on Live and Practice and whatever host the
  device uses; the tills' phone scanner pairing compares it (remote-scanner.md §5.1). An older ERP sends none: the
  kept key stays, null before any.

### Activation QR code (owner, 2026-11-18)

- `Activation::codeFromScan($text, $scheme)`: what a scanned QR holds, `code` and `link` (true for the office's
  activation link, so the app may activate at once). `codeFromLink()` also reads `Activation::QR_SCHEME`
  (`sylloo-app`, the scheme the ERP and the portal always draw, also for a till whose own scheme is `sylloo-pos`) and
  a code with dashes or spaces. Digits are no longer pulled out of any text: a web address with 16 digits in it is not
  a code.

### Phone SMS screens (phone-messaging-plan.md SM2)

- Two optional shared screens, written once for both apps, texts in English, Bangla and Finnish:
  `Screens\MessagesAcceptScreen` (`messages_accept`, route `sylloo.messages-accept`: Android's SMS permission, one
  sentence naming the SIM and who pays, a SIM to choose on a two-SIM phone, Allow / Refuse sent as
  `POST messages/pairing` with the member's token; moment `messages_accept` for an app's approval) and
  `Screens\MessagesScreen` (`messages`, route `sylloo.messages`: waiting, sent today of the daily limit, failed today,
  the SIM, Pause / Resume, what is missing: internet, signal, permission). Web faces `Livewire\MessagesAccept` and
  `Livewire\Messages`.
- `Contracts\MessagingPhone` (the phone's side, bound by the phone package), `Messaging\PhoneMessaging`
  (`enabled`, `keepGoing`, `startPhone`, `needsAnswer`, `menuEntry`), `DeviceApi::messages()`,
  `messagesPairing()`, `messagesPause()` and the same on `OfficeClient`.
- Config `messages.enabled` (env `SYLLOO_PHONE_SMS`, default false); `ScreenRoutes::OPTIONAL` draws the two routes only
  when it is on.
- `Testing\FakeMessagingPhone`.

## 1.1.0 - 2026-11-18

### The business window (from Sylloo App's desktop D2)

- `Window\WindowRules`: an address with a port is refused by `mayShow()` and `isTicketUrl()`, unless the office
  address (`erp.base_url`) carries that same port (a local office such as `https://sylloo.test:8443`); the scheme's own
  port (`:443`) counts as no port. `mayShow()` also refuses a password in the address. `isTicketUrl()` wants exactly the
  entrance path: `/app/session/` is refused. Both now match the desktop app's Electron `decide()` /
  `isSessionTicketUrl()`.
- The shared window cases ship in the release: `resources/testing/window-cases.json` (38 window cases, 13 ticket
  addresses, the hosts, the paths and the office address), read through `Testing\WindowCases` (`path()`, `cases()`,
  `tickets()`, `hosts()`, `allowedPaths()`, `useOffice()`), with the base's Pest test `WindowCasesTest`. An app's own
  filter (Electron, Kotlin) reads the same file.
- Connection problems of the business window: `Window\ConnectionProblems` (`check()` on the page's beat: the thin
  "No internet" bar, a failed page hidden and named as the internet's or Sylloo's problem, tried again once online;
  `tryAgain()`: the same page again, a one-use ticket never twice), `Window\ConnectionCheck`, the contract
  `Contracts\WindowConnection` (the app's window: `connection`, `hide`, `retry`, `close`), the event
  `Events\WindowLoadFailed` (an app extends it to receive it from its window), the components
  `<x-sylloo-app-base::window.offline-bar>` and `<x-sylloo-app-base::window.problem>`, config
  `window.status_page`, texts `window_offline_bar`, `window_load_failed_offline`, `window_load_failed_office`,
  `window_tries_again` in English, Bangla and Finnish, and `Testing\FakeWindowConnection`.

### Release signing (from Sylloo App)

- `php artisan release:sign-update` (signs latest.yml: `syllooSignature` over "<version>|<sha512>") and
  `php artisan release:sign-config` (signs the remote config file, raises `serial`, checks it as an installed app
  would), for any app: `Update\ReleaseSigning` and config `release` (`key_path`, `public_key`, `public_key_file`,
  `latest_yml`, `commands`). An app that still keeps its own copies sets `release.commands` false.

### For the Android app (sylloo-erp-mobile-app)

- `Hooks::afterPinSaved()`: after a new PIN is saved and the person is in, the app may send them elsewhere first
  (the "Use fingerprint next time?" offer); `Hooks::pinSavedGo()`.
- `PinScreen::enterVerified()`: go in from the PIN pad after the app verified the person another way (a fingerprint);
  not an action, so a page can never call it.
- `FirstScreen::screenFirst()`: the chosen language, then the first screen; the `sylloo.device` middleware uses it and
  sylloo-app-native's `NeedsReadyDevice` / `ReadyDevice` can call it.
- `Rest\WaitingList` keeps files of fields inside a list (`items.0.receipt`, sent as `items[0][receipt_file_id]`);
  `WaitingList::FILE_FIELD`.
- The signed remote config takes `release.android {url, sha256}` (https, 64 hex digits) and gives it with
  `RemoteConfig::androidRelease()` (sha256 in lower case) for the in-app APK update.
- `SavedData::refresh()`: a test proves a removed row is removed only from its own activation (`scope`); the reported
  bug did not reproduce, because every query of SavedData already starts from the scoped table.

### Fixed

- The test of the release archive expected `/electron/tests` to be export-ignored, which 1.0.1 undid on purpose; it
  now checks that the shared Electron tests and the window cases ship.

## 1.0.1 - 2026-11-18

- The release now ships `electron/tests`: apps copy them with `shared:sync` and their `shared:check` refuses to build
  without them.

## 1.0.0 - 2026-11-17

### On wire-bootstrap's code field and PIN pad

- The shared screens draw the grouped code field with `<x-wire-bootstrap::input.code formatted>` and the PIN pad with
  `<x-wire-bootstrap::pin-pad min="4" max="6" global-keys>`, sending the pad's action on its `wb-pin-submit` event
  (wire-bootstrap 1.1.0); the member's photo is drawn above the pad (`.sylloo-pin`, `.sylloo-pin-photo`).
- Removed the base's own copies (the `wire-bootstrap-candidate` views, Alpine `syllooCodeField` / `syllooPinPad`, the
  `.sylloo-code-*` / `.sylloo-pin-*` pad styles, `docs/wire-bootstrap-candidates.md`). An app loads wire-bootstrap's
  browser layer and styles `.wb-code-*` / `.wb-pin-*`.
- Requires `rishadblack/wire-bootstrap` `^1.1`.
- Licence: MIT.
- The API collection test finds the collection relative to the package (override: `SYLLOO_API_COLLECTION`).

### The till on the shared screens (P1 part 3)

- Config `screens.activate.when_activated` (`confirm` | `leave`), `screens.activate.device_name_required`,
  `screens.sign_in.device_limit` (`problem` | `list`: step `limit` on Sign in), `screens.state.release_on_open`,
  `screens.state.still_blocked`, `screens.pin.no_pin` (`create` | `refuse`), `screens.pin.forgot_starts_browser`;
  `screens.business_header` also shows the device and LIVE / PRACTICE on Sign in and PIN.
- Hook steps `activate.continue`, `use_another_code`, `sign_in.pick`, `sign_in.kept`; `Hooks::goOnRefusal()` /
  `refusalGo()`; a question's `onConfirm` / `onCancel` may return a toast (`Hooks::answer()` returns it).
- State button `try_again`; "Renew plan" without an address shows `renew_no_link`; "Check again" may say
  `still_blocked`.
- Sign in: `:host` in the waiting text, `browser_url` in the waiting context, two initials on tiles
  (`SignInScreen::initialsOf()`), `SignInScreen::isWebPage()` (an office address that is not a web page is never
  opened), `sign_in_unreachable` when the office can not be reached.
- `Refusal::text()` and `OfficeAnswer::text()` take extra replacements (`:allowed`); `Handover::NEW_PIN` ("Set a new
  PIN") and `Handover::START_BROWSER`.
- Texts `device_name_required`, `sign_in_unreachable`, `new_pin_title`, `pin_none`, `still_blocked`, `renew_no_link` in
  English, Bangla and Finnish.

### One first-screen decision

- `Screens\FirstScreen::name()` (`activate`, `state`, `sign_in`, `lock` or null): the `sylloo.device` middleware and
  sylloo-app-native's `ReadyDevice` both ask it.

### Behaviour hooks (the till, P1 part 2)

- `Contracts\ScreenNotices` with `Screens\Notices\AlertScreenNotices` (default) and `EventScreenNotices` (`notify`,
  `confirm-dialog`); config `screens.notices`.
- `Hooks::payload()`, `check()` / `problemAt()`, `step()` / `runSteps()` (`activate.before_keep`, `activate.after_keep`,
  `state.check`, `sign_in.link`, `refused`), `refused()`, `sentences()`, `finish()` (`signed_in`).
- Config `texts.refusals`, `screens.activate.ask_use_another_code`, `screens.state.unreachable`, `screens.date_format`,
  `screens.business_header`, `screens.pin.create_here`, `screens.classes.buttons.<action>`, `screens.loading.<action>`,
  `screens.views.<screen>`.
- `Livewire\SignIn::openedFromUrl()` (NativePHP `OpenedFromURL`); `StateScreen::formatDate()`; `ScreenView::$header`.

### Employee door (collection M1)

- `DeviceState::restUrl()` / `hostUrl()` from `business.rest_url` / `host_url`; `RestClient::forDevice()`.
- `RestClient::read()` (`rest/v1/reads/<name>`), `RestWire::readPath()`, `RestWire::anchor()`, `RestResult::anchor()`.
- `office-examples.json` from the M1 collection (33 examples, door `rest`); `OfficeExamples::restAnswer()`.
- `PretendOffice` answers the collection's REST examples by default; `read()`, `stamp()` (server_time and anchor).

### Apps with their own look (the till)

- Config `screens.layout`, `screens.classes`, `screens.questions` (`inline` / `alert`), `screens.activate.device_name`,
  `icons.set` / `icons.map`, `app.device_word`, `texts.locale`, `routes.screens`.
- Activate: "Name this device" field; the all-devices-in-use step lists the devices; questions with `detail` and
  `lines`, inline or as livewire-alert.
- `Hooks::lines()` (counted lines at a place), `Screen::placeLines()`, `Screens\Lines`, `Screens\Look`,
  `Support\Icons`, `Support\Texts` (the device word and its case forms; app overrides by vendor lang file).
- `Contracts\LockSource` with `Pin\PresenceLockSource` (default) and `Pin\SessionKeyLockSource`.
- `AppUpdateService` takes NativePHP event objects; `versionOf()`.
- Electron: `appLabel` optional; `.gitattributes` export-ignore and `files` keep node_modules and tests out.

### REST door alignment

- `Rest\RestWire` follows the built door (../sylloo-erp-docs/erp/rest-door.md): prefix `rest/v1`, the one-use key only
  in the `Idempotency-Key` header (8–64 of `A-Z a-z 0-9 _ - : .`), list answers `{data, removed, page, per_page,
  has_more, server_time}`, `X-Rest-Replayed: true` read as done.
- `RestClient::for()` takes the business host; `upload()` / `uploadContents()` keep a file and answer `fileId()`.
- `RestResult`: `hasMore()`, `serverTime()`, `fileId()`, `replayed`, `retryAfter`, `triesLater()`, `isGate()`
  (replaces `lastPage()` / `since()`).
- `RefusalCode`: `no_permission`, `feature_off`, `key_required`, `key_reused`, `call_in_progress`, `too_many_requests`,
  with texts in English, Bangla and Finnish.
- `WaitingList`: files of a waiting write (uploaded first, sent as `<field>_file_id`); `call_in_progress` /
  `too_many_requests` and gate codes keep writes waiting; `SendReport::$stoppedBy`. Migration: `files` column.
- `SavedData` pages while `has_more` and keeps the first page's `server_time` as the next `since`.
- `PretendOffice`: `rest()` answers `rest/v1/…` calls (optional headers), `uploads()`.
- The update notices of `Update\AppUpdateService` are texts (`device.update_*`) in English, Bangla and Finnish.

### Changed

- Renamed from `rishadblack/sylloo-desktop-base` (namespace `Rishadblack\SyllooDesktopBase`) to
  `rishadblack/sylloo-app-base` (namespace `Rishadblack\SyllooAppBase`): the base now serves the desktop and the mobile
  apps.
- Texts moved to the base's own namespace `sylloo-app-base::device.*` (English, Bangla, Finnish).
- The app version is read through `Support\AppInfo::version()` (config `sylloo-app-base.app.version`, else
  `nativephp.version`).
- `DeviceState` knows the device kind (`pos`, `app`, `employee`) and the `employee_app_off` block; app states come
  from `Device\StateRegistry`.
- `DeviceRelease` takes the entry id and fires `Events\DeviceReleased`.

### Added

- Office calls as named results: `Erp\OfficeClient`, `Erp\OfficeAnswer`, `Erp\OfficeOutcome`, `Erp\RefusalCode`.
- Sign in for a member and for an employee: `SignIn\SignedInPerson`; `MemberDirectory::keepSignIn()` and `member()`.
- REST door: `Rest\RestClient`, `Rest\RestWire` (the wire format in one class), `Rest\RestResult`.
- The waiting list for offline writes (`Rest\WaitingList`) and the saved-data frame (`Rest\SavedData`), with their
  migration.
- Business window rules: `Window\WindowRules`.
- Several businesses: `Contracts\DeviceEntries`, `Device\DeviceEntry`, `Device\SingleDeviceEntries`.
- The idle lock: `Pin\Presence`.
- Shared screens as plain logic (`Screens\*Screen`) with Livewire web faces (`Livewire\*`), the layout with the three
  steps and LIVE / PRACTICE, About and Language modals (wire-modals), `sylloo.device` and `sylloo.language` middleware.
- Hooks (`Screens\Hooks`: pieces and questions), extra states, events (`DeviceActivated`, `PersonSignedIn`,
  `DeviceLocked`, `SignInEnded`, `DeviceReleased`, `PinSaved`).
- Contracts `BrowserOpener`, `SecretStore`.
- PIN pad and grouped code field (now wire-bootstrap's own, see above).
- Test helpers: `Testing\PretendOffice`, `Testing\OfficeExamples`, `Testing\TestDevice`, `Testing\RecordingBrowser`
  and the in-memory stores.
- `php artisan sylloo-app-base:install`, Laravel Boost guideline and skill.
