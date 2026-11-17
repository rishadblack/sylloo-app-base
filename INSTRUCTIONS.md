# Working on sylloo-app-base

Rules for people and agents changing this package.

1. **No business logic.** The base knows devices, people, sign-in, PIN, lock, states, the office's answers, the
   waiting list and saved data. It never knows sales, punches, leave, products or prices. An app adds those.
2. **Logic once, faces apart.** A screen's logic is a plain class in `src/Screens` (no request, no HTML). The web face
   in `src/Livewire` only draws its `ScreenView` and sends actions. A native face sits on the same class later.
3. **The office's answers are named.** Screens read `OfficeAnswer` / `RestResult` (`ok`, `pending`, `refused` with a
   `RefusalCode`, `unreachable`, `unexpected`), never a raw Response, and never catch connection errors themselves.
4. **The REST wire format lives in `Rest\RestWire` only.** Paths, query names, the one-use key and the answer shape
   change there and nowhere else.
5. **Every text in three languages.** Add a key to `resources/lang/en/device.php`, `bn` and `fi` together; the tests
   fail otherwise. Plain words; the person reading may never have used a computer before.
6. **wire-bootstrap first.** Draw with `<x-wire-bootstrap::…>` tags (the PIN pad and the grouped code field too:
   `pin-pad`, `input.code`). A general UI piece missing there is proposed to the owner for wire-bootstrap first, and
   built in wire-bootstrap once he says yes; never edit wire-bootstrap from here.
7. **Behaviour in Alpine components** registered on `alpine:init` in `resources/js/sylloo-app-base.js`. No inline
   scripts, no jQuery.
8. **Tests for every change**: Pest, a screen with and without a hook added. Use `Testing\PretendOffice` (answers from
   the API collection) and `Testing\TestDevice`. Keep `src/Testing/office-examples.json` equal to the collection.
9. **Checks before handing over**: `composer lint`, `composer analyse` (PHPStan level 6), `composer test`, and
   `npm test` in `electron/` when it changed.
10. Write each technical choice in `docs/decisions.md`; note each change in `changelog.md`.
