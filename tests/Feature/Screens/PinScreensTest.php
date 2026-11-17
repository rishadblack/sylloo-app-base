<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Rishadblack\SyllooAppBase\Events\PersonSignedIn;
use Rishadblack\SyllooAppBase\Events\PinSaved;
use Rishadblack\SyllooAppBase\Events\SignInEnded;
use Rishadblack\SyllooAppBase\Livewire\CreatePin;
use Rishadblack\SyllooAppBase\Livewire\Lock;
use Rishadblack\SyllooAppBase\Livewire\Pin;
use Rishadblack\SyllooAppBase\Pin\Presence;
use Rishadblack\SyllooAppBase\Screens\Handover;
use Rishadblack\SyllooAppBase\Screens\Hooks;
use Rishadblack\SyllooAppBase\Testing\PretendOffice;
use Rishadblack\SyllooAppBase\Testing\TestDevice;

beforeEach(function () {
    Sleep::fake();
    $this->office = PretendOffice::start();
    $this->device = TestDevice::bind();
});

describe('create PIN', function () {
    it('saves the PIN at the office and lets the person in', function () {
        Event::fake([PinSaved::class, PersonSignedIn::class]);
        $rahim = $this->device->addMember(pin: null);
        Handover::put('member', $rahim->memberKey());

        Livewire::test(CreatePin::class)
            ->assertSee('Create your PIN')
            ->assertDontSee('sylloo-hook')
            ->set('fields.pin', '1357')
            ->set('fields.pin_confirmation', '1357')
            ->call('run', 'save')
            ->assertRedirect(url('/'));

        $this->office->assertCalled('pin', fn (Request $request) => $request['pin'] === '1357' && $request->hasHeader('Authorization', 'Bearer token-member-1'));
        expect(Hash::check('1357', (string) $this->device->members->pinHash($rahim)))->toBeTrue()
            ->and(app(Presence::class)->memberKey('default'))->toBe('member-1');
        Event::assertDispatched(PinSaved::class);
        Event::assertDispatched(PersonSignedIn::class);
    });

    it('refuses an easy PIN and two different ones', function () {
        Handover::put('member', $this->device->addMember(pin: null)->memberKey());

        Livewire::test(CreatePin::class)
            ->set('fields.pin', '1234')
            ->set('fields.pin_confirmation', '1234')
            ->call('run', 'save')
            ->assertHasErrors('fields.pin')
            ->set('fields.pin', '1357')
            ->set('fields.pin_confirmation', '1358')
            ->call('run', 'save')
            ->assertHasErrors('fields.pin_confirmation');

        $this->office->assertNotCalled('pin');
    });

    it('needs the office', function () {
        Handover::put('member', $this->device->addMember(pin: null)->memberKey());
        $this->office->down();

        Livewire::test(CreatePin::class)
            ->set('fields.pin', '1357')
            ->set('fields.pin_confirmation', '1357')
            ->call('run', 'save')
            ->assertSee('This needs the internet.')
            ->assertNoRedirect();
    });

    it('goes to Sign in without a person', function () {
        Livewire::test(CreatePin::class)->assertRedirect(route('sylloo.sign-in'));
    });

    it('shows what an app adds', function () {
        Handover::put('member', $this->device->addMember(pin: null)->memberKey());
        app(Hooks::class)->add('create_pin.bottom', fn () => 'Same PIN on the till');

        Livewire::test(CreatePin::class)->assertSee('Same PIN on the till');
    });
});

describe('PIN pad', function () {
    it("draws wire-bootstrap's PIN pad, which sends the pad's action on wb-pin-submit", function () {
        Event::fake([PersonSignedIn::class]);
        Handover::put('member', $this->device->addMember()->memberKey());
        $this->office->down();

        Livewire::test(Pin::class)
            ->assertSeeHtml('wb-pin-pad')
            ->assertSeeHtml('wbPinPad(')
            ->assertSeeHtml('\u0022submit\u0022:null')
            ->assertSeeHtml('x-on:wb-pin-submit="$wire.run(\'enter\')"')
            ->assertSeeHtml('keydown.window')
            ->assertDontSeeHtml('syllooPinPad')
            ->set('fields.pin', '1357')
            ->call('run', 'enter')
            ->assertRedirect(url('/'));

        Event::assertDispatched(PersonSignedIn::class);
    });

    it('lets the person in offline within the offline days', function () {
        Event::fake([PersonSignedIn::class]);
        Handover::put('member', $this->device->addMember()->memberKey());
        $this->office->down();

        Livewire::test(Pin::class)
            ->assertSee('Hello, Rahim')
            ->assertDontSee('sylloo-hook')
            ->set('fields.pin', '1357')
            ->call('run', 'enter')
            ->assertRedirect(url('/'));

        Event::assertDispatched(PersonSignedIn::class, fn (PersonSignedIn $event) => $event->offline);
    });

    it('asks the office after the offline days', function () {
        Handover::put('member', $this->device->addMember(offline: false)->memberKey());

        Livewire::test(Pin::class)
            ->set('fields.pin', '1357')
            ->call('run', 'enter')
            ->assertRedirect(url('/'));

        $this->office->assertCalled('me');
    });

    it('needs the office after the offline days', function () {
        Handover::put('member', $this->device->addMember(offline: false)->memberKey());
        $this->office->down();

        Livewire::test(Pin::class)
            ->set('fields.pin', '1357')
            ->call('run', 'enter')
            ->assertSee('This needs the internet.')
            ->assertNoRedirect();
    });

    it('counts wrong PINs and ends the sign-in at the fifth', function () {
        $rahim = $this->device->addMember();
        Handover::put('member', $rahim->memberKey());

        $page = Livewire::test(Pin::class);

        foreach (range(1, 4) as $try) {
            $page->set('fields.pin', '2468')->call('run', 'enter')->assertHasErrors('fields.pin');
        }

        $page->assertSee('1 tries left');
        $page->set('fields.pin', '2468')->call('run', 'enter')->assertRedirect(route('sylloo.sign-in'));

        expect($rahim->hasValidToken())->toBeFalse()
            ->and(Handover::pullProblem())->toContain('Five wrong PINs');
    });

    it('sends "Forgot PIN" to the browser sign-in', function () {
        Handover::put('member', $this->device->addMember()->memberKey());

        Livewire::test(Pin::class)->call('run', 'forgot')->assertRedirect(route('sylloo.sign-in'));

        expect(Handover::get('intent'))->toBe(Handover::INTENT_FORGOT_PIN);
    });

    it('shows what an app adds below the pad', function () {
        Handover::put('member', $this->device->addMember()->memberKey());
        app(Hooks::class)->add('pin.below_pad', fn () => 'Counter 1 opens at 9');

        Livewire::test(Pin::class)->assertSee('Counter 1 opens at 9');
    });
});

describe('lock', function () {
    it('opens again with the PIN of the person who is in', function () {
        $rahim = $this->device->addMember();
        $this->device->enter($rahim);

        Livewire::test(Lock::class)
            ->assertSee('Locked')
            ->assertDontSee('sylloo-hook')
            ->set('fields.pin', '1357')
            ->call('run', 'enter')
            ->assertRedirect(url('/'));

        expect(app(Presence::class)->isLocked())->toBeFalse();
    });

    it('stays locked on a wrong PIN', function () {
        $this->device->enter($this->device->addMember());

        Livewire::test(Lock::class)
            ->set('fields.pin', '2468')
            ->call('run', 'enter')
            ->assertHasErrors('fields.pin')
            ->assertNoRedirect();

        expect(app(Presence::class)->isLocked())->toBeTrue();
    });

    it('lets another person sign in', function () {
        $rahim = $this->device->addMember();
        $this->device->enter($rahim);

        Livewire::test(Lock::class)->call('run', 'switch_person')->assertRedirect(route('sylloo.sign-in'));

        expect(app(Presence::class)->isSignedIn())->toBeFalse()
            ->and($rahim->hasValidToken())->toBeTrue();
    });

    it('signs out after asking', function () {
        Event::fake([SignInEnded::class]);
        $rahim = $this->device->addMember();
        $this->device->enter($rahim);

        Livewire::test(Lock::class)
            ->call('run', 'sign_out')
            ->assertSee('Sign out on this device?')
            ->call('run', 'answer_yes')
            ->assertRedirect(route('sylloo.sign-in'));

        expect($rahim->hasValidToken())->toBeFalse();
        $this->office->assertCalled('sign-out');
        Event::assertDispatched(SignInEnded::class);
    });

    it('shows what an app adds below the pad', function () {
        $this->device->enter($this->device->addMember());
        app(Hooks::class)->add('lock.below_pad', fn () => '3 sales waiting');

        Livewire::test(Lock::class)->assertSee('3 sales waiting');
    });
});
