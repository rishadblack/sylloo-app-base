<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Device\StateDefinition;
use Rishadblack\SyllooAppBase\Device\StateRegistry;
use Rishadblack\SyllooAppBase\Language\Language;
use Rishadblack\SyllooAppBase\Livewire\About;
use Rishadblack\SyllooAppBase\Livewire\Businesses;
use Rishadblack\SyllooAppBase\Livewire\Language as LanguagePage;
use Rishadblack\SyllooAppBase\Livewire\Modals\AboutModal;
use Rishadblack\SyllooAppBase\Livewire\PinSettings;
use Rishadblack\SyllooAppBase\Livewire\State;
use Rishadblack\SyllooAppBase\Pin\PinSignIn;
use Rishadblack\SyllooAppBase\Pin\Presence;
use Rishadblack\SyllooAppBase\Screens\Hooks;
use Rishadblack\SyllooAppBase\Support\AppInfo;
use Rishadblack\SyllooAppBase\Support\Texts;
use Rishadblack\SyllooAppBase\Testing\PretendOffice;
use Rishadblack\SyllooAppBase\Testing\RecordingBrowser;
use Rishadblack\SyllooAppBase\Testing\TestDevice;

beforeEach(function () {
    Sleep::fake();
    $this->office = PretendOffice::start();
    $this->browser = RecordingBrowser::bind();
});

describe('state screens', function () {
    it('shows the plan ended and opens the portal to renew', function () {
        TestDevice::bind(['plan' => ['active' => false, 'ends_on' => '2026-10-01', 'in_grace' => false, 'valid_until' => '2026-10-08'], 'business_portal_url' => 'https://sylloo.eu/businesses/1/billing']);

        Livewire::test(State::class)
            ->assertSee('Plan ended')
            ->assertSee('The plan of XYZ Trading has ended.')
            ->assertDontSee('sylloo-hook')
            ->call('run', 'renew_plan');

        expect($this->browser->last())->toBe('https://sylloo.eu/businesses/1/billing');
    });

    it('goes on when the office says the device may work again', function () {
        TestDevice::bind(['blocked' => 'business_suspended']);

        Livewire::test(State::class)
            ->assertSee('Business paused')
            ->call('run', 'check_again')
            ->assertRedirect(url('/'));

        $this->office->assertCalled('state');
    });

    it('stays when the office can not be reached', function () {
        TestDevice::bind(['blocked' => 'employee_app_off']);
        $this->office->down();

        Livewire::test(State::class)
            ->assertSee('Employee app turned off')
            ->call('run', 'check_again')
            ->assertSee('Employee app turned off')
            ->assertNoRedirect();
    });

    it('cleans a released device and goes to Activate', function () {
        $device = TestDevice::bind([DeviceState::RELEASING_SETTING => true]);

        Livewire::test(State::class)
            ->assertSee('This device is being released')
            ->call('run', 'poll')
            ->assertRedirect(route('sylloo.activate'));

        expect($device->release->calls)->toContain('removeLocalData')
            ->and($device->state->isActivated())->toBeFalse();
        $this->office->assertCalled('released');
    });

    it('shows a state the app registered, and what it adds to a shared one', function () {
        TestDevice::bind(['outlet_ready' => false]);
        app(StateRegistry::class)->register(
            new StateDefinition('almost_ready', 'Terminal almost ready', 'A manager must choose its outlet.', null, 'bi bi-shop'),
            fn (DeviceState $device) => $device->get('outlet_ready') === false,
        );
        app(Hooks::class)->add('state.almost_ready', fn () => '14 sales are safe on this terminal');

        Livewire::test(State::class)
            ->assertSee('Terminal almost ready')
            ->assertSee('14 sales are safe on this terminal');
    });

    it('never takes the state from a link: ?state=releasing on a working device releases nothing (audit H3)', function () {
        $device = TestDevice::bind();
        $device->enter($device->addMember());

        Livewire::withQueryParams(['state' => DeviceState::RELEASING])->test(State::class)->assertRedirect(url('/'));

        expect($device->release->calls)->toBe([])
            ->and($device->state->isActivated())->toBeTrue()
            ->and(app(Presence::class)->isSignedIn())->toBeTrue();
        $this->office->assertNotCalled('released');
    });

    it('goes home when nothing stops the device', function () {
        TestDevice::bind();

        Livewire::test(State::class)->assertRedirect(url('/'));
    });
});

describe('businesses', function () {
    it('lists the activation with its kind and opens its sign-in', function () {
        TestDevice::bind();

        Livewire::test(Businesses::class)
            ->assertSee('XYZ Trading · STAFF')
            ->assertDontSee('Add a business')
            ->assertDontSee('sylloo-hook')
            ->call('run', 'pick', 'default')
            ->assertRedirect(route('sylloo.sign-in'));
    });

    it('offers "Add a business" in a multi-business app and shows what an app adds', function () {
        config(['sylloo-app-base.app.multi_business' => true]);
        TestDevice::bind();
        app(Hooks::class)->add('businesses.bottom', fn () => 'Two businesses at most');

        Livewire::test(Businesses::class)
            ->assertSee('Add a business')
            ->assertSee('Two businesses at most');
    });
});

describe('PIN and lock settings', function () {
    beforeEach(function () {
        $this->device = TestDevice::bind();
        $this->rahim = $this->device->addMember();
        $this->device->enter($this->rahim);
    });

    it('changes the PIN after the current one', function () {
        Livewire::test(PinSettings::class)
            ->assertSee('PIN and lock')
            ->assertDontSee('sylloo-hook')
            ->set('fields.current_pin', '2468')
            ->set('fields.new_pin', '1593')
            ->set('fields.new_pin_confirmation', '1593')
            ->call('run', 'change_pin')
            ->assertHasErrors('fields.current_pin')
            ->set('fields.current_pin', '1357')
            ->set('fields.new_pin', '1593')
            ->set('fields.new_pin_confirmation', '1593')
            ->call('run', 'change_pin')
            ->assertHasNoErrors();

        $this->office->assertCalled('pin');
        expect(password_verify('1593', (string) $this->device->members->pinHash($this->rahim)))->toBeTrue();
    });

    it('asks for an empty Current PIN without counting a wrong try', function () {
        Livewire::test(PinSettings::class)
            ->set('fields.current_pin', '')
            ->set('fields.new_pin', '1593')
            ->set('fields.new_pin_confirmation', '1593')
            ->call('run', 'change_pin')
            ->assertHasErrors('fields.current_pin')
            ->assertSee('Type your current PIN.')
            ->assertDontSee('tries left');

        expect(PinSignIn::triesLeft($this->rahim))->toBe(PinSignIn::MAX_WRONG);
        $this->office->assertNotCalled('pin');
    });

    it('keeps the lock time the person chose', function () {
        Livewire::test(PinSettings::class)
            ->assertSet('fields.idle', '5')
            ->set('fields.idle', '15')
            ->call('run', 'save_idle')
            ->set('fields.idle', '7')
            ->call('run', 'save_idle')
            ->assertHasErrors('fields.idle');

        expect(Presence::idleMinutes($this->device->state))->toBe(15);
    });

    it('locks now', function () {
        Livewire::test(PinSettings::class)->call('run', 'lock_now')->assertRedirect(route('sylloo.lock'));

        expect(app(Presence::class)->isLocked())->toBeTrue();
    });

    it('deactivates after asking, then the release runs', function () {
        Livewire::test(PinSettings::class)
            ->call('run', 'deactivate')
            ->assertSee('Deactivate this device?')
            ->call('run', 'answer_yes')
            ->assertRedirect(route('sylloo.state'));

        $this->office->assertCalled('deactivate');
        expect($this->device->state->isReleasing())->toBeTrue();
    });

    it('never opens on a locked device (audit H3)', function () {
        app(Presence::class)->lock();

        Livewire::test(PinSettings::class)->assertRedirect(route('sylloo.lock'));
    });

    it('runs nothing once the device locked while it was open (audit H3)', function () {
        $page = Livewire::test(PinSettings::class);
        app(Presence::class)->lock();

        $page->set('fields.idle', '0')->call('run', 'save_idle')->assertRedirect(route('sylloo.lock'));
        $page->call('run', 'deactivate')->assertRedirect(route('sylloo.lock'));

        expect(Presence::idleMinutes($this->device->state))->toBe(5);
        $this->office->assertNotCalled('deactivate');
    });

    it('locks by itself when the idle time passed before an action', function () {
        $page = Livewire::test(PinSettings::class);
        $this->travel(6)->minutes();

        $page->set('fields.idle', '0')->call('run', 'save_idle')->assertRedirect(route('sylloo.lock'));

        expect(app(Presence::class)->isLocked())->toBeTrue()
            ->and(Presence::idleMinutes($this->device->state))->toBe(5);
    });

    it('shows what an app adds', function () {
        app(Hooks::class)->add('pin_settings.bottom', fn () => 'Ask HR to reset a forgotten PIN');

        Livewire::test(PinSettings::class)->assertSee('Ask HR to reset a forgotten PIN');
    });
});

describe('about and language', function () {
    it('shows the app and this activation', function () {
        TestDevice::bind();

        Livewire::test(About::class)
            ->assertSee('Sylloo App')
            ->assertSee('2.0.0')
            ->assertSee('XYZ Trading')
            ->assertSee('LIVE')
            ->assertDontSee('sylloo-hook');
    });

    it('marks a development build and its build number, in Bangla digits in Bangla', function () {
        TestDevice::bind();
        config(['sylloo-app-base.app.version' => '1.0.0', 'nativephp.version' => 'DEBUG', 'nativephp.version_code' => 2001]);

        expect(AppInfo::displayVersion())->toBe('1.0.0 DEBUG (2001)');

        Livewire::test(About::class)->assertSee('1.0.0 DEBUG (2001)');

        app()->setLocale('bn');

        Livewire::test(About::class)->assertSee('১.০.০ DEBUG (২০০১)');
        expect(Texts::get('pin_wrong', ['tries' => 3]))->toContain('৩')
            ->and(Texts::get('pin_wrong', ['tries' => 3], 'en'))->toContain('3 tries left');
    });

    it('shows About in a modal with what an app adds', function () {

        TestDevice::bind();
        app(Hooks::class)->add('about.rows', fn () => 'Till number 3');

        Livewire::test(AboutModal::class)
            ->call('openScreenModal')
            ->assertDispatched('wire-modals:open')
            ->assertSee('Till number 3');
    });

    it('opens About and Language from the layout through the modal host', function () {
        TestDevice::bind();

        Livewire::test('wire-modals.host')
            ->call('load', ['component' => 'sylloo-app-base.about'])
            ->assertSee('Sylloo App')
            ->call('load', ['component' => 'sylloo-app-base.language'])
            ->assertSee('Suomi');
    });

    it('keeps the language chosen', function () {
        Storage::fake('local');
        TestDevice::bind();

        Livewire::test(LanguagePage::class)
            ->assertSee('Language')
            ->set('fields.language', 'bn')
            ->call('run', 'save')
            ->assertHasNoErrors();

        expect(Language::chosen())->toBe('bn')
            ->and(app()->getLocale())->toBe('bn');

        Livewire::test(LanguagePage::class)->assertSee('ভাষা');
    });

    it('refuses a language it does not have', function () {
        Storage::fake('local');
        TestDevice::bind();

        Livewire::test(LanguagePage::class)
            ->set('fields.language', 'de')
            ->call('run', 'save')
            ->assertHasErrors('fields.language');

        expect(Language::chosen())->toBeNull();
    });
});
