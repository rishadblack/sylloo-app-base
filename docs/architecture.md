# Architecture

The base is a Laravel package (PHP 8.3+, Laravel 12/13, Livewire 4) every Sylloo app requires. It holds what the apps
share and no business logic: an app adds its own work (sales, punches, leave…) on top.

```
src/
  Contracts/   what an app binds: DeviceStore, MemberDirectory, DeviceMember, ReleaseSteps, DeviceEntries,
               BrowserOpener, SecretStore, AppUpdater, Notifier
  Support/     AppInfo: name, kind, platform, version, link scheme, one or several businesses
  Erp/         the device door: ApiService (headers, timeouts, retries, breaker), DeviceApi (raw calls, kept for the
               till), OfficeClient + OfficeAnswer + OfficeOutcome + RefusalCode (named results), Refusal (texts)
  Device/      DeviceState (what the office said, which screen applies), Activation, ActivationKey,
               DeviceStateCheck, DeviceTestRunner, StateDefinition + StateRegistry (state screens), DeviceEntry +
               SingleDeviceEntries (one or several businesses)
  SignIn/      BrowserSignIn, SignInRequest, SignedInPerson (member or employee)
  Pin/         PinSignIn (PIN rules, offline days, five wrong), Presence (who is in, the idle lock)
  Release/     DeviceRelease (the release frame: send what waits → remove → tell the office)
  Update/      AppUpdateService (desktop updater notices), ReleaseSigning (release:sign-update / sign-config)
  Config/      RemoteConfig (the signed remote config)
  Images/      ImageCache (business logos and photos kept on the device)
  Rest/        RestClient, RestWire (the wire format), RestResult, WaitingList, WaitingWrite, SendReport, SavedData
  Window/      WindowRules (the business window: ticket address, allowed paths, ports, keep-alive, quiet
               re-sign-in), ConnectionProblems + ConnectionCheck (offline bar, a page that can not load, retry)
  Screens/     the shared screens' logic (Screen, ScreenState, ScreenView, Field, Button, Hooks, Question,
               Handover, ScreenRoutes, and one class per screen)
  Livewire/    the web faces (ScreenPage, ScreenModal, one class per screen, Modals/)
  Http/        RequireDeviceReady (sylloo.device), UseChosenLanguage (sylloo.language)
  Language/    Language (the chosen language of the install)
  Events/      DeviceActivated, PersonSignedIn, DeviceLocked, SignInEnded, DeviceReleased, PinSaved, WindowLoadFailed
  Console/     InstallCommand, Installer, SignUpdateCommand, SignConfigCommand
  Testing/     PretendOffice, OfficeExamples (+ office-examples.json), TestDevice, RecordingBrowser, in-memory stores,
               WindowCases (resources/testing/window-cases.json), FakeWindowConnection
config/        erp.php (office address, offline days, image hosts, remote config) and sylloo-app-base.php
database/      the waiting-list and saved-data tables
resources/     views (layout, screen body, components), lang (en, bn, fi), scss, js, stubs, boost
routes/        the web faces' routes (/device/…)
electron/      the Electron parts the desktop apps copy in (print bridge, tray, update signature, install key)
```

## One business or several

The screens never reach a store directly: they ask `Contracts\DeviceEntries` for the current `DeviceEntry` (its id,
`DeviceState`, `MemberDirectory`, `ReleaseSteps`). A one-business app (the till) binds the three contracts and gets
`SingleDeviceEntries`; the mobile app binds its own `DeviceEntries` that holds a staff and an employee activation.
Waiting lists and saved data are kept per entry (`scope` = the entry id).

## A screen

```
face (Livewire / native)          logic (Screens\XScreen)               office
mount ─────────────────────────▶ start(input) → ScreenState
render ◀──────────────────────── view(state)  → ScreenView
run('activate') ───────────────▶ act(state, 'activate', fields) ──────▶ OfficeClient → OfficeAnswer
                ◀─────────────── state: errors, problem, go, open, toast, question
```

The face keeps `ScreenState` between requests (locked against the browser in Livewire). Secrets (a sign-in request's
verifier) stay in the session, never in the state. What one screen hands to the next goes through `Handover` (session).

## Hooks

`Screens\Hooks` (singleton): pieces shown at named places of a screen and questions asked at moments before a step.
`Device\StateRegistry` (singleton): the state screens, the base's and the app's. Events tell an app what happened.
