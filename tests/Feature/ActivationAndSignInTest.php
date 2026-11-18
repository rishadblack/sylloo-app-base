<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Rishadblack\SyllooAppBase\Device\Activation;
use Rishadblack\SyllooAppBase\Erp\ApiService;
use Rishadblack\SyllooAppBase\Erp\DeviceApi;
use Rishadblack\SyllooAppBase\Erp\RefusedException;
use Rishadblack\SyllooAppBase\SignIn\BrowserSignIn;
use Rishadblack\SyllooAppBase\SignIn\SignInRequest;

beforeEach(function () {
    config(['erp.base_url' => 'https://sylloo-erp.example', 'nativephp.version' => '1.0.0']);
    Http::preventStrayRequests();
});

describe('activation', function () {
    it('takes the code with or without dashes', function () {
        expect(Activation::digitsOf('1234-5678 1234-5678'))->toBe('1234567812345678')
            ->and(Activation::isCode('1234567812345678'))->toBeTrue()
            ->and(Activation::isCode('12345678'))->toBeFalse();
    });

    it('sends the code, kind, install id, name, system and version', function () {
        $payload = Activation::payload('1234567812345678', 'app', 'uid-1', 'Front desk', ['extra' => 1], 'windows');

        expect($payload)->toMatchArray([
            'code' => '1234567812345678', 'kind' => 'app', 'device_uid' => 'uid-1', 'name' => 'Front desk',
            'platform' => 'windows', 'app_version' => '1.0.0', 'extra' => 1,
        ]);
    });

    it('reads the office\'s activation QR in every app, whatever its own link scheme', function (string $scheme) {
        // What the ERP (Modules/Device PortalDeviceController::code) and the portal (RevealDeviceCode) draw.
        expect(Activation::codeFromScan('sylloo-app://activate?code=1234567812345678', $scheme))
            ->toBe(['code' => '1234567812345678', 'link' => true])
            ->and(Activation::codeFromScan(" sylloo-app://activate?code=1234-5678-1234-5678\n", $scheme))
            ->toBe(['code' => '1234567812345678', 'link' => true])
            ->and(Activation::codeFromLink($scheme.'://activate?code=1234567812345678', $scheme))->toBe('1234567812345678');
    })->with(['Sylloo App' => 'sylloo-app', 'Sylloo POS' => 'sylloo-pos']);

    it('reads 16 digits alone as a code to fill in, and nothing else as a code', function () {
        expect(Activation::codeFromScan('1234 5678 1234 5678', 'sylloo-app'))->toBe(['code' => '1234567812345678', 'link' => false])
            ->and(Activation::codeFromScan('https://example.com/p/1234567812345678', 'sylloo-app'))->toBe(['code' => null, 'link' => false])
            ->and(Activation::codeFromScan('sylloo-app://activate?code=12345678', 'sylloo-app'))->toBe(['code' => null, 'link' => false])
            ->and(Activation::codeFromScan('other-app://activate?code=1234567812345678', 'sylloo-app'))->toBe(['code' => null, 'link' => false])
            ->and(Activation::codeFromScan('sylloo-app://signed-in?code=1234567812345678', 'sylloo-app'))->toBe(['code' => null, 'link' => false])
            ->and(Activation::codeFromScan('123456781234567', 'sylloo-app'))->toBe(['code' => null, 'link' => false]);
    });

    it('keeps only an answer with a key and a mode', function () {
        expect(Activation::isComplete(['device_key' => 'k', 'mode' => 'live']))->toBeTrue()
            ->and(Activation::isComplete(['device_key' => ' ', 'mode' => 'live']))->toBeFalse()
            ->and(Activation::isComplete(['device_key' => 'k', 'mode' => 'test']))->toBeFalse();
    });
});

describe('sign in with browser', function () {
    it('sends the SHA-256 of a secret it keeps and opens the ERP page', function () {
        Http::fake(['*/sign-in/start' => Http::response(['request_id' => 'r1', 'browser_url' => 'https://xyz.sylloo.app/device/sign-in/r1', 'expires_in' => 300, 'poll_seconds' => 1])]);

        $request = (new BrowserSignIn(new DeviceApi(new ApiService(activatedDevice()))))->start();

        expect($request->requestId)->toBe('r1')
            ->and(strlen($request->secret))->toBe(64)
            ->and($request->browserUrl)->toBe('https://xyz.sylloo.app/device/sign-in/r1')
            ->and($request->pollSeconds)->toBe(2)
            ->and($request->isExpired())->toBeFalse();

        Http::assertSent(fn (Request $sent) => $sent['challenge'] === hash('sha256', $request->secret) && ! str_contains($sent->body(), $request->secret));
    });

    it('refuses an answer without a usable browser address', function () {
        Http::fake(['*' => Http::response(['request_id' => 'r1', 'browser_url' => 'javascript:alert(1)'])]);

        expect(fn () => (new BrowserSignIn(new DeviceApi(new ApiService(activatedDevice()))))->start())->toThrow(RefusedException::class);
    });

    it('waits while the member has not pressed Allow, then takes the member', function () {
        Http::fakeSequence('*/sign-in/finish')
            ->push(['code' => 'sign_in_pending', 'message' => 'Waiting.'], 409)
            ->push(['token' => 't1', 'member' => ['id' => 5, 'name' => 'Rahim', 'photo' => null], 'pin_set' => true]);
        $signIn = new BrowserSignIn(new DeviceApi(new ApiService(activatedDevice())));
        $request = new SignInRequest('r1', 'secret', now()->addMinutes(5)->timestamp);

        expect($signIn->finish($request))->toBeNull()
            ->and($signIn->finish($request))->toMatchArray(['token' => 't1']);

        Http::assertSent(fn (Request $sent) => $sent['verifier'] === 'secret' && $sent['request_id'] === 'r1');
    });

    it('reports a refused, used or expired request', function (int $status, string $code) {
        Http::fake(['*' => Http::response(['code' => $code, 'message' => 'No.'], $status)]);

        try {
            (new BrowserSignIn(new DeviceApi(new ApiService(activatedDevice()))))->finish(new SignInRequest('r1', 's', now()->addMinute()->timestamp));
            $this->fail('No refusal.');
        } catch (RefusedException $refusal) {
            expect($refusal->code())->toBe($code)->and($refusal->status)->toBe($status);
        }
    })->with([
        'refused' => [403, 'sign_in_refused'],
        'expired' => [410, 'sign_in_expired'],
        'member not allowed' => [403, 'member_not_allowed'],
    ]);

    it('says when the ERP can not be reached', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        expect(fn () => (new BrowserSignIn(new DeviceApi(new ApiService(activatedDevice()))))->finish(new SignInRequest('r1', 's', now()->addMinute()->timestamp)))
            ->toThrow(ConnectionException::class);
    });

    it('knows an expired request and a stored one', function () {
        expect((new SignInRequest('r', 's', now()->subSecond()->timestamp))->isExpired())->toBeTrue()
            ->and(SignInRequest::fromArray(['request_id' => 'r', 'secret' => 's', 'expires_at' => 5])?->requestId)->toBe('r')
            ->and(SignInRequest::fromArray(['request_id' => 'r']))->toBeNull();
    });

    it('accepts only the hand-back link of its own scheme', function () {
        expect(BrowserSignIn::isSignedInLink('sylloo-app://signed-in', 'sylloo-app'))->toBeTrue()
            ->and(BrowserSignIn::isSignedInLink(['url' => 'sylloo-app://signed-in?x=1'], 'sylloo-app'))->toBeTrue()
            ->and(BrowserSignIn::isSignedInLink('sylloo-pos://signed-in', 'sylloo-app'))->toBeFalse()
            ->and(BrowserSignIn::isSignedInLink('sylloo-app://activate', 'sylloo-app'))->toBeFalse();
    });

    it('takes a sign-in only with a token and a member', function () {
        expect(BrowserSignIn::isComplete(['token' => 't', 'member' => ['id' => 1, 'name' => 'A']]))->toBeTrue()
            ->and(BrowserSignIn::isComplete(['token' => 't', 'member' => ['id' => 1]]))->toBeFalse()
            ->and(BrowserSignIn::isComplete(['member' => ['id' => 1, 'name' => 'A']]))->toBeFalse();
    });
});
