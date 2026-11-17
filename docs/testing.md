# Testing an app on the base

The helpers live in `src/Testing` so an app's tests use them too.

```php
use Rishadblack\SyllooAppBase\Testing\PretendOffice;
use Rishadblack\SyllooAppBase\Testing\RecordingBrowser;
use Rishadblack\SyllooAppBase\Testing\TestDevice;

beforeEach(function () {
    Sleep::fake();                         // no real waiting between retries
    $this->office = PretendOffice::start(); // answers like the API collection's examples
    $this->browser = RecordingBrowser::bind();
    $this->device = TestDevice::bind();     // activated: "XYZ Trading", Live
});

it('signs a member in', function () {
    $this->office->asMember()->with('sign-in/finish', ['pin_set' => true, 'pin_hash' => Hash::make('2468')]);

    Livewire::test(SignIn::class)->call('run', 'browser')->call('run', 'poll')->assertRedirect(url('/'));
});
```

- `PretendOffice`: `answer($call, $body, $status)`, `with($call, $changes)` (over the example), `refuse($call, $code,
  $status)`, `pending()`, `down()`, `asMember()`, `rest($path, $body, $status)` for a REST door call (the collection's REST examples answer by default: `reads/roster`,
  `lists/attendance`, `actions/attendance/punch`, `uploads`, …; several examples of one call: the first by name),
  `read($name, $data)` (`{data, server_time, anchor}`), `PretendOffice::stamp()`,
  `assertCalled($call, $check)`, `assertNotCalled($call)`, `example($call)`.
- `OfficeExamples`: the example answers of `sylloo-erp-api-collection/Employee mode`, kept in
  `src/Testing/office-examples.json`. The base's test `OfficeClientTest` compares them with the collection when it is on
  the computer (`SYLLOO_API_COLLECTION` names another folder); regenerate the file from `OfficeExamples::fromCollection()`
  when the collection changes.
- `TestDevice`: `bind($values, activated: false)`, `addMember($key, $name, $pin, $offline)`, `enter($member)`.
- `ArrayDeviceStore`, `ArrayMemberDirectory`, `FakeMember`, `FakeReleaseSteps`: in-memory stores.

The base's own suite: `composer test` (Pest), `composer analyse` (PHPStan level 6), `composer lint` (Pint), and
`npm test` in `electron/` (Vitest).
