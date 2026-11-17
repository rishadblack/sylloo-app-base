<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Erp\RefusalCode;
use Rishadblack\SyllooAppBase\Rest\RestClient;
use Rishadblack\SyllooAppBase\Rest\RestWire;
use Rishadblack\SyllooAppBase\Rest\SavedData;
use Rishadblack\SyllooAppBase\Rest\WaitingList;
use Rishadblack\SyllooAppBase\Rest\WaitingWrite;
use Rishadblack\SyllooAppBase\Support\AppInfo;
use Rishadblack\SyllooAppBase\Testing\PretendOffice;

beforeEach(function () {
    Sleep::fake();
    Storage::fake('local');
    $this->office = PretendOffice::start();
    $this->device = activatedDevice();
    $this->client = RestClient::for('https://xyz.sylloo.app', $this->device, 'member-token');
});

/**
 * A small picture on the disk, as the camera leaves it.
 */
function aSelfie(string $contents = 'jpeg-bytes'): string
{
    $path = tempnam(sys_get_temp_dir(), 'selfie').'.jpg';
    file_put_contents($path, $contents);

    return $path;
}

describe('the wire format', function () {
    it('names actions, lists and uploads under rest/v1', function () {
        expect(RestWire::actionPath('hr/punch'))->toBe('rest/v1/actions/hr/punch')
            ->and(RestWire::isActionName('hr/punch'))->toBeTrue()
            ->and(RestWire::isActionName('../punch'))->toBeFalse()
            ->and(RestWire::listPath('customers'))->toBe('rest/v1/lists/customers')
            ->and(RestWire::uploadPath())->toBe('rest/v1/uploads')
            ->and(RestWire::listQuery('2026-11-01T00:00:00Z', 2, 50, ['outlet' => 3]))
            ->toBe(['since' => '2026-11-01T00:00:00Z', 'page' => 2, 'per_page' => 50, 'filter' => ['outlet' => 3]])
            ->and(RestWire::listQuery(null, 1, 500)['per_page'])->toBe(200);
    });

    it('rejects a list name that is not one', function () {
        RestWire::listPath('customers/../x');
    })->throws(InvalidArgumentException::class);

    it('takes only the keys the office takes', function () {
        expect(RestWire::isKey(RestWire::newKey()))->toBeTrue()
            ->and(RestWire::isKey('punch:2026-11-17.1'))->toBeTrue()
            ->and(RestWire::isKey('short'))->toBeFalse()
            ->and(RestWire::isKey(str_repeat('a', 65)))->toBeFalse()
            ->and(RestWire::isKey('has space 123'))->toBeFalse()
            ->and(RestWire::isKey(RestWire::fileKey(RestWire::newKey(), 'selfie')))->toBeTrue()
            ->and(RestWire::fileKey('key-12345', 'selfie'))->toBe(RestWire::fileKey('key-12345', 'selfie'))
            ->not->toBe(RestWire::fileKey('key-12345', 'receipt'));
    });

    it('refuses to send a write with a key the office would refuse', function () {
        $this->client->run('hr/punch', [], 'bad key');
    })->throws(InvalidArgumentException::class);
});

describe('the client', function () {
    it('reads one page of a list as the person, on the business host', function () {
        $this->office->rest('lists/customers', ['data' => [['id' => 1, 'name' => 'Rahman Stores']], 'removed' => [], 'page' => 1, 'per_page' => 200, 'has_more' => false, 'server_time' => '2026-11-17T09:00:00Z']);

        $result = $this->client->list('customers', '2026-11-01T00:00:00Z');

        expect($result->ok())->toBeTrue()
            ->and($result->rows())->toBe([['id' => 1, 'name' => 'Rahman Stores']])
            ->and($result->hasMore())->toBeFalse()
            ->and($result->serverTime())->toBe('2026-11-17T09:00:00Z');

        $this->office->assertCalled('lists/customers', fn (Request $request) => str_starts_with($request->url(), 'https://xyz.sylloo.app/rest/v1/lists/customers?')
            && str_contains($request->url(), 'since=2026-11-01')
            && $request->hasHeader('Authorization', 'Bearer member-token')
            && $request->hasHeader('X-Device-Key', $this->device->deviceKey())
            && $request->hasHeader('X-Sylloo-App', AppInfo::version())
            && ! $request->hasHeader(RestWire::KEY_HEADER));
    });

    it('takes the door address with or without rest/v1', function () {
        $this->office->rest('lists/customers', ['data' => [], 'has_more' => false]);

        RestClient::for('https://xyz.sylloo.app/rest/v1/', $this->device, 'member-token')->list('customers');

        $this->office->assertCalled('lists/customers', fn (Request $request) => str_starts_with($request->url(), 'https://xyz.sylloo.app/rest/v1/lists/customers'));
    });

    it('runs an action with its one-use key in the header only', function () {
        $this->office->rest('actions/hr/punch', ['data' => ['id' => 9, 'state' => 'accepted'], 'server_time' => '2026-11-17T09:00:00Z']);

        $result = $this->client->run('hr/punch', ['type' => 'in'], 'key-0001');

        expect($result->ok())->toBeTrue()->and($result->replayed)->toBeFalse()
            ->and($result->data())->toBe(['id' => 9, 'state' => 'accepted']);
        $this->office->assertCalled('actions/hr/punch', fn (Request $request) => $request->hasHeader(RestWire::KEY_HEADER, 'key-0001')
            && $request->data() === ['type' => 'in']);
    });

    it('takes a replayed answer as done', function () {
        $this->office->rest('actions/hr/punch', ['data' => ['id' => 9], 'server_time' => '2026-11-17T09:00:00Z'], 200, [RestWire::REPLAYED_HEADER => 'true']);

        $result = $this->client->run('hr/punch', ['type' => 'in']);

        expect($result->ok())->toBeTrue()->and($result->replayed)->toBeTrue()->and($result->data())->toBe(['id' => 9]);
    });

    it('reads an action that keeps no record', function () {
        $this->office->rest('actions/hr/note-read', ['data' => null, 'server_time' => '2026-11-17T09:00:00Z']);

        expect($this->client->run('hr/note-read', ['id' => 3])->data())->toBe([]);
    });

    it('names the door refusals by their code and shows the office message', function () {
        $this->office->rest('actions/hr/punch', ['message' => 'Outside the shop.', 'code' => 'refused'], 422);
        $this->office->rest('actions/hr/leave', ['message' => 'Check the dates.', 'code' => 'validation_failed', 'errors' => ['from' => ['The from date is needed.']]], 422);
        $this->office->rest('actions/hr/salary', ['message' => 'You may not see salaries.', 'code' => 'no_permission'], 403);
        $this->office->rest('actions/hr/visit', ['message' => 'Visits are off.', 'code' => 'feature_off'], 403);
        $this->office->rest('actions/hr/claim', ['message' => 'Another body.', 'code' => 'key_reused'], 422);

        $refused = $this->client->run('hr/punch', []);
        $invalid = $this->client->run('hr/leave', []);
        $noPermission = $this->client->run('hr/salary', []);
        $off = $this->client->run('hr/visit', []);
        $reused = $this->client->run('hr/claim', []);

        expect($refused->isRefused())->toBeTrue()->and($refused->refusal())->toBe(RefusalCode::Refused)->and($refused->text())->toBe('Outside the shop.')
            ->and($invalid->isInvalid())->toBeTrue()->and($invalid->errors())->toBe(['from' => 'The from date is needed.'])
            ->and($noPermission->refusal())->toBe(RefusalCode::NoPermission)->and($noPermission->text())->toBe('You may not see salaries.')
            ->and($off->refusal())->toBe(RefusalCode::FeatureOff)
            ->and($reused->refusal())->toBe(RefusalCode::KeyReused)->and($reused->text())->toBe(__('sylloo-app-base::device.refused_key_reused'))
            ->and($reused->triesLater())->toBeFalse()->and($reused->isGate())->toBeFalse();
    });

    it('tells "try later" and the gate apart from a refusal', function () {
        $this->office->rest('actions/hr/punch', ['message' => 'Still working.', 'code' => 'call_in_progress'], 409);
        $this->office->rest('actions/hr/visit', ['message' => 'Signed out.', 'code' => 'session_expired'], 401);
        $this->office->rest('actions/hr/leave', ['message' => 'Update.', 'code' => 'app_too_old'], 426);

        $inProgress = $this->client->run('hr/punch', []);
        $signedOut = $this->client->run('hr/visit', []);
        $tooOld = $this->client->run('hr/leave', []);

        expect($inProgress->triesLater())->toBeTrue()->and($inProgress->isGate())->toBeFalse()
            ->and($signedOut->isGate())->toBeTrue()->and($signedOut->refusal()?->endsSignIn())->toBeTrue()
            ->and($tooOld->isGate())->toBeTrue();
    });

    it('reads a 429 as too many requests', function () {
        $this->office->rest('actions/hr/punch', ['message' => 'Too many.'], 429, ['Retry-After' => '60']);

        $result = $this->client->run('hr/punch', []);

        expect($result->refusal())->toBe(RefusalCode::TooManyRequests)->and($result->triesLater())->toBeTrue()
            ->and($result->retryAfter)->toBe(60);
    });

    it('answers unreachable with no internet', function () {
        $this->office->down();

        expect($this->client->run('hr/punch', [])->isUnreachable())->toBeTrue();
    });

    it('uploads a file with its key and reads the file id', function () {
        $this->office->uploads();

        $result = $this->client->upload(aSelfie(), 'upload-0001');

        expect($result->ok())->toBeTrue()->and($result->fileId())->toBe('file-1');
        $this->office->assertCalled('uploads', fn (Request $request) => $request->isMultipart()
            && $request->hasFile('file', 'jpeg-bytes')
            && $request->hasHeader(RestWire::KEY_HEADER, 'upload-0001')
            && $request->hasHeader('Authorization', 'Bearer member-token'));
    });
});

describe('the waiting list', function () {
    beforeEach(function () {
        WaitingList::allow('hr/punch', 'hr/visit');
        $this->list = new WaitingList('default');
    });

    it('keeps only the actions the app lets wait', function () {
        $this->list->add('hr/salary', ['amount' => 1]);
    })->throws(InvalidArgumentException::class);

    it('keeps only keys the office takes', function () {
        $this->list->add('hr/punch', [], 'bad key');
    })->throws(InvalidArgumentException::class);

    it('sends what waits in order, each once with its own key', function () {
        $sent = [];
        $this->office->rest('actions/hr/punch', function (Request $request) use (&$sent) {
            $sent[] = [$request['type'], $request->header(RestWire::KEY_HEADER)[0]];

            return ['data' => ['ok' => true], 'server_time' => '2026-11-17T09:00:00Z'];
        });

        $this->list->add('hr/punch', ['type' => 'in'], 'key-in-1');
        $this->list->add('hr/punch', ['type' => 'out'], 'key-out-1');

        expect($this->list->count())->toBe(2);

        $report = $this->list->send($this->client);

        expect($report->sent)->toBe(2)->and($report->left)->toBe(0)
            ->and($sent)->toBe([['in', 'key-in-1'], ['out', 'key-out-1']]);
    });

    it('takes a replayed write as sent', function () {
        $this->office->rest('actions/hr/punch', ['data' => ['id' => 4]], 200, [RestWire::REPLAYED_HEADER => 'true']);
        $this->list->add('hr/punch', ['type' => 'in']);

        expect($this->list->send($this->client)->sent)->toBe(1)->and($this->list->count())->toBe(0);
    });

    it('keeps a refused write with its reason and goes on', function () {
        $this->office->rest('actions/hr/visit', ['message' => 'The customer is closed.', 'code' => 'refused'], 422);
        $this->office->rest('actions/hr/punch', ['data' => null]);

        $this->list->add('hr/visit', ['customer' => 4]);
        $this->list->add('hr/punch', ['type' => 'in']);

        $report = $this->list->send($this->client);
        $refused = $this->list->refused();

        expect($report->sent)->toBe(1)->and($report->refused)->toBe(1)
            ->and($refused)->toHaveCount(1)
            ->and($refused[0]->status)->toBe(WaitingWrite::REFUSED)
            ->and($refused[0]->code)->toBe('refused')
            ->and($refused[0]->reason)->toBe('The customer is closed.')
            ->and($refused[0]->fields)->toBe(['customer' => 4]);

        $this->list->retry($refused[0]->id);

        expect($this->list->count())->toBe(1);
    });

    it('keeps a write with a reused key as refused', function () {
        $this->office->rest('actions/hr/punch', ['message' => 'Another body.', 'code' => 'key_reused'], 422);
        $this->list->add('hr/punch', ['type' => 'in']);

        $this->list->send($this->client);

        expect($this->list->refused()[0]->code)->toBe('key_reused');
    });

    it('keeps waiting while the office still works on the first send', function () {
        $this->office->rest('actions/hr/punch', ['message' => 'Still working.', 'code' => 'call_in_progress'], 409);
        $this->list->add('hr/punch', ['type' => 'in']);
        $this->list->add('hr/punch', ['type' => 'out']);

        $report = $this->list->send($this->client);

        expect($report->sent)->toBe(0)->and($report->refused)->toBe(0)->and($report->left)->toBe(2)
            ->and($this->list->waiting()[0]->tries)->toBe(0)
            ->and(DB::table('sylloo_waiting_writes')->orderBy('id')->value('next_try_at'))->not->toBeNull();

        $this->office->rest('actions/hr/punch', ['data' => null]);

        expect($this->list->send($this->client)->sent)->toBe(0);

        $this->travel(31)->seconds();

        expect($this->list->send($this->client)->sent)->toBe(2);
    });

    it('keeps waiting when the gate refuses the person, and says why', function () {
        $this->office->rest('actions/hr/punch', ['message' => 'Signed out.', 'code' => 'session_expired'], 401);
        $this->list->add('hr/punch', ['type' => 'in']);

        $report = $this->list->send($this->client);

        expect($report->stoppedBy)->toBe('session_expired')->and($report->refused)->toBe(0)->and($report->left)->toBe(1);
    });

    it('stops and keeps the order while the office can not be reached', function () {
        $this->office->down();
        $this->list->add('hr/punch', ['type' => 'in']);
        $this->list->add('hr/punch', ['type' => 'out']);

        $report = $this->list->send($this->client);

        expect($report->unreachable)->toBeTrue()->and($report->left)->toBe(2)
            ->and(array_map(fn (WaitingWrite $write) => $write->fields['type'], $this->list->waiting()))->toBe(['in', 'out']);
    });

    it('keeps the fields encrypted', function () {
        $this->list->add('hr/punch', ['type' => 'in', 'note' => 'secret']);

        expect((string) DB::table('sylloo_waiting_writes')->value('fields'))->not->toContain('secret');
    });

    it('uploads a waiting photo first and sends its file id with the action', function () {
        $this->office->uploads();
        $this->office->rest('actions/hr/punch', ['data' => null]);
        $selfie = aSelfie('my-face');

        $write = $this->list->add('hr/punch', ['type' => 'in'], 'key-in-1', ['selfie' => $selfie]);
        unlink($selfie);

        expect($write->files)->toBe(['selfie' => basename($selfie)])
            ->and(collect(Storage::disk('local')->allFiles())->map(fn ($file) => Storage::disk('local')->get($file))->implode(''))->not->toContain('my-face');

        $report = $this->list->send($this->client);

        expect($report->sent)->toBe(1)->and(Storage::disk('local')->allFiles())->toBe([]);
        $this->office->assertCalled('uploads', fn (Request $request) => $request->hasFile('file', 'my-face', basename($selfie))
            && $request->hasHeader(RestWire::KEY_HEADER, RestWire::fileKey('key-in-1', 'selfie')));
        $this->office->assertCalled('actions/hr/punch', fn (Request $request) => $request->data() === ['type' => 'in', 'selfie_file_id' => 'file-1']
            && $request->hasHeader(RestWire::KEY_HEADER, 'key-in-1'));
    });

    it('does not upload a photo twice when the action must wait', function () {
        $this->office->uploads();
        $this->office->rest('actions/hr/punch', ['message' => 'Still working.', 'code' => 'call_in_progress'], 409);
        $this->list->add('hr/punch', ['type' => 'in'], 'key-in-1', ['selfie' => aSelfie()]);

        $this->list->send($this->client);

        expect($this->list->waiting()[0]->files)->toBe([])
            ->and($this->list->waiting()[0]->fields)->toBe(['type' => 'in', 'selfie_file_id' => 'file-1']);

        $this->office->rest('actions/hr/punch', ['data' => null]);
        $this->travel(31)->seconds();
        $this->list->send($this->client);

        expect($this->office->recorded('uploads'))->toHaveCount(1)->and($this->list->count())->toBe(0);
    });

    it('keeps the photo when the upload can not be sent', function () {
        $this->list->add('hr/punch', ['type' => 'in'], null, ['selfie' => aSelfie()]);
        $this->office->down();

        $report = $this->list->send($this->client);

        expect($report->unreachable)->toBeTrue()->and($this->list->waiting()[0]->files)->toHaveKey('selfie')
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    });

    it('keeps a write whose photo the office refused', function () {
        $this->office->rest('uploads', ['message' => 'Not a picture.', 'code' => 'validation_failed', 'errors' => ['file' => ['Not a picture.']]], 422);
        $this->list->add('hr/punch', ['type' => 'in'], null, ['selfie' => aSelfie()]);

        $report = $this->list->send($this->client);

        expect($report->refused)->toBe(1)->and($this->list->refused()[0]->reason)->toBe('Not a picture.');
        $this->office->assertNotCalled('actions/hr/punch');
    });

    it('drops the kept photo with a forgotten write', function () {
        $write = $this->list->add('hr/punch', ['type' => 'in'], null, ['selfie' => aSelfie()]);

        $this->list->forget($write->id);

        expect(Storage::disk('local')->allFiles())->toBe([])->and($this->list->count())->toBe(0);
    });
});

describe('saved data', function () {
    it('keeps a list page by page and removes what the office removed', function () {
        $this->office->rest('lists/customers', function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match ((string) ($query['since'] ?? '')) {
                '' => ($query['page'] ?? '1') === '1'
                    ? ['data' => [['id' => 1, 'name' => 'Rahman Stores']], 'removed' => [], 'page' => 1, 'per_page' => 1, 'has_more' => true, 'server_time' => 'mark-1']
                    : ['data' => [['id' => 2, 'name' => 'Karim Traders']], 'removed' => [], 'page' => 2, 'per_page' => 1, 'has_more' => false, 'server_time' => 'mark-1b'],
                default => ['data' => [['id' => 2, 'name' => 'Karim & Sons']], 'removed' => [1], 'page' => 1, 'per_page' => 1, 'has_more' => false, 'server_time' => 'mark-2'],
            };
        });

        $saved = new SavedData('default');

        expect($saved->refresh($this->client, 'customers')->ok())->toBeTrue()
            ->and(array_column($saved->rows('customers'), 'name'))->toBe(['Rahman Stores', 'Karim Traders'])
            ->and($saved->since('customers'))->toBe('mark-1')
            ->and($saved->savedAt('customers'))->not->toBeNull();

        $saved->refresh($this->client, 'customers');

        expect(array_column($saved->rows('customers'), 'name'))->toBe(['Karim & Sons'])
            ->and($saved->row('customers', '2'))->toBe(['id' => 2, 'name' => 'Karim & Sons'])
            ->and($saved->since('customers'))->toBe('mark-2');
        $this->office->assertCalled('lists/customers', fn (Request $request) => str_contains($request->url(), 'since=mark-1'));
    });

    it('keeps the rows as they were offline', function () {
        $this->office->rest('lists/customers', ['data' => [['id' => 1, 'name' => 'Rahman Stores']], 'removed' => [], 'has_more' => false, 'server_time' => 'mark-1']);
        $saved = new SavedData('default');
        $saved->refresh($this->client, 'customers');

        $this->office->down();

        expect($saved->refresh($this->client, 'customers')->isUnreachable())->toBeTrue()
            ->and($saved->rows('customers'))->toHaveCount(1);
    });
});

describe('the employee door of the collection', function () {
    it('calls the REST door the office named at activation', function () {
        $device = activatedDevice(activated: false);
        $device->rememberActivation($this->office->example('activate'), '1111222233334444', 'Karim\'s phone');

        expect($device->hostUrl())->toBe('https://demo.sylloo.app')
            ->and($device->restUrl())->toBe('https://demo.sylloo.app/rest/v1');

        $roster = RestClient::forDevice($device, 'employee-token')->read('roster');

        expect($roster->ok())->toBeTrue()
            ->and($roster->data()['days'][0]['date'])->toBe('2026-11-17')
            ->and($roster->serverTime())->toBe('2026-11-17T09:00:01Z')
            ->and($roster->anchor())->toBe(['at' => '2026-11-17T08:59:58Z', 'sig' => 'b3c1f0e9a2']);

        $this->office->assertCalled('reads/roster', fn (Request $request) => str_starts_with($request->url(), 'https://demo.sylloo.app/rest/v1/reads/roster')
            && $request->hasHeader('Authorization', 'Bearer employee-token'));
    });

    it('follows the state answer when the office moves the door, and has none before it names one', function () {
        expect(RestClient::forDevice($this->device))->toBeNull();

        $this->device->remember(stateAnswer(['business' => ['rest_url' => 'https://xyz-practice.sylloo.app/rest/v1/', 'host_url' => 'https://xyz-practice.sylloo.app']]));

        expect($this->device->restUrl())->toBe('https://xyz-practice.sylloo.app/rest/v1');

        $this->device->remember(stateAnswer(['business' => ['rest_url' => 'javascript:alert(1)']]));

        expect($this->device->restUrl())->toBeNull()
            ->and(DeviceState::SETTINGS)->toContain('business_rest_url', 'business_host_url');
    });

    it('answers the collection\'s actions, lists and uploads by default', function () {
        $punch = $this->client->run('attendance/punch', ['latitude' => 23.78, 'longitude' => 90.41]);
        $list = $this->client->list('attendance');
        $upload = $this->client->uploadContents('jpeg', 'selfie.jpg');

        expect($punch->data()['state'])->toBeIn(['sent', 'sent_late', 'needs_check'])
            ->and($punch->anchor())->not->toBeNull()
            ->and($list->rows()[0]['worked_minutes'])->toBe(543)
            ->and($list->hasMore())->toBeFalse()
            ->and($upload->fileId())->toBe('8f2c41d0-5b7e-4c1a-9d3e-2a6b7c8d9e0f')
            ->and(RestWire::readPath('home'))->toBe('rest/v1/reads/home');
    });

    it('answers a read the test names, with server_time and anchor', function () {
        $this->office->read('home', ['menu' => []]);

        $home = $this->client->read('home');

        expect($home->data())->toBe(['menu' => []])
            ->and($home->serverTime())->not->toBeNull()
            ->and($home->anchor()['at'])->toBe($home->serverTime());
    });

    it('rejects a read name that is not one', function () {
        RestWire::readPath('home/../x');
    })->throws(InvalidArgumentException::class);
});
