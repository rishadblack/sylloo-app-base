<?php

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Rishadblack\SyllooAppBase\Erp\OfficeAnswer;
use Rishadblack\SyllooAppBase\Erp\OfficeOutcome;
use Rishadblack\SyllooAppBase\Erp\Refusal;
use Rishadblack\SyllooAppBase\Livewire\Activate;
use Rishadblack\SyllooAppBase\Livewire\Pin;
use Rishadblack\SyllooAppBase\Livewire\SignIn;
use Rishadblack\SyllooAppBase\Livewire\State;
use Rishadblack\SyllooAppBase\Screens\Handover;
use Rishadblack\SyllooAppBase\Screens\Hooks;
use Rishadblack\SyllooAppBase\Screens\Question;
use Rishadblack\SyllooAppBase\Screens\SignInScreen;
use Rishadblack\SyllooAppBase\Testing\PretendOffice;
use Rishadblack\SyllooAppBase\Testing\RecordingBrowser;
use Rishadblack\SyllooAppBase\Testing\TestDevice;

/*
 * The switches and hooks the till needed to use the shared Activate, Sign in, PIN and state screens (P1 part 3). Each
 * is off by default: the other apps see no change (their own tests stay as they were).
 */

beforeEach(function () {
    Sleep::fake();
    $this->office = PretendOffice::start();
    $this->browser = RecordingBrowser::bind();
    $this->hooks = app(Hooks::class);
});

describe('activate', function () {
    it('leaves an activated device for sign in, and a releasing one for its state screen', function () {
        config(['sylloo-app-base.screens.activate.when_activated' => 'leave']);
        TestDevice::bind();

        Livewire::test(Activate::class)->assertRedirect(route('sylloo.sign-in'));

        TestDevice::bind(['device_releasing' => true]);

        Livewire::test(Activate::class)->assertRedirect(route('sylloo.state'));
    });

    it('refuses an empty device name when the app requires one', function () {
        config(['sylloo-app-base.screens.activate.device_name_required' => true]);
        TestDevice::bind(activated: false);

        Livewire::test(Activate::class)
            ->set('fields.code', '1234-5678-1234-5678')
            ->set('fields.device_name', '  ')
            ->call('run', 'activate')
            ->assertHasErrors('fields.device_name')
            ->assertSee('Give this device a name.');

        $this->office->assertNotCalled('activate');
    });

    it('runs the app\'s steps on Continue and after "Use another code"', function () {
        config(['sylloo-app-base.screens.activate.ask_use_another_code' => false]);
        TestDevice::bind(activated: false);
        $steps = [];
        $this->hooks->step('activate.continue', function () use (&$steps) {
            $steps[] = 'continue';
        });
        $this->hooks->step('use_another_code', function () use (&$steps) {
            $steps[] = 'another';
        });

        Livewire::test(Activate::class)->set('fields.code', '1234-5678-1234-5678')->call('run', 'activate')
            ->call('run', 'continue')->assertRedirect(route('sylloo.sign-in'));
        Livewire::test(Activate::class)->call('run', 'use_another_code')->call('run', 'answer_yes')->assertRedirect(route('sylloo.state'));

        expect($steps)->toBe(['continue', 'another']);
        $this->office->assertCalled('deactivate');
    });

    it('shows the toast of an app question\'s "no"', function () {
        TestDevice::bind(activated: false);
        $this->hooks->ask('activate', 'reset', fn () => new Question('Reset?', 'Old data.', 'Yes', 'No', true, onCancel: fn () => ['text' => 'Nothing changed.', 'variant' => 'info']));

        Livewire::test(Activate::class)
            ->set('fields.code', '1234-5678-1234-5678')
            ->call('run', 'activate')
            ->call('run', 'answer_no')
            ->assertSet('state.toast', ['text' => 'Nothing changed.', 'variant' => 'info'])
            ->assertSet('state.question', null);

        $this->office->assertNotCalled('activate');
    });
});

describe('refusals', function () {
    it('lets the app choose where a refusal goes', function () {
        TestDevice::bind();
        $this->office->refuse('sign-in/start', 'business_suspended', 403);
        $this->hooks->goOnRefusal(fn (array $refusal) => $refusal['code'] === 'business_suspended' ? 'state' : null);

        Livewire::test(SignIn::class)->call('run', 'browser')->assertRedirect(route('sylloo.state'));

        expect(app(Hooks::class)->refusalGo(['code' => 'plan_ended'], []))->toBeNull();
    });

    it('fills in the plan\'s allowed devices, and lists the devices in use at sign in', function () {
        config(['sylloo-app-base.screens.sign_in.device_limit' => 'list', 'sylloo-app-base.texts.refusals' => 'device.refused_']);
        app('translator')->addLines(['device.refused_device_limit_reached_app' => 'Your plan allows :allowed devices.'], 'en');
        TestDevice::bind();
        $this->office->answer('sign-in/finish', ['code' => 'device_limit_reached_app', 'message' => 'Full', 'allowed' => 2, 'devices' => [['name' => 'Counter 9', 'last_seen_at' => null, 'last_user' => null]]], 403);

        Livewire::test(SignIn::class)
            ->call('run', 'browser')
            ->call('run', 'poll')
            ->assertSet('state.step', 'limit')
            ->assertSee('Your plan allows 2 devices.')
            ->assertSee('Counter 9')
            ->call('run', 'try_again')
            ->assertSet('state.step', 'choose');

        expect(Refusal::text(['code' => 'device_limit_reached_app'], ['allowed' => 5]))->toBe('Your plan allows 5 devices.')
            ->and((new OfficeAnswer(OfficeOutcome::Refused, ['code' => 'device_limit_reached_app'], 403))->text(['allowed' => 3]))->toBe('Your plan allows 3 devices.');
    });
});

describe('state screens', function () {
    it('cleans a released device as Releasing opens, when the app asks', function () {
        $device = TestDevice::bind(['device_releasing' => true]);

        Livewire::test(State::class)->assertNoRedirect();
        expect($device->release->calls)->toBe([]);

        config(['sylloo-app-base.screens.state.release_on_open' => true]);

        Livewire::test(State::class)->assertRedirect(route('sylloo.activate'));
        expect($device->release->calls)->toContain('removeLocalData');
    });

    it('says nothing changed after a check, and Try again checks again', function () {
        config(['sylloo-app-base.screens.state.still_blocked' => true]);
        TestDevice::bind(['blocked' => 'business_suspended']);
        $this->office->with('state', ['blocked' => 'business_suspended']);

        Livewire::test(State::class)
            ->call('run', 'check_again')
            ->assertSet('state.toast', ['text' => 'Nothing changed yet.', 'variant' => 'info'])
            ->call('run', 'try_again')
            ->assertSet('state.step', 'business_suspended');

        $this->office->assertCalled('state');
    });

    it('says where to renew when there is no renew address', function () {
        config(['sylloo-app-base.app.portal_url' => null]);
        TestDevice::bind(['blocked' => 'plan_ended']);

        Livewire::test(State::class)
            ->call('run', 'renew_plan')
            ->assertSet('state.toast', ['text' => 'Open the Sylloo portal in your browser to renew the plan.', 'variant' => 'info']);

        expect($this->browser->opened)->toBe([]);
    });
});

describe('sign in', function () {
    it('shows the business, the device and its mode above the screen when the app asks', function () {
        config(['sylloo-app-base.screens.business_header' => ['sign_in']]);
        $device = TestDevice::bind();
        $device->addMember(name: 'Ayesha Rahman');

        Livewire::test(SignIn::class)
            ->assertSeeHtml('sylloo-business-header')
            ->assertSee('XYZ Trading')
            ->assertSee('Counter 1')
            ->assertSee('LIVE')
            ->assertSee('AR');

        expect(SignInScreen::initialsOf('Ayesha Rahman'))->toBe('AR')
            ->and(SignInScreen::initialsOf('Karim'))->toBe('K');
    });

    it('names the browser\'s host while waiting and gives the app the address', function () {
        TestDevice::bind();
        $seen = [];
        $this->hooks->add('sign_in.waiting', function (array $context) use (&$seen) {
            $seen[] = $context['browser_url'] ?? null;

            return null;
        });
        app('translator')->addLines(['device.sign_in_waiting_text' => 'We opened :host.'], 'en', 'sylloo-app-base');

        Livewire::test(SignIn::class)->call('run', 'browser')->assertSee('We opened demo.sylloo.app.');

        expect($seen[0])->toStartWith('https://demo.sylloo.app/');
    });

    it('refuses a browser address that is not a web page, and says so when the office can not be reached', function () {
        TestDevice::bind();
        $this->office->with('sign-in/start', ['browser_url' => 'javascript:alert(1)']);

        Livewire::test(SignIn::class)->call('run', 'browser')->assertSet('state.step', 'choose')->assertSee('Sylloo gave an answer we did not expect.');
        expect($this->browser->opened)->toBe([])
            ->and(SignInScreen::isWebPage('https://a.test'))->toBeTrue()
            ->and(SignInScreen::isWebPage('file:///C:/x.exe'))->toBeFalse();

        $this->office->down();

        Livewire::test(SignIn::class)->call('run', 'browser')->assertSet('state.step', 'choose')->assertSet('state.problem', __('sylloo-app-base::device.sign_in_unreachable'));
    });

    it('runs the app\'s steps on a tap and on a kept browser sign-in', function () {
        $device = TestDevice::bind();
        $member = $device->addMember();
        $steps = [];
        $this->hooks->step('sign_in.pick', function ($picked) use (&$steps) {
            $steps[] = 'pick:'.$picked->memberKey();
        });
        $this->hooks->step('sign_in.kept', function ($kept, $person) use (&$steps) {
            $steps[] = 'kept:'.$person->kind;
        });
        $this->office->asMember()->with('sign-in/finish', ['pin_set' => true, 'pin_hash' => Hash::make('2468')]);

        Livewire::test(SignIn::class)->call('run', 'pick', $member->memberKey())->assertRedirect(route('sylloo.pin'));
        Livewire::test(SignIn::class)->call('run', 'browser')->call('run', 'poll');

        expect($steps)->toBe(['pick:member-1', 'kept:member']);
    });
});

describe('PIN', function () {
    it('"Forgot PIN?" starts the browser sign-in at once and ends on Set a new PIN', function () {
        config(['sylloo-app-base.screens.pin.forgot_starts_browser' => true, 'sylloo-app-base.screens.pin.create_here' => true]);
        $device = TestDevice::bind();
        Handover::put('member', $device->addMember()->memberKey());

        Livewire::test(Pin::class)->call('run', 'forgot')->assertRedirect(route('sylloo.sign-in'));
        Livewire::test(SignIn::class)->assertSet('state.step', 'waiting')->call('run', 'poll')->assertRedirect(route('sylloo.pin'));

        Livewire::test(Pin::class)->assertSet('state.step', 'create')->assertSee('Set a new PIN');
        $this->office->assertCalled('sign-in/start');
    });

    it('sends a person without a PIN here to the browser when the app asks', function () {
        config(['sylloo-app-base.screens.pin.no_pin' => 'refuse', 'sylloo-app-base.screens.pin.create_here' => true]);
        $device = TestDevice::bind();
        Handover::put('member', $device->addMember(name: 'Karim', pin: null)->memberKey());

        Livewire::test(Pin::class)
            ->assertSet('state.step', 'pad')
            ->set('fields.pin', '1357')
            ->call('run', 'enter')
            ->assertHasErrors('fields.pin')
            ->assertSee('Karim has no PIN on this device yet.');
    });

    it('lets the app choose where a refusal of the PIN check goes', function () {
        $device = TestDevice::bind();
        Handover::put('member', $device->addMember(offline: false)->memberKey());
        $this->office->refuse('me', 'device_released', 401);
        $this->hooks->goOnRefusal(fn (array $refusal) => $refusal['call'] === 'me' ? 'state' : null);

        Livewire::test(Pin::class)->set('fields.pin', '1357')->call('run', 'enter')->assertRedirect(route('sylloo.state'));
    });
});
