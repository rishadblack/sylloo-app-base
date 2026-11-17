<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Testing\ArrayDeviceStore;
use Rishadblack\SyllooAppBase\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

pest()->use(RefreshDatabase::class)->in('Feature/Rest');

/**
 * The device state of an activated device (key `device-key-1`, Live, business "XYZ Trading"), kept in memory, unless
 * `$activated` is false.
 *
 * @param  array<string, mixed>  $values
 */
function activatedDevice(array $values = [], bool $activated = true): DeviceState
{
    return new DeviceState(new ArrayDeviceStore([
        ...($activated ? [
            'activation_key' => Crypt::encryptString('device-key-1'),
            'device_id' => '7',
            'device_name' => 'Counter 1',
            'activation_code' => '1111222233334444',
            'license_mode' => 'live',
            'business_name' => 'XYZ Trading',
        ] : []),
        ...$values,
    ]));
}

/**
 * The ERP's `GET device/api/v1/state` answer (device-api.md §1), Live with an active plan unless overridden.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stateAnswer(array $overrides = []): array
{
    return [
        'device' => ['id' => 7, 'name' => 'Counter 1', 'kind' => 'app', 'mode' => 'live', 'status' => 'active'],
        'business' => ['name' => 'XYZ Trading', 'logo_url' => null, 'hosts' => ['live' => 'xyz.sylloo.app', 'practice' => 'xyz.sylloo.dev'], 'portal_url' => 'https://sylloo.eu/businesses/1/billing'],
        'plan' => ['active' => true, 'ends_on' => now()->addMonths(6)->toDateString(), 'in_grace' => false, 'valid_until' => now()->addMonths(6)->addDays(7)->toDateString()],
        'blocked' => null,
        'limits' => ['app' => ['used' => 1, 'allowed' => 3]],
        'min_version' => null,
        'offline_login_days' => 7,
        ...$overrides,
    ];
}
