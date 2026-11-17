<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Rishadblack\SyllooAppBase\Events\PersonSignedIn;
use Rishadblack\SyllooAppBase\Livewire\SignIn;
use Rishadblack\SyllooAppBase\Pin\Presence;
use Rishadblack\SyllooAppBase\Screens\Handover;
use Rishadblack\SyllooAppBase\Screens\Hooks;
use Rishadblack\SyllooAppBase\Testing\PretendOffice;
use Rishadblack\SyllooAppBase\Testing\RecordingBrowser;
use Rishadblack\SyllooAppBase\Testing\TestDevice;

beforeEach(function () {
    Sleep::fake();
    $this->office = PretendOffice::start();
    $this->browser = RecordingBrowser::bind();
    $this->device = TestDevice::bind();
});

it('goes to Activate on a device that is not activated', function () {
    TestDevice::bind(activated: false);

    Livewire::test(SignIn::class)->assertRedirect(route('sylloo.activate'));
});

it('opens the browser and waits for Allow', function () {
    $page = Livewire::test(SignIn::class)
        ->assertSee('Sign in with browser')
        ->assertDontSee('sylloo-hook')
        ->call('run', 'browser')
        ->assertSee('Waiting for your browser');

    expect($this->browser->last())->toStartWith('https://demo.sylloo.app/employee/device/sign-in/');
    $this->office->assertCalled('sign-in/start', fn (Request $request) => strlen((string) $request['challenge']) === 64);

    $this->office->pending();
    $page->call('run', 'poll')->assertSee('Waiting for your browser')->assertNoRedirect();
});

it('signs an employee in and asks for a new PIN', function () {
    Event::fake([PersonSignedIn::class]);

    Livewire::test(SignIn::class)
        ->call('run', 'browser')
        ->call('run', 'poll')
        ->assertRedirect(route('sylloo.create-pin'));

    $member = $this->device->members->member('employee-01JD89FAQDDDDDDDDDDDDDDDDD');

    expect($member?->memberName())->toBe('Karim Hossain')
        ->and($member?->hasValidToken())->toBeTrue()
        ->and(Handover::get('member'))->toBe('employee-01JD89FAQDDDDDDDDDDDDDDDDD');
    $this->office->assertCalled('sign-in/finish', fn (Request $request) => strlen((string) $request['verifier']) === 64);
    Event::assertNotDispatched(PersonSignedIn::class);
});

it('lets a member with a PIN in at once', function () {
    Event::fake([PersonSignedIn::class]);
    $this->office->asMember()->with('sign-in/finish', ['pin_set' => true, 'pin_hash' => Hash::make('2468')]);

    Livewire::test(SignIn::class)
        ->call('run', 'browser')
        ->call('run', 'poll')
        ->assertRedirect(url('/'));

    expect(app(Presence::class)->memberKey('default'))->toBe('member-01JD89FAQDDDDDDDDDDDDDDDDD');
    Event::assertDispatched(PersonSignedIn::class, fn (PersonSignedIn $event) => ! $event->offline && $event->person?->kind === 'member');
});

it('asks for a new PIN after "Forgot PIN" even when one is known', function () {
    Handover::put('intent', Handover::INTENT_FORGOT_PIN);
    $this->office->asMember()->with('sign-in/finish', ['pin_set' => true, 'pin_hash' => Hash::make('2468')]);

    Livewire::test(SignIn::class)
        ->assertSee('then choose a new PIN')
        ->call('run', 'browser')
        ->call('run', 'poll')
        ->assertRedirect(route('sylloo.create-pin'));
});

it('shows the office refusal and starts again', function () {
    $this->office->refuse('sign-in/finish', 'phone_needs_approval', 403);

    Livewire::test(SignIn::class)
        ->call('run', 'browser')
        ->call('run', 'poll')
        ->assertSee('HR must approve this phone first.')
        ->assertSee('Sign in with browser');
});

it('keeps waiting while the office can not be reached', function () {
    $page = Livewire::test(SignIn::class)->call('run', 'browser');

    $this->office->down();

    $page->call('run', 'poll')->assertSee('Waiting for your browser')->assertSee('Sylloo can not be reached.');
});

it('cancels the request', function () {
    Livewire::test(SignIn::class)
        ->call('run', 'browser')
        ->call('run', 'cancel')
        ->assertSee('Sign in with browser');
});

it('shows the people signed in here and opens the PIN of the one tapped', function () {
    $this->device->addMember('member-1', 'Rahim');

    Livewire::test(SignIn::class)
        ->assertSee('Rahim')
        ->call('run', 'pick', 'member-1')
        ->assertRedirect(route('sylloo.pin'));

    expect(Handover::get('member'))->toBe('member-1');
});

it('shows what an app adds below the tiles', function () {
    app(Hooks::class)->add('sign_in.below_tiles', fn (array $context) => 'Shift starts at 9 at '.$context['business']);

    Livewire::test(SignIn::class)->assertSee('Shift starts at 9 at XYZ Trading');
});
