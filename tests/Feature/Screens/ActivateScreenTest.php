<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Rishadblack\SyllooAppBase\Events\DeviceActivated;
use Rishadblack\SyllooAppBase\Livewire\Activate;
use Rishadblack\SyllooAppBase\Screens\Hooks;
use Rishadblack\SyllooAppBase\Screens\Question;
use Rishadblack\SyllooAppBase\Testing\PretendOffice;
use Rishadblack\SyllooAppBase\Testing\TestDevice;

beforeEach(function () {
    Sleep::fake();
    $this->office = PretendOffice::start();
});

it('activates with the code and shows the confirm card', function () {
    $device = TestDevice::bind(activated: false);
    Event::fake([DeviceActivated::class]);

    Livewire::test(Activate::class)
        ->assertSee('Activate this device')
        ->assertDontSee('sylloo-hook')
        ->set('fields.code', '1234-5678-1234-5678')
        ->call('run', 'activate')
        ->assertSee('Device activated')
        ->assertSee('Demo Ltd')
        ->assertSee('LIVE')
        ->assertSee('EMPLOYEE')
        ->assertSee('Continue');

    expect($device->state->isActivated())->toBeTrue()
        ->and($device->state->businessName())->toBe('Demo Ltd')
        ->and($device->state->kind())->toBe('employee');

    $this->office->assertCalled('activate', fn (Request $request) => $request['code'] === '1234567812345678'
        && $request['kind'] === 'app' && $request['app_version'] === '2.0.0' && is_string($request['device_uid']));
    Event::assertDispatched(DeviceActivated::class, fn (DeviceActivated $event) => $event->entryId === 'default');
});

it('takes the code of an activation link', function () {
    TestDevice::bind(activated: false);

    Livewire::withQueryParams(['code' => '1111222233334444'])->test(Activate::class)
        ->assertSet('fields.code', '1111-2222-3333-4444');
});

it('asks for 16 digits before it asks the office', function () {
    TestDevice::bind(activated: false);

    Livewire::test(Activate::class)
        ->set('fields.code', '1234')
        ->call('run', 'activate')
        ->assertHasErrors('fields.code')
        ->assertSee('The business code has 16 digits.');

    $this->office->assertNotCalled('activate');
});

it('shows the office refusal at the code', function () {
    TestDevice::bind(activated: false);
    $this->office->refuse('activate', 'invalid_code', 422);

    Livewire::test(Activate::class)
        ->set('fields.code', '1234567812345678')
        ->call('run', 'activate')
        ->assertHasErrors('fields.code')
        ->assertSee('This code is not right.');
});

it('shows the limit step when every device is in use', function () {
    TestDevice::bind(activated: false);
    $this->office->refuse('activate', 'device_limit_reached_app', 409);

    Livewire::test(Activate::class)
        ->set('fields.code', '1234567812345678')
        ->call('run', 'activate')
        ->assertSee('Every device is in use')
        ->call('run', 'try_again')
        ->assertSee('Activate this device');
});

it('says when the office can not be reached', function () {
    TestDevice::bind(activated: false);
    $this->office->down();

    Livewire::test(Activate::class)
        ->set('fields.code', '1234567812345678')
        ->call('run', 'activate')
        ->assertSee('Sylloo can not be reached.');
});

it('goes on to Sign in', function () {
    TestDevice::bind(activated: false);

    Livewire::test(Activate::class)
        ->set('fields.code', '1234-5678-1234-5678')
        ->call('run', 'activate')
        ->assertSee('Device activated')
        ->call('run', 'continue')
        ->assertRedirect(route('sylloo.sign-in'));
});

it('shows This device on an activated device: business, LIVE or PRACTICE, the device', function () {
    TestDevice::bind(['license_mode' => 'practice']);

    Livewire::test(Activate::class)
        ->assertSee('This device')
        ->assertSee('XYZ Trading')
        ->assertSee('PRACTICE')
        ->assertSee('Counter 1')
        ->assertSee('Use another activation code')
        ->assertDontSee('Device activated')
        ->call('run', 'back')
        ->assertRedirect(url('/'));
});

it('releases the activation fully for another code after warning', function () {
    $device = TestDevice::bind();

    $page = Livewire::test(Activate::class)
        ->call('run', 'use_another_code')
        ->assertSee('Use another code?')
        ->assertSee('The activation of this device for XYZ Trading (LIVE) ends on this device, and everyone signed in here is signed out.');

    $this->office->assertNotCalled('deactivate');

    $page->call('run', 'answer_yes')->assertRedirect(route('sylloo.state'));

    $this->office->assertCalled('deactivate');
    expect($device->state->isReleasing())->toBeTrue()
        ->and($device->release->calls)->toContain('stopWork');
});

it('keeps the activation when the office can not be reached', function () {
    $device = TestDevice::bind();
    $this->office->down();

    Livewire::test(Activate::class)
        ->call('run', 'use_another_code')
        ->call('run', 'answer_yes')
        ->assertSee('Using another activation code needs the internet. Connect and try again.')
        ->assertNoRedirect();

    expect($device->state->isActivated())->toBeTrue()->and($device->state->isReleasing())->toBeFalse();
});

it('sends a releasing device to its Releasing screen', function () {
    TestDevice::bind(['device_releasing' => true]);

    Livewire::test(Activate::class)->assertRedirect(route('sylloo.state'));
});

it('shows This device in an app that leaves Activate when activated, when asked for it', function () {
    config(['sylloo-app-base.screens.activate.when_activated' => 'leave']);
    TestDevice::bind();

    Livewire::test(Activate::class)->assertRedirect(route('sylloo.sign-in'));
    Livewire::withQueryParams(['details' => '1'])->test(Activate::class)->assertSee('Use another activation code');
});

it('runs no action the view does not offer', function () {
    TestDevice::bind(activated: false);

    Livewire::test(Activate::class)->call('run', 'continue')->assertNoRedirect();
});

it('shows what an app adds and asks its question first', function () {
    $device = TestDevice::bind(activated: false);
    $answered = [];

    app(Hooks::class)
        ->add('activate.below_code', fn () => 'Scan QR code')
        ->ask('activate', 'reset', function (array $context) use (&$answered) {
            return new Question(
                'Reset terminal for the new code?', 'Its data is removed.', 'Yes, reset', 'No', true,
                function (array $context) use (&$answered) {
                    $answered[] = 'yes';
                },
                function (array $context) use (&$answered) {
                    $answered[] = 'no';
                },
            );
        });

    $page = Livewire::test(Activate::class)
        ->assertSee('Scan QR code')
        ->set('fields.code', '1234567812345678')
        ->call('run', 'activate')
        ->assertSee('Reset terminal for the new code?');

    $this->office->assertNotCalled('activate');

    $page->call('run', 'answer_no')->assertSee('Activate this device');
    $this->office->assertNotCalled('activate');

    $page->call('run', 'activate')->call('run', 'answer_yes')->assertSee('Device activated');

    expect($answered)->toBe(['no', 'yes'])
        ->and($device->state->isActivated())->toBeTrue();
});

it("draws the code with wire-bootstrap's grouped code field (4 × 4 digits, formatted)", function () {
    TestDevice::bind(activated: false);

    Livewire::test(Activate::class)
        ->assertSeeHtml('wb-code')
        ->assertSeeHtml('wbCode(')
        ->assertSeeHtml('\u0022groups\u0022:[4,4,4,4]')
        ->assertSeeHtml('\u0022separator\u0022:\u0022-\u0022')
        ->assertSeeHtml('\u0022alphanumeric\u0022:false')
        ->assertSeeHtml('\u0022formatted\u0022:true')
        ->assertDontSeeHtml('syllooCodeField');
});
