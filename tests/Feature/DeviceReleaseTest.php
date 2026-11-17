<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Device\DeviceStateCheck;
use Rishadblack\SyllooAppBase\Erp\ApiService;
use Rishadblack\SyllooAppBase\Erp\DeviceApi;
use Rishadblack\SyllooAppBase\Release\DeviceRelease;
use Rishadblack\SyllooAppBase\Testing\FakeReleaseSteps;

beforeEach(function () {
    config(['erp.base_url' => 'https://sylloo-erp.example']);
    Http::preventStrayRequests();
    $this->device = activatedDevice(['app_data' => 'members, tokens, PINs', 'device_releasing' => true]);
    $this->steps = new FakeReleaseSteps($this->device);
    $this->release = new DeviceRelease($this->device, $this->steps);
});

it('does nothing on a device that is not released', function () {
    $device = activatedDevice();

    expect((new DeviceRelease($device, new FakeReleaseSteps($device)))->run())->toBe(DeviceRelease::NONE);
});

it('sends what waits before it removes anything', function () {
    $this->steps->waiting = true;

    expect($this->release->run())->toBe(DeviceRelease::SENDING)
        ->and($this->steps->calls)->toBe(['stopWork', 'sendWaitingWork'])
        ->and($this->device->get('app_data'))->not->toBeNull();
});

it('stays on Releasing while the office refused work', function () {
    $this->steps->refused = 2;

    expect($this->release->run())->toBe(DeviceRelease::REFUSED)
        ->and($this->device->isActivated())->toBeTrue();
});

it('removes everything of the business, tells the office with the old key and leaves no secret', function () {
    Http::fake(['*/device/api/v1/released' => Http::response(['status' => 'removed'])]);
    $uid = $this->device->deviceUid();

    expect($this->release->run())->toBe(DeviceRelease::CLEANED)
        ->and($this->steps->calls)->toBe(['stopWork', 'removeLocalData', 'afterClean'])
        ->and($this->device->store()->values)->toBe([
            DeviceState::DEVICE_UID_SETTING => $uid,
            DeviceState::RELEASED_FROM_SETTING => 'XYZ Trading',
        ])
        ->and($this->device->screen())->toBe(DeviceState::NOT_ACTIVATED);

    Http::assertSent(fn (Request $request) => $request->header('X-Device-Key') === ['device-key-1']);
});

it('cleans up even when the office can not be told', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

    expect($this->release->run())->toBe(DeviceRelease::CLEANED)
        ->and($this->device->deviceKey())->toBeNull();
});

it('deactivates only online, then releases', function () {
    $device = activatedDevice();
    $release = new DeviceRelease($device, new FakeReleaseSteps($device));

    $online = false;
    Http::fake(function () use (&$online) {
        return $online ? Http::response(['status' => 'releasing']) : throw new ConnectionException('cURL error 7: Failed to connect');
    });
    expect($release->deactivate())->toBe(DeviceRelease::OFFLINE)
        ->and($device->isReleasing())->toBeFalse();

    $online = true;
    expect($release->deactivate())->toBe(DeviceRelease::DEACTIVATED)
        ->and($device->isReleasing())->toBeTrue();
});

describe('asking the office how the device stands', function () {
    it('keeps the state and shows its screen', function () {
        Http::fake(['*/device/api/v1/state' => Http::response(stateAnswer(['blocked' => 'business_suspended']))]);
        $device = activatedDevice();

        $state = (new DeviceStateCheck($device, fn () => new DeviceApi(new ApiService($device))))->askState();

        expect($state['blocked'])->toBe('business_suspended')
            ->and($device->screen())->toBe(DeviceState::BUSINESS_SUSPENDED);
    });

    it('reads a released key as released', function () {
        Http::fake(['*' => Http::response(['code' => 'device_released'], 401)]);
        $device = activatedDevice();

        $state = (new DeviceStateCheck($device, fn () => new DeviceApi(new ApiService($device))))->askState();

        expect(DeviceStateCheck::confirmsRelease($state))->toBeTrue()
            ->and($device->screen())->toBe(DeviceState::RELEASING);
    });

    it('does not ask for a device that is not activated, and says when the office is away', function () {
        $device = activatedDevice(activated: false);
        expect((new DeviceStateCheck($device, fn () => throw new RuntimeException('not asked')))->askState())->toBeNull();

        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));
        $device = activatedDevice();
        expect((new DeviceStateCheck($device, fn () => new DeviceApi(new ApiService($device))))->askState())->toBeNull();
    });
});
