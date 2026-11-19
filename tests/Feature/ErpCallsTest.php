<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Rishadblack\SyllooAppBase\Erp\ApiService;
use Rishadblack\SyllooAppBase\Erp\DeviceApi;
use Rishadblack\SyllooAppBase\Erp\ErpCircuitBreaker;
use Rishadblack\SyllooAppBase\Erp\Refusal;
use Rishadblack\SyllooAppBase\Erp\RefusalCode;

beforeEach(function () {
    config(['erp.base_url' => 'https://sylloo-erp.example', 'nativephp.version' => '2.1.0']);
    Http::preventStrayRequests();
});

it('sends the device key, the app version and the member token to the device API', function () {
    Http::fake(['sylloo-erp.example/device/api/v1/me' => Http::response(['member' => ['id' => 1]])]);

    (new DeviceApi(new ApiService(activatedDevice(), 'member-token')))->me();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://sylloo-erp.example/device/api/v1/me'
        && $request->header('X-Device-Key') === ['device-key-1']
        && $request->header('X-Sylloo-App') === ['2.1.0']
        && $request->header('Authorization') === ['Bearer member-token']);
});

it('sends the sign-in challenge, never the secret', function () {
    Http::fake(['*' => Http::response(['request_id' => 'r1'])]);

    (new DeviceApi(new ApiService(activatedDevice())))->signInStart(hash('sha256', 'secret'));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/device/api/v1/sign-in/start')
        && $request['challenge'] === hash('sha256', 'secret'));
});

it('marks the device as releasing when the key no longer works', function () {
    Http::fake(['*' => Http::response(['code' => 'device_released', 'message' => 'Released.'], 401)]);
    $device = activatedDevice();

    (new DeviceApi(new ApiService($device)))->deviceState();

    expect($device->isReleasing())->toBeTrue();
});

it('stops background calls while the ERP is away, but probes still try', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    ErpCircuitBreaker::recordFailure();
    ErpCircuitBreaker::recordFailure();
    ErpCircuitBreaker::recordFailure();

    expect(ErpCircuitBreaker::isOpen())->toBeTrue()
        ->and(fn () => (new ApiService(activatedDevice()))->get('v1/state'))->toThrow(ConnectionException::class);

    (new DeviceApi(new ApiService(activatedDevice())))->deviceState();

    expect(ErpCircuitBreaker::isOpen())->toBeFalse();
});

it('opens the breaker for what a 429 asks, at most the longest cool-down', function () {
    ErpCircuitBreaker::openFor(5000);

    expect(ErpCircuitBreaker::openUntil()->diffInSeconds(now(), true))->toBeLessThanOrEqual(ErpCircuitBreaker::MAX_COOL_DOWN_SECONDS + 1);
});

it('refuses to call an http ERP in production', function () {
    config(['erp.base_url' => 'http://sylloo-erp.example']);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new DeviceApi(new ApiService(activatedDevice())))->deviceState())->toThrow(ConnectionException::class);
});

it('retries only what is safe to send again', function () {
    $dropped = new ConnectionException('cURL error 28: Operation timed out after 15000 milliseconds');
    $neverConnected = new ConnectionException('cURL error 7: Failed to connect');

    expect(ApiService::shouldRetry($dropped, null, 'GET'))->toBeTrue()
        ->and(ApiService::shouldRetry($dropped, null, 'POST'))->toBeFalse()
        ->and(ApiService::shouldRetry($neverConnected, null, 'POST'))->toBeTrue();
});

describe('refusals', function () {
    it('reads the code, then the message, then says it was unexpected', function () {
        app('translator')->addLines(['device.refused_invalid_code' => 'That code is not right.', 'device.unexpected' => 'Something went wrong.'], 'en', 'sylloo-app-base');

        expect(Refusal::text(['code' => 'invalid_code', 'message' => 'x']))->toBe('That code is not right.')
            ->and(Refusal::text(['code' => 'new_code', 'message' => 'The office says no.']))->toBe('The office says no.')
            ->and(Refusal::text([]))->toBe('Something went wrong.');
    });

    it('lists the devices in use when the plan is full', function () {
        $limit = Refusal::limitFrom(['code' => 'device_limit_reached_app', 'allowed' => '2', 'devices' => [
            ['name' => 'Desk 1', 'last_seen_at' => '2026-11-01', 'last_user' => 'Rahim'],
            ['name' => 7],
            'odd',
        ]]);

        expect($limit)->toBe(['allowed' => 2, 'devices' => [['name' => 'Desk 1', 'last_seen_at' => '2026-11-01', 'last_user' => 'Rahim']]])
            ->and(Refusal::isDeviceLimit('device_limit_reached_app', 'app'))->toBeTrue();
    });

    it('says the business has no employee phone left, without the list of devices in use', function () {
        app()->setLocale('en');

        expect(Refusal::text(['code' => 'device_limit_reached_employee', 'message' => 'x']))
            ->toStartWith('This business has reached its number of employee phones.')
            ->and(RefusalCode::DeviceLimitReachedEmployee->isDeviceLimit())->toBeFalse()
            ->and(RefusalCode::DeviceLimitReachedEmployee->isGate())->toBeFalse();

        foreach (['bn', 'fi'] as $locale) {
            expect(trans()->has('sylloo-app-base::device.refused_device_limit_reached_employee', $locale, false))->toBeTrue();
        }
    });
});
