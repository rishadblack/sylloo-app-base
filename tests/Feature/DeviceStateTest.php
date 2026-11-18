<?php

use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Rishadblack\SyllooAppBase\Device\ActivationKey;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Testing\ArrayDeviceStore;

describe('the device key', function () {
    it('is kept encrypted and read back', function () {
        $store = new ArrayDeviceStore;
        $key = new ActivationKey($store);

        $key->put('device-key-9');

        expect($store->values['activation_key'])->not->toBe('device-key-9')
            ->and(ActivationKey::isEncryptedPayload($store->values['activation_key']))->toBeTrue()
            ->and($key->get())->toBe('device-key-9');
    });

    it('encrypts a key that was saved plain', function () {
        $store = new ArrayDeviceStore(['activation_key' => 'plain-key']);

        expect((new ActivationKey($store))->get())->toBe('plain-key')
            ->and(Crypt::decryptString($store->values['activation_key']))->toBe('plain-key');
    });

    it('never sends a key encrypted by another install, and counts the device as not activated', function () {
        $foreign = (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString('other-key');
        $device = activatedDevice(['activation_key' => $foreign]);

        expect($device->deviceKey())->toBeNull()
            ->and($device->activationKey()->isUnreadable())->toBeTrue()
            ->and($device->isActivated())->toBeFalse()
            ->and($device->screen())->toBe(DeviceState::NOT_ACTIVATED);
    });
});

describe('which screen shows', function () {
    it('lets an activated device work', function () {
        expect(activatedDevice()->screen())->toBeNull();
    });

    it('shows each state the ERP sends', function (array $values, string $screen) {
        expect(activatedDevice($values)->screen())->toBe($screen);
    })->with([
        'released' => [['blocked' => 'device_released'], DeviceState::RELEASING],
        'releasing' => [['device_releasing' => true], DeviceState::RELEASING],
        'locked' => [['is_locked' => 1], DeviceState::LOCKED],
        'business paused' => [['blocked' => 'business_suspended'], DeviceState::BUSINESS_SUSPENDED],
        'plan ended' => [['blocked' => 'plan_ended'], DeviceState::PLAN_ENDED],
        'past the last offline day' => [['plan' => ['active' => true, 'ends_on' => '2020-01-01', 'in_grace' => false, 'valid_until' => '2020-01-08']], DeviceState::PLAN_ENDED],
        'update needed' => [['min_version' => '9.0.0'], DeviceState::UPDATE_NEEDED],
    ]);

    it('never ends the plan of a Practice device', function () {
        expect(activatedDevice(['license_mode' => 'practice', 'blocked' => 'plan_ended'])->screen())->toBeNull();
    });

    it('shows Activate on a device without a key', function () {
        expect(activatedDevice(activated: false)->screen())->toBe(DeviceState::NOT_ACTIVATED);
    });
});

describe('what the ERP says', function () {
    it('keeps a new activation', function () {
        $device = activatedDevice(activated: false);

        $device->rememberActivation(['device_key' => 'key-new', 'device_id' => 12, 'mode' => 'practice', ...stateAnswer(['device' => ['mode' => 'practice']])], '1234123412341234', 'Front desk');

        expect($device->deviceKey())->toBe('key-new')
            ->and($device->isActivated())->toBeTrue()
            ->and($device->isPractice())->toBeTrue()
            ->and($device->get('activation_code'))->toBe('1234123412341234')
            ->and($device->deviceName())->toBe('Front desk')
            ->and($device->businessName())->toBe('XYZ Trading')
            ->and($device->signInHost())->toBe('xyz.sylloo.app')
            ->and($device->portalUrl())->toBe('https://sylloo.eu/businesses/1/billing')
            ->and($device->offlineLoginDays())->toBe(7);
    });

    it('keeps the business key from the activation and from a later state answer', function () {
        $device = activatedDevice(activated: false);
        $key = '3f9c2a7d1e8b4c6a9d0e5f7a2b8c4d1e';

        $device->rememberActivation(['device_key' => 'key-new', 'device_id' => 12, 'mode' => 'live', ...stateAnswer(['business' => ['key' => $key, 'name' => 'XYZ Trading']])], '1234123412341234', 'Front desk');

        expect($device->businessKey())->toBe($key);

        // An older ERP sends no key: the kept one stays.
        $device->remember(stateAnswer());
        expect($device->businessKey())->toBe($key);

        // A till activated before the ERP sent it learns it from the next state answer.
        $older = activatedDevice();
        expect($older->businessKey())->toBeNull();

        $older->remember(stateAnswer(['business' => ['key' => $key]]));
        expect($older->businessKey())->toBe($key);

        // A key that is not one is never kept.
        $older->remember(stateAnswer(['business' => ['key' => 'short']]));
        expect($older->businessKey())->toBeNull()
            ->and(DeviceState::SETTINGS)->toContain('business_key');
    });

    it('starts releasing when the state says the device was released', function () {
        $device = activatedDevice();

        $device->remember(stateAnswer(['blocked' => 'device_released']));

        expect($device->isReleasing())->toBeTrue();
    });

    it('reads a 401 device_released answer as released', function () {
        $released = new Response(new GuzzleHttp\Psr7\Response(401, [], json_encode(['code' => 'device_released'])));
        $expired = new Response(new GuzzleHttp\Psr7\Response(401, [], json_encode(['code' => 'session_expired'])));

        expect(DeviceState::isReleasedReply($released))->toBeTrue()
            ->and(DeviceState::isReleasedReply($expired))->toBeFalse();
    });

    it('counts the plan bar days only near the end of a Live plan', function () {
        $device = activatedDevice(['plan' => ['active' => true, 'ends_on' => now()->addDays(5)->toDateString(), 'in_grace' => false, 'valid_until' => now()->addDays(12)->toDateString()]]);

        expect($device->planEndsInDays())->toBe(5)
            ->and(activatedDevice()->planEndsInDays())->toBeNull();
    });

    it('forgets the activation but keeps the install id', function () {
        $device = activatedDevice();
        $uid = $device->deviceUid();
        $device->remember(stateAnswer());

        $device->forgetActivation();

        $left = array_keys($device->store()->values);

        expect($left)->toBe([DeviceState::DEVICE_UID_SETTING])
            ->and($device->deviceUid())->toBe($uid)
            ->and($device->isActivated())->toBeFalse();
    });

    it('reads a store that fails as empty', function () {
        $store = new class extends ArrayDeviceStore
        {
            public function get(string $key): mixed
            {
                throw new RuntimeException('unreadable');
            }
        };

        expect((new DeviceState($store))->get('business_name'))->toBeNull();
    });
});
