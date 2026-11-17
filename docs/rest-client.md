# The REST door, the waiting list and saved data

## The door's shape (../sylloo-erp-docs/erp/rest-door.md)

The office's REST door is on the business's host under `rest/v1`. The office names it in the activation and state
answers (`business.rest_url`, with `business.host_url`: the host of the device's own side, Live or Practice); the base
keeps both (`DeviceState::restUrl()` / `hostUrl()`, forgotten on release) and `RestClient::forDevice($device, $token)`
calls there (null until the office has named one; `RestClient::for($host, …)` still takes a host the app names). The
base knows only the door's shape, never what an action does. Every detail of it is in `Rest\RestWire` and nowhere else:

| | Request | Answer |
|---|---|---|
| A list | `GET rest/v1/lists/<name>?since=<mark>&page=<n>&per_page=<n≤200>&filter[<name>]=<value>` | `{ data: [rows with id], removed: [ids], page, per_page, has_more, server_time, anchor }` |
| A read | `GET rest/v1/reads/<name>` (`home`, `roster`; `RestClient::read()`), no `since`, no pages | `{ data: {…}, server_time, anchor }` |
| An action | `POST rest/v1/actions/<area>/<name>`, body = the fields, header `Idempotency-Key` | `{ data: row or null, server_time, anchor }` |
| An upload | `POST rest/v1/uploads`, multipart with one `file` (picture or PDF, ≤ 10 MB), header `Idempotency-Key` | `{ data: { file_id }, server_time, anchor }` |
| A refusal | | `{ message, code }`; 422 `validation_failed` also has `errors: {field: [text]}` |

- `anchor` = `{at, sig}`: the office's signed time (`RestResult::anchor()`). The app keeps the last one with the
  device's running timer to prove the time of a write made offline (what it proves is the app's business).
- Every call carries `X-Device-Key`, `Authorization: Bearer <the person's token>` and `X-Sylloo-App: <version>`
  (`Erp\ApiService`).
- Every write carries `Idempotency-Key`: 8–64 of `A-Z a-z 0-9 _ - : .` (`RestWire::isKey()`; the client makes a UUID).
  It is only a header, never in the body. A second send with the same key answers the first answer again with
  `X-Rest-Replayed: true`: read as done (`RestResult::$replayed`).
- The app reads `code`, never `message`. It shows the base's text for the code, except for the general codes
  (`refused`, `validation_failed`, `no_permission`, `feature_off`, `not_found`, `forbidden`), where the office's
  `message` says more.

| Code | HTTP | `RestResult` | The waiting list |
|---|---|---|---|
| `validation_failed`, `refused`, `no_permission`, `feature_off`, `not_found`, `key_required`, `key_reused` | 422 / 403 / 404 | `isRefused()` (`isInvalid()` for `validation_failed`) | kept as refused with the reason; the next one is sent |
| `call_in_progress`, `too_many_requests` | 409 / 429 | `triesLater()`, `retryAfter` | keeps waiting; nothing more now; tried again after `Retry-After` (else 30 s), not counted as a try |
| the gate: `session_expired`, `signed_in_elsewhere`, `device_released`, `plan_ended`, `app_too_old`, … | 401 / 403 / 426 | `isGate()` | keeps waiting; `SendReport::$stoppedBy` names the code for the app |
| no answer, 502/503/504 | | `isUnreachable()` | keeps waiting; nothing more now |
| anything else | 5xx | unexpected | tried again later (30 s to 1 h), at most `max_tries`, then refused |

## RestClient

```php
$client = RestClient::for('https://xyz.sylloo.app', $entry->state, $personToken);  // the business's host
$page = $client->list('customers', since: $mark);        // rows(), removed(), hasMore(), serverTime()
$result = $client->run('hr/punch', ['type' => 'in']);     // ok(), replayed, isRefused(), isInvalid(), errors(), text()
$file = $client->upload($path);                           // fileId()
$client->run('hr/punch', ['type' => 'in', 'selfie_file_id' => $file->fileId()]);
```

`RestResult` uses the same outcomes as the device door (`OfficeOutcome`): ok, refused (with a `RefusalCode` when the
base knows it), unreachable, unexpected. A screen never catches a connection error. Since every write carries its key,
a write that timed out or met a server error is tried again like a read (`ApiService::postIdempotent()` /
`upload()`): the office does it once.

## The waiting list

```php
WaitingList::allow('hr/punch', 'hr/visit');              // or config sylloo-app-base.rest.waiting.actions
$list = new WaitingList($entry->id);
$list->add('hr/punch', ['type' => 'in'], files: ['selfie' => $photoPath]);   // offline: kept with a new one-use key
$report = $list->send($client);                          // online: oldest first, each once
```

- Sent in order; a write the office takes (or replays) is removed.
- Files first: each file of a write is uploaded with its own key (`RestWire::fileKey($key, $field)`, the same on every
  send), and its id is put in the fields as `<field>_file_id` at once, so a later send does not upload it again. Then
  the action is sent. A copy of each file is kept encrypted on `rest.waiting.disk` under `rest.waiting.folder` until it
  is uploaded, so the app may delete its own.
- What the office answers decides what happens (table above). A refused write is kept with the code and the office's
  reason; the app shows it; `retry($id)` puts it back in line, `forget($id)` drops it and its files.
- A send-only device (the sign-in ended) still reaches the `mayWait` actions and uploads, so the list can be sent
  after a sign-out (the release frame does so).
- The fields and files are kept encrypted with the app key. Table `sylloo_waiting_writes`.

## Saved data

```php
$saved = new SavedData($entry->id);
$saved->refresh($client, 'customers');                   // changed since the last mark, page by page
$saved->rows('customers'); $saved->savedAt('customers'); // "Saved on 17 Nov, 09:12"
```

Pages are read while `has_more`. The **first** page's `server_time` is kept as the next `since` (a row changed while
the later pages were read comes again rather than never). Rows are kept by `id` (encrypted), removed rows are deleted.
Offline the rows stay. A row that leaves a list without being deleted is not in `removed`: refresh fully now and then
(`clear($list)` then `refresh()`). Tables `sylloo_saved_lists` and `sylloo_saved_rows`.
