<?php

namespace Rishadblack\SyllooAppBase\Testing;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Rishadblack\SyllooAppBase\Contracts\DeviceEntries;
use Rishadblack\SyllooAppBase\Contracts\DeviceStore;
use Rishadblack\SyllooAppBase\Contracts\MemberDirectory;
use Rishadblack\SyllooAppBase\Contracts\ReleaseSteps;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Pin\Presence;

/**
 * A device kept in memory for tests, bound in the container as the one business of the app (DeviceStore, DeviceState,
 * MemberDirectory, ReleaseSteps), so the shared screens run on it:
 *
 *     $device = TestDevice::bind();                    // activated: "XYZ Trading", Live, key device-key-1
 *     $device = TestDevice::bind(activated: false);    // a new install
 *     $rahim = $device->addMember(pin: '1357');        // signed in here before, PIN 1357, within the offline days
 *     $device->enter($rahim);                          // Rahim is in (the idle lock watches him)
 */
final class TestDevice
{
    public function __construct(
        public readonly ArrayDeviceStore $store,
        public readonly DeviceState $state,
        public readonly ArrayMemberDirectory $members,
        public readonly FakeReleaseSteps $release,
    ) {}

    /**
     * @param  array<string, mixed>  $values  more (or other) values of the device store
     */
    public static function bind(array $values = [], bool $activated = true): self
    {
        $store = new ArrayDeviceStore([
            ...($activated ? [
                'activation_key' => Crypt::encryptString('device-key-1'),
                'device_id' => '7',
                'device_name' => 'Counter 1',
                'activation_code' => '1111222233334444',
                'license_mode' => 'live',
                'business_name' => 'XYZ Trading',
                'device_kind' => 'app',
            ] : []),
            ...$values,
        ]);
        $state = new DeviceState($store);
        $device = new self($store, $state, new ArrayMemberDirectory($state), new FakeReleaseSteps($state));

        app()->instance(DeviceStore::class, $store);
        app()->instance(DeviceState::class, $state);
        app()->instance(MemberDirectory::class, $device->members);
        app()->instance(ReleaseSteps::class, $device->release);
        app()->forgetInstance(DeviceEntries::class);

        return $device;
    }

    /**
     * A person signed in here before: a valid sign-in, a PIN (null: none yet), offline sign-in allowed for a week.
     */
    public function addMember(string $key = 'member-1', string $name = 'Rahim', ?string $pin = '1357', bool $offline = true): FakeMember
    {
        $member = new FakeMember($key, $name, 'token-'.$key, now()->addMonth(), $offline ? now()->addWeek() : null);
        $this->members->members[] = $member;

        if ($pin !== null) {
            $this->members->hashes[$key] = Hash::make($pin);
        }

        return $member;
    }

    /**
     * The person is in on this device.
     */
    public function enter(FakeMember $member): void
    {
        app(Presence::class)->enter('default', $member->memberKey());
    }
}
