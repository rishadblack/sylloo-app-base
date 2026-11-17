<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Rishadblack\SyllooAppBase\Pin\PinSignIn;
use Rishadblack\SyllooAppBase\Testing\ArrayMemberDirectory;
use Rishadblack\SyllooAppBase\Testing\FakeMember;

beforeEach(function () {
    config(['erp.base_url' => 'https://sylloo-erp.example']);
    Http::preventStrayRequests();
    $this->device = activatedDevice(['offline_login_days' => 3]);
    $this->members = new ArrayMemberDirectory($this->device);
    $this->pins = new PinSignIn($this->members, $this->device);
    $this->member = new FakeMember;
    $this->members->members = [$this->member];
});

it('refuses short and easy PINs', function (string $pin, ?string $problem) {
    expect(PinSignIn::problemWith($pin))->toBe($problem);
})->with([
    ['12', 'sylloo-app-base::device.pin_format'],
    ['12a4', 'sylloo-app-base::device.pin_format'],
    ['1111', 'sylloo-app-base::device.pin_too_easy'],
    ['1234', 'sylloo-app-base::device.pin_too_easy'],
    ['98765', 'sylloo-app-base::device.pin_too_easy'],
    ['1357', null],
    ['204816', null],
]);

it('checks a PIN against the kept bcrypt hash', function () {
    $this->members->hashes['member-1'] = Hash::make('1357');

    expect($this->pins->check($this->member, '1357'))->toBe(PinSignIn::CHECKED)
        ->and($this->pins->check($this->member, '2468'))->toBe(PinSignIn::WRONG)
        ->and(PinSignIn::triesLeft($this->member))->toBe(4);
});

it('has no PIN to check for a member without a hash', function () {
    expect($this->pins->check($this->member, '1357'))->toBe(PinSignIn::NO_PIN);
});

it('ends the sign-in after five wrong PINs and tells the office', function () {
    Http::fake(['*/device/api/v1/sign-out' => Http::response(['signed_out' => true])]);
    $this->members->hashes['member-1'] = Hash::make('1357');

    foreach (range(1, 4) as $try) {
        expect($this->pins->check($this->member, '0000'))->toBe(PinSignIn::WRONG);
    }

    expect($this->pins->check($this->member, '0000'))->toBe(PinSignIn::SIGNED_OUT)
        ->and($this->member->hasValidToken())->toBeFalse()
        ->and(PinSignIn::triesLeft($this->member))->toBe(5);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sign-out') && $request->header('Authorization') === ['Bearer member-token']);
});

it('keeps only bcrypt hashes from the office', function () {
    $this->pins->rememberHash($this->member, 'not-a-hash');
    expect($this->members->hashes)->toBe([]);

    $this->pins->rememberPin($this->member, '1357', 'not-a-hash');
    expect(Hash::check('1357', $this->members->hashes['member-1']))->toBeTrue();
});

it('learns a PIN changed elsewhere, and a removed one, from GET me', function () {
    $hash = Hash::make('2468');
    Http::fakeSequence('*/device/api/v1/me')
        ->push(['member' => ['id' => 1, 'name' => 'Rahim', 'photo' => 'https://sylloo.app/p.png'], 'pin_set' => true, 'pin_hash' => $hash])
        ->push(['member' => ['id' => 1, 'name' => 'Rahim', 'photo' => 'https://sylloo.app/p.png'], 'pin_set' => false, 'pin_hash' => null]);

    expect($this->pins->refresh($this->member))->toBeTrue()
        ->and($this->members->hashes['member-1'])->toBe($hash)
        ->and($this->member->photo)->toBe('https://sylloo.app/p.png');

    $this->pins->refreshAll();

    expect($this->members->hashes)->toBe([]);
});

it('forgets a sign-in the office ended', function () {
    Http::fake(['*' => Http::response(['code' => 'session_expired'], 401)]);

    expect($this->pins->refresh($this->member))->toBeTrue()
        ->and($this->member->hasValidToken())->toBeFalse();
});

it('lets a PIN work offline only within the offline days after the office confirmed it', function () {
    expect(PinSignIn::mayUseOffline($this->member))->toBeFalse();

    $this->pins->confirmOnline($this->member);

    expect(PinSignIn::mayUseOffline($this->member))->toBeTrue()
        ->and($this->member->offlineSignInUntil()->toDateString())->toBe(now()->addDays(3)->toDateString());
});
