<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Rishadblack\SyllooAppBase\Contracts\MessagingPhone;
use Rishadblack\SyllooAppBase\Livewire\Messages;
use Rishadblack\SyllooAppBase\Livewire\MessagesAccept;
use Rishadblack\SyllooAppBase\Messaging\PhoneMessaging;
use Rishadblack\SyllooAppBase\Pin\Presence;
use Rishadblack\SyllooAppBase\Screens\Hooks;
use Rishadblack\SyllooAppBase\Screens\Question;
use Rishadblack\SyllooAppBase\Screens\ScreenRoutes;
use Rishadblack\SyllooAppBase\Testing\FakeMessagingPhone;
use Rishadblack\SyllooAppBase\Testing\PretendOffice;
use Rishadblack\SyllooAppBase\Testing\TestDevice;

/**
 * `GET messages` as the office answers it (device-api.md §7).
 *
 * @return array<string, mixed>
 */
function inboxAnswer(array $changes = []): array
{
    return array_replace([
        'connected' => true,
        'pairing' => ['state' => 'accepted', 'asked_at' => '2026-11-18T08:00:00Z', 'business' => 'XYZ Trading'],
        'paused' => false,
        'paused_reason' => null,
        'sim' => ['slot' => 0, 'label' => 'SIM 1 · Grameenphone'],
        'daily_limit' => 200,
        'sent_today' => 12,
        'pace_seconds' => 8,
        'messages' => [['id' => '01JBQ7Y2Z3A4B5C6D7E8F9G0H1', 'parts' => 1, 'waiting_since' => '2026-11-18T09:00:00Z']],
    ], $changes);
}

function askedInbox(): array
{
    return inboxAnswer(['connected' => false, 'pairing' => ['state' => 'requested', 'asked_at' => '2026-11-18T08:00:00Z', 'business' => 'XYZ Trading'], 'sim' => null, 'messages' => []]);
}

beforeEach(function () {
    Sleep::fake();
    $this->office = PretendOffice::start();
    $this->device = TestDevice::bind();
});

describe('switched off', function () {
    it('draws no route and offers no menu entry unless the app turns phone SMS on', function () {
        expect(ScreenRoutes::screens()['messages']['drawn'])->toBeFalse()
            ->and(ScreenRoutes::screens()['messages_accept']['drawn'])->toBeFalse()
            ->and(app(PhoneMessaging::class)->enabled())->toBeFalse()
            ->and(app(PhoneMessaging::class)->menuEntry())->toBeNull()
            ->and(app(PhoneMessaging::class)->keepGoing())->toBeNull();

        $this->office->assertNotCalled('messages');
    });

    it('says the device can not send SMS without a phone that can', function () {
        config(['sylloo-app-base.messages.enabled' => true]);

        Livewire::test(MessagesAccept::class)->assertSee('SMS not available');
        Livewire::test(Messages::class)->assertSee('SMS not available');

        app()->instance(MessagingPhone::class, new FakeMessagingPhone(isAvailable: false));

        expect(app(PhoneMessaging::class)->enabled())->toBeFalse();
        $this->office->assertNotCalled('messages');
    });

    it('draws both routes once on', function () {
        FakeMessagingPhone::bind();

        expect(ScreenRoutes::screens()['messages']['drawn'])->toBeTrue()
            ->and(ScreenRoutes::screens()['messages']['path'])->toBe('messages')
            ->and(ScreenRoutes::screens()['messages_accept']['path'])->toBe('messages/accept');
    });
});

describe('the accept screen', function () {
    beforeEach(function () {
        config(['sylloo-app-base.messages.enabled' => true]);
        ScreenRoutes::register();
        Route::getRoutes()->refreshNameLookups();
        $this->rahim = $this->device->addMember();
        $this->device->enter($this->rahim);
    });

    it('asks Android first, then names the SIM and who pays', function () {
        $phone = FakeMessagingPhone::bind(permission: false);
        $this->office->answer('messages', askedInbox());

        $page = Livewire::test(MessagesAccept::class)
            ->assertSee("Send the shop's SMS from this phone?")
            ->assertSee('XYZ Trading asks this phone to send its SMS to customers.')
            ->assertSee('Allow SMS on this phone')
            ->call('run', 'allow_android');

        expect($phone->calls)->toBe(['askPermission']);

        $phone->permission = true;

        $page->call('run', 'poll')
            ->assertSee("This phone will send XYZ Trading's SMS from SIM 1 · Grameenphone. SMS are paid from this SIM.")
            ->assertSee('Allow')
            ->assertSee('Refuse');
    });

    it('says so when Android did not ask', function () {
        $phone = FakeMessagingPhone::bind(permission: false);
        $phone->asks = false;
        $this->office->answer('messages', askedInbox());

        Livewire::test(MessagesAccept::class)
            ->call('run', 'allow_android')
            ->assertSee('Android did not ask. Open the app settings and allow SMS.');
    });

    it('sends the yes with the chosen SIM and the member token, then starts the background job', function () {
        $phone = FakeMessagingPhone::bind(sims: [['slot' => 0, 'label' => 'SIM 1 · Grameenphone'], ['slot' => 1, 'label' => 'SIM 2 · Robi']]);
        $this->office->answer('messages', askedInbox())->answer('messages/pairing', inboxAnswer(['sim' => ['slot' => 1, 'label' => 'SIM 2 · Robi']]));

        Livewire::test(MessagesAccept::class)
            ->assertSee('SIM 2 · Robi')
            ->set('fields.sim', '1')
            ->call('run', 'accept')
            ->assertRedirect(route('sylloo.messages'));

        $this->office->assertCalled('messages/pairing', fn (Request $request) => $request['answer'] === 'accept'
            && $request['sim'] === ['slot' => 1, 'label' => 'SIM 2 · Robi']
            && $request->hasHeader('Authorization', 'Bearer token-member-1'));
        expect($phone->started)->toMatchArray(['device_key' => 'device-key-1', 'base_url' => rtrim((string) config('erp.base_url'), '/')]);
    });

    it('needs a SIM chosen that is in the phone', function () {
        FakeMessagingPhone::bind(sims: [['slot' => 0, 'label' => 'SIM 1 · Grameenphone'], ['slot' => 1, 'label' => 'SIM 2 · Robi']]);
        $this->office->answer('messages', askedInbox());

        Livewire::test(MessagesAccept::class)
            ->set('fields.sim', '5')
            ->call('run', 'accept')
            ->assertHasErrors('fields.sim')
            ->assertNoRedirect();

        $this->office->assertNotCalled('messages/pairing');
    });

    it('waits for the manager when the app asks at the messages_accept moment', function () {
        FakeMessagingPhone::bind();
        $this->office->answer('messages', askedInbox())->answer('messages/pairing', inboxAnswer());
        app(Hooks::class)->ask('messages_accept', 'manager', fn (array $context) => new Question('Manager approval', 'SIM '.($context['sim']['slot'] + 1).' pays.', 'Approve', 'Cancel'));

        Livewire::test(MessagesAccept::class)
            ->call('run', 'accept')
            ->assertSee('Manager approval')
            ->assertSee('SIM 1 pays.');

        $this->office->assertNotCalled('messages/pairing');
    });

    it('refuses: tells the office and stops the job', function () {
        $phone = FakeMessagingPhone::bind();
        $this->office->answer('messages', askedInbox())->answer('messages/pairing', inboxAnswer(['connected' => false, 'pairing' => ['state' => 'refused']]));

        Livewire::test(MessagesAccept::class)->call('run', 'refuse');

        $this->office->assertCalled('messages/pairing', fn (Request $request) => $request['answer'] === 'refuse' && ! isset($request['sim']));
        expect($phone->calls)->toContain('stop')->and($phone->started)->toBeNull();
    });

    it('asks for a SIM when the phone has none', function () {
        FakeMessagingPhone::bind(sims: []);
        $this->office->answer('messages', askedInbox());

        Livewire::test(MessagesAccept::class)->assertSee('No SIM card found');
    });

    it('has nothing to answer when the office asks nothing, and says when it is connected', function () {
        FakeMessagingPhone::bind();
        $this->office->answer('messages', inboxAnswer(['connected' => false, 'pairing' => null]));

        Livewire::test(MessagesAccept::class)->assertSee('Nothing to answer');

        $this->office->answer('messages', inboxAnswer());

        Livewire::test(MessagesAccept::class)->assertSee("This phone sends the shop's SMS")->assertSee('SIM 1 · Grameenphone');
    });

    it('stays when the office can not be reached', function () {
        FakeMessagingPhone::bind();
        $this->office->down();

        Livewire::test(MessagesAccept::class)->assertSee('Check again');
    });

    it('says the office refusal', function () {
        FakeMessagingPhone::bind();
        $this->office->answer('messages', askedInbox())->refuse('messages/pairing', 'messaging_not_allowed', 403, 'You may not answer for messaging.');

        Livewire::test(MessagesAccept::class)
            ->call('run', 'accept')
            ->assertSee('You may not answer for messaging.')
            ->assertNoRedirect();
    });

    it('sends a person without a sign-in to sign in first', function () {
        FakeMessagingPhone::bind();
        app(Presence::class)->leave();
        $this->rahim->forgetSignIn();
        $this->office->answer('messages', askedInbox());

        Livewire::test(MessagesAccept::class)->call('run', 'accept')->assertRedirect(route('sylloo.sign-in'));

        $this->office->assertNotCalled('messages/pairing');
    });
});

describe('the Messages screen', function () {
    beforeEach(function () {
        config(['sylloo-app-base.messages.enabled' => true]);
        ScreenRoutes::register();
        Route::getRoutes()->refreshNameLookups();
        $this->rahim = $this->device->addMember();
        $this->device->enter($this->rahim);
    });

    it('shows what waits, sent today of the limit, failed today and the SIM', function () {
        FakeMessagingPhone::bind(state: ['failed_today' => 2]);
        $this->office->answer('messages', inboxAnswer());

        Livewire::test(Messages::class)
            ->assertSee('SMS from this phone')
            ->assertSee("XYZ Trading's SMS go out from SIM 1 · Grameenphone.")
            ->assertSee('Waiting')
            ->assertSee('12 of 200')
            ->assertSee('Failed today')
            ->assertSee('Pause')
            ->assertDontSee('No internet');
    });

    it('pauses and resumes with the member token, and wakes the job on resume', function () {
        $phone = FakeMessagingPhone::bind();
        $this->office->answer('messages', inboxAnswer())->answer('messages/pause', ['paused' => true]);

        $page = Livewire::test(Messages::class)->call('run', 'pause');

        $this->office->assertCalled('messages/pause', fn (Request $request) => $request['paused'] === true && $request->hasHeader('Authorization', 'Bearer token-member-1'));

        $this->office->answer('messages', inboxAnswer(['paused' => true, 'paused_reason' => 'failures']));
        $page->call('run', 'poll')
            ->assertSee('Resume')
            ->assertSee('Paused after several failed SMS. Check the SIM balance, then resume.')
            ->call('run', 'resume');

        $this->office->assertCalled('messages/pause', fn (Request $request) => $request['paused'] === false);
        expect($phone->calls)->toContain('wake');
    });

    it('says what is missing: the internet or the signal', function () {
        FakeMessagingPhone::bind(state: ['service' => 'no_signal']);
        $this->office->answer('messages', inboxAnswer());

        Livewire::test(Messages::class)->assertSee('No mobile signal: the SMS wait until the signal is back.');

        FakeMessagingPhone::bind(state: ['online' => false, 'service' => 'radio_off']);

        Livewire::test(Messages::class)
            ->assertSee('No internet: the phone can not get the messages.')
            ->assertSee('Flight mode is on: SMS can not be sent.');
    });

    it('asks for Android permission when it was taken away', function () {
        FakeMessagingPhone::bind(permission: false);
        $this->office->answer('messages', inboxAnswer());

        Livewire::test(Messages::class)
            ->assertSee('Android does not allow this app to send SMS.')
            ->assertSee('Allow SMS on this phone');
    });

    it('offers the answer when the office asks this phone', function () {
        FakeMessagingPhone::bind();
        $this->office->answer('messages', askedInbox());

        Livewire::test(Messages::class)
            ->assertSee('XYZ Trading asks this phone to send its SMS.')
            ->call('run', 'answer')
            ->assertRedirect(route('sylloo.messages-accept'));
    });

    it('wakes the job on Check now', function () {
        $phone = FakeMessagingPhone::bind();
        $this->office->answer('messages', inboxAnswer());

        Livewire::test(Messages::class)->call('run', 'check_now');

        expect($phone->calls)->toContain('wake');
    });
});

describe('keeping the job going', function () {
    it('hands the key over while connected', function () {
        $phone = FakeMessagingPhone::bind();
        $this->office->answer('messages', inboxAnswer());

        expect(app(PhoneMessaging::class)->keepGoing())->toMatchArray(['connected' => true])
            ->and($phone->started['device_key'])->toBe('device-key-1')
            ->and($phone->started['app_version'])->not->toBe('');
    });

    it('stops it when the connection ended or the office turned it off, and only wakes it offline', function () {
        $phone = FakeMessagingPhone::bind();
        $this->office->answer('messages', inboxAnswer(['connected' => false, 'pairing' => null]));
        app(PhoneMessaging::class)->keepGoing();
        expect($phone->calls)->toBe(['stop']);

        $phone->calls = [];
        $this->office->refuse('messages', 'feature_off', 403);
        app(PhoneMessaging::class)->keepGoing();
        expect($phone->calls)->toBe(['stop']);

        $phone->calls = [];
        $this->office->down();
        app(PhoneMessaging::class)->keepGoing();
        expect($phone->calls)->toBe(['wake']);
    });

    it('gives the menu entry, with a badge while the office asks', function () {
        FakeMessagingPhone::bind();
        $this->office->answer('messages', askedInbox());

        expect(app(PhoneMessaging::class)->menuEntry())->toBe(['screen' => 'messages_accept', 'label' => 'SMS from this phone', 'badge' => true])
            ->and(app(PhoneMessaging::class)->menuEntry(inboxAnswer()))->toBe(['screen' => 'messages', 'label' => 'SMS from this phone', 'badge' => false]);
    });

    it('has every text in en, bn and fi', function () {
        $keys = fn (string $lang) => array_keys(array_filter(require __DIR__."/../../../resources/lang/{$lang}/device.php", fn ($value, $key) => str_starts_with($key, 'messages_'), ARRAY_FILTER_USE_BOTH));

        expect($keys('bn'))->toBe($keys('en'))->and($keys('fi'))->toBe($keys('en'))->and(count($keys('en')))->toBeGreaterThan(40);
    });
});
