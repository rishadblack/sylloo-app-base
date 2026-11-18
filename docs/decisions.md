# Technical decisions

Choices made while building 1.0.0 (2026-11-17), with the reason. A business question is not decided here; those are
listed at the end for the owner.

1. **Two config keys.** `erp` (office address, offline days, image hosts, remote config) stays as the till reads it;
   `sylloo-app-base` holds the app's shape (name, kind, platform, version, link scheme, one or several businesses),
   PIN and lock, routes, layout, languages, REST and window settings.
2. **Texts in the base's namespace** (`sylloo-app-base::device.*`). An app changes a text by publishing
   (`lang/vendor/sylloo-app-base`). Wording is neutral ("device", `:app`), not "terminal" or "Sylloo POS".
3. **Named results.** `OfficeAnswer` and `RestResult` share `OfficeOutcome` (ok, pending, refused, unreachable,
   unexpected) and `RefusalCode`. `DeviceApi` still returns raw Responses so the till keeps working unchanged.
4. **Refusal texts**: the base's sentence for a known code, else the office's message. For the general codes
   (`refused`, `not_found`, `forbidden`, `validation_failed`) the office's message wins: it says more.
5. **REST wire format in one class** (`RestWire`), aligned with the built door (../sylloo-erp-docs/erp/rest-door.md):
   prefix `rest/v1` on the business host; `GET lists/<name>?since&page&per_page&filter[]` answering
   `{data, removed, page, per_page, has_more, server_time}`; `POST actions/<area>/<name>`; `POST uploads` (multipart
   `file` → `{data: {file_id}}`); refusals `{message, code}` and 422 `errors`. The one-use key is only the
   `Idempotency-Key` header; `X-Rest-Replayed: true` is read as done. The first page's `server_time` is the next
   `since`. Keyed writes are retried on a timeout like reads, since the office does a key once.
   - `call_in_progress` and `too_many_requests` keep a waiting write waiting (after `Retry-After`, else 30 s) and do not
     count as a try; gate codes (`session_expired`, `device_released`, `app_too_old`, …) also keep it waiting and the
     send report names the code; every other refusal keeps the write as refused.
   - Waiting files: kept encrypted on a disk (`rest.waiting.disk/folder`), uploaded before the action with a key made
     from the write's key and the field, the id put in `<field>_file_id` at once (one upload even across sends).
6. **Waiting list and saved data in database tables** (one migration, `loadMigrationsFrom`, can be turned off and
   published), fields and rows encrypted with the app key, one scope per activation. A refused write is never dropped
   by the base. An unexpected answer is retried with backoff up to `max_tries`, then kept as refused.
7. **One logic, many faces.** Each screen is a plain class (`Screens\*Screen`): `start()`, `view()`, `act()`. It never
   reads a request or draws HTML; it returns a `ScreenView` (title, text, step, fields, buttons, notices, card, rows,
   poll, hook places) and asks the face to go/open/toast/ask through `ScreenState`. Only the actions the current view
   offers may run. The web face is one generic Livewire class per screen with a single Blade body; the state is
   `#[Locked]`.
8. **Secrets never in the page.** The sign-in request's verifier stays in the session; screens hand over through
   `Handover` (session); Livewire state holds only plain values.
9. **Questions.** A screen asks before a risky step: the app's question at that moment (hook) or the base's own (use
   another code, deactivate, sign out). Questions are drawn inline in the screen (same on a native face), not as a
   SweetAlert: livewire-alert is used for short toasts only.
10. **wire-modals** for About and Language from the layout's footer (`sylloo-app-base.about`,
    `sylloo-app-base.language`), reusing the same screen logic; they are also full pages.
11. **Presence and the idle lock in the session** of the app's own window (who is in, locked, last seen). The idle time
    is per activation in the device store (`idle_minutes`), default from config; 0 = never.
12. **"Switch person" on the lock keeps the person's sign-in on the device** (their PIN works next time); "Sign out"
    ends it at the office.
13. **PIN length 4–6**, as `PinSignIn::problemWith()` and the office already say. The PIN pad submits on OK/Enter, and by
    itself at 6 digits.
14. **Language per install**, kept in a small file on the local disk (`sylloo-app-base/language`), chosen before or
    after activation; `sylloo.language` applies it to the app's own pages.
15. **The browser.** Screens ask the face to open an address; the web face uses the app's `BrowserOpener` when bound
    (desktop: system browser), else `window.open` (developers in a browser).
16. **Icons**: the views name Bootstrap Icons and draw them through `Support\Icons::of()`: `icons.set` bootstrap (`bi bi-*`, the app installs `bootstrap-icons`) or tabler (a fixed name map, `ti ti-*`), and `icons.map` for single icons. No icon font is a hard dependency.
17. **AppInfo::version()** reads `sylloo-app-base.app.version`, else `nativephp.version` (desktop and mobile NativePHP).
18. **Examples for tests** are copied from the API collection into `src/Testing/office-examples.json` (24 requests:
    10 device door, 14 employee business door), with a test that compares them to the collection when it is present.
    Member answers are made from the employee examples (`member` instead of `employee`, no `code`).
19. **ImageCache URL**: the app's `erp_image` route when it has one (the till), else the public disk URL.
20. **Larastan boots the package** through `testbench.yaml`, so package views and macros are known at analysis.
21. **Electron parts** (`electron/`) are kept as they were, with their Vitest tests; only the comments and the error
    text name the new package. The manifest name `.desktop-base.json`, the `@desktop-base` alias and the npm package
    name stay, so the till's copy step keeps working.
22. **Additions for apps with their own look** (the till): all are config or hooks with defaults that keep the old
    behaviour (layout, classes, icons, questions inline, device name on, word `device`, routes and paths as before,
    the presence lock). English sentences render exactly as before with the word `device`.
23. **The device word is a text parameter**, not separate sentences: `Support\Texts` adds `:app`, `:device`,
    `:devices` and the language's case forms (`words` in each `device.php`; a form a language does not name falls back to
    the plain word). Overrides use Laravel's vendor lang folder, merged per key.
24. **Questions as livewire-alert** are shown once, when the question opens (not on every poll), and answered through
    `answerQuestion`; the inline card stays the default.
25. **The idle lock is a contract** (`LockSource`) so the till keeps its Auth-based lock and session key; Presence
    still fires `DeviceLocked` only when someone is in.
26. **`AppUpdateService` takes NativePHP event objects** (`version`, `info.version`, `message`, `name`) as well as
    strings; the till's overrides of `text()` and `productName()` keep their signatures.
27. **Electron releases**: `.gitattributes export-ignore` and `files` in `electron/package.json` keep `node_modules`
    and the tests out of a dist. An app's release build installs a tagged or mirrored copy of the base (composer
    `dist`, or a path repo with `"symlink": false`), never the symlink of the working folder; `appLabel` may be left
    out (SYLLOO_APP_LABEL, else the Electron app's / package.json name, else `sylloo-app`).
28. **The REST door's address comes from the office** (`business.rest_url` / `host_url` of the activation and state
    answers, kept only when https (http outside production), forgotten on release). `RestClient::for($host)` stays for
    apps and tests that name a host.
29. **`office-examples.json` is the collection's M1 `Employee mode`**: 10 device-door and 23 REST-door examples (door
    `rest`, path after `rest/v1/`). PretendOffice answers the REST examples by default; a call with several examples
    (the punches) answers the first by name. An example whose JSON does not read is left out (Home, for now).
30. **Behaviour hooks for the till** (P1 part 2): the look waits for P2 (the till moves to wire-bootstrap), the behaviour
    is hooks with today's defaults: `ScreenNotices` (alert | events), `Hooks::payload/check/step/refused/sentences/finish`,
    config `texts.refusals`, `screens.state.unreachable`, `screens.date_format` (default `d M Y`: only app sentences use
    `:date`), `screens.business_header`, `screens.pin.create_here`, `screens.activate.ask_use_another_code`, per-button
    classes and loading texts, and a body view per screen.
31. **An activation answer is checked before anything is kept** (`Activation::isComplete()`, then the app's
    `activate.answer` check); the app's steps run in the flow, `before_keep` before and `after_keep` after
    `rememberActivation()`, both before `DeviceActivated`. The app's payload fields never replace the base's.
32. **A finish hook replaces the base's end of a sign-in** (presence, `PersonSignedIn`, `home`) as a whole, so an app
    with its own login does not end up with two sign-ins.
33. **The till's last screen differences are switches and hooks, never copies** (P1 part 3): every addition is off by
    default (`when_activated` = `confirm`, `device_limit` = `problem`, `no_pin` = `create`, …), so the desktop and
    mobile apps see no change. A refusal's destination is the app's (`goOnRefusal`), because only the app knows its
    release and its state screens; the base keeps the refusal texts and the `refused` steps.
34. **A browser address is opened only when it is a web page** (https; http outside production) and the request has an
    id: an office answer can never make the device open `javascript:` or a file. This holds for every app (it is a
    check, not a switch).
35. **`no_pin` = `refuse` also keeps the pad when the PIN screen opens**: a person without a PIN here is not asked to
    create one before the browser sign-in (the till's rule: the PIN hash comes from the office), they are told so when
    they type.
36. **The code field and the PIN pad are wire-bootstrap's** (1.1.0: `input.code`, `pin-pad`); the base keeps no copy.
    The code field is bound `formatted` (the screens keep `1234-5678-9012-3456`); 4 × 4 digits and `-` are
    wire-bootstrap's defaults but are passed explicitly, so an app's wire-bootstrap config can not change the activation
    code. The pad is `min` 4 / `max` 6 and `global-keys` (typing works anywhere on the page, as before). It sends the
    action with `x-on:wb-pin-submit="$wire.run('<action>')"` on the pad, not wire-bootstrap's `submit="method"`: that
    one calls a method without arguments, and every action of a shared screen stays a visible `run('<action>')` in the
    markup (sylloo-app-native's one-logic-two-faces tests read the web face's actions from it). The member's photo is
    drawn by the base above the pad (`.sylloo-pin-photo`); wire-bootstrap 1.2's `avatar` is not used, so the base
    needs only `^1.1`.
37. **Licence MIT** (owner's decision, before the base is published as 1.0.0).
38. **A port only when the office has it** (1.1.0, owner's yes 2026-11-18): the window and the ticket refuse an address
    with a port, as the desktop app's Electron filter does; the one exception is the port of the office address
    (`erp.base_url`), so a local office on `https://sylloo.test:8443` still works in development. It is the office's
    port, not a list per host: the business hosts come from that office. The scheme's own port (`:443`) is no port
    (a browser drops it). The ticket path is exact (`/app/session/` refused): the ticket is the window's only door.
39. **The window cases ship in `resources/testing/`**, not `tests/` (which is export-ignored): every app's filter
    reads the same file from the installed package; a base test checks no export-ignore line covers it.
40. **Connection problems are plain logic over a contract** (`WindowConnection`): the base decides (bar, failed page,
    retry, never a ticket twice), the app's window answers (Electron, a phone's web view). The event is a plain class
    an app extends, because receiving it from Electron needs NativePHP's `DispatchableFromNative`, which the base does
    not require. The Electron side (`did-fail-load`, keeping the failed address) stays the app's, with its window.
41. **Release signing commands are the base's, registered by default** (`release.commands`): the desktop app and the
    till keep their copies until they switch, then delete them; an app keeping a copy sets the switch false so two
    commands never share a name. The public key comes from config first, else from the app's `updateSignature.ts`.
42. **A verified fingerprint enters through `PinScreen::enterVerified()`, never an action**: the screen's actions come
    from the page (`run('<action>')`), so a page could send any of them; a method only the app's code calls after the
    Keystore prompt said yes keeps the PIN the only door a page has. The offline days and the office's check still
    hold. The fingerprint offer after Create PIN is a "where next" hook (`afterPinSaved`), asked only when the person
    went in the usual way.
43. **`release.android` may be on any https host** (GitHub's release files are not on the office's domains): the signed
    `sha256` of the APK is what makes it safe, and NativePHP's `Update.Install` checks it before installing.
