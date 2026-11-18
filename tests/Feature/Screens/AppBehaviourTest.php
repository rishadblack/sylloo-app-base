<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Rishadblack\SyllooAppBase\Contracts\ScreenNotices;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Device\StateDefinition;
use Rishadblack\SyllooAppBase\Device\StateRegistry;
use Rishadblack\SyllooAppBase\Erp\Refusal;
use Rishadblack\SyllooAppBase\Events\DeviceActivated;
use Rishadblack\SyllooAppBase\Events\PersonSignedIn;
use Rishadblack\SyllooAppBase\Livewire\Activate;
use Rishadblack\SyllooAppBase\Livewire\Pin;
use Rishadblack\SyllooAppBase\Livewire\SignIn;
use Rishadblack\SyllooAppBase\Livewire\State;
use Rishadblack\SyllooAppBase\Pin\Presence;
use Rishadblack\SyllooAppBase\Screens\Handover;
use Rishadblack\SyllooAppBase\Screens\Hooks;
use Rishadblack\SyllooAppBase\Screens\Notices\AlertScreenNotices;
use Rishadblack\SyllooAppBase\Screens\Notices\EventScreenNotices;
use Rishadblack\SyllooAppBase\Screens\Question;
use Rishadblack\SyllooAppBase\Screens\StateScreen;
use Rishadblack\SyllooAppBase\Testing\PretendOffice;
use Rishadblack\SyllooAppBase\Testing\RecordingBrowser;
use Rishadblack\SyllooAppBase\Testing\TestDevice;

/*
 * The behaviour an app with its own flow (the till) plugs into the shared screens: its notices, its activation steps,
 * its refusal texts, its state checks and sentences, its own sign-in finish, and a few cheap look pieces. Without them
 * every screen behaves as before.
 */

beforeEach(function () {
    Sleep::fake();
    $this->office = PretendOffice::start();
    $this->browser = RecordingBrowser::bind();
    $this->hooks = app(Hooks::class);
});

describe('notices', function () {
    it('uses livewire-alert by default', function () {
        expect(app(ScreenNotices::class))->toBeInstanceOf(AlertScreenNotices::class);
    });

    it('sends browser events when the app draws its own notices', function () {
        config(['sylloo-app-base.screens.notices' => 'events', 'sylloo-app-base.screens.questions' => 'alert']);
        app()->forgetInstance(ScreenNotices::class);
        TestDevice::bind(['blocked' => 'employee_app_off']);
        $this->office->down();

        expect(app(ScreenNotices::class))->toBeInstanceOf(EventScreenNotices::class);

        Livewire::test(State::class)
            ->call('run', 'check_again')
            ->assertDispatched('notify', type: 'warning', title: 'Sylloo can not be reached. Check the internet connection and try again.', message: null);

        $this->office->down(false);
        TestDevice::bind();
        $this->hooks->ask('use_another_code', 'reset', fn () => new Question('Reset terminal?', 'Another business.', 'Reset', 'Cancel', true, detail: '14 sales are safe'));

        $page = Livewire::test(Activate::class)->call('run', 'use_another_code');

        $page->assertDispatched('confirm-dialog', fn (string $name, array $params) => $params['componentId'] === $page->id()
            && $params['method'] === 'answerQuestion' && $params['params'] === [['yes' => true]]
            && $params['cancelMethod'] === 'answerQuestion' && $params['cancelParams'] === [['yes' => false]]
            && $params['title'] === 'Reset terminal?' && $params['message'] === "Another business.\n14 sales are safe" && $params['danger'] === true);

        $page->call('answerQuestion', ['yes' => true])->assertRedirect(route('sylloo.state'));
        $this->office->assertCalled('deactivate');
    });
});

describe('activate', function () {
    it('sends the app\'s fields, checks the answer, and runs its steps around keeping it', function () {
        $device = TestDevice::bind(activated: false);
        $order = [];
        Event::listen(DeviceActivated::class, function () use (&$order) {
            $order[] = 'event';
        });
        $this->hooks->payload('activate', fn (array $context) => ['pending_orders' => 3, 'code' => 'not-mine']);
        $this->hooks->check('activate.answer', function (array $answer) use (&$order) {
            $order[] = 'check';

            return null;
        });
        $this->hooks->step('activate.before_keep', function (array $answer, array $context) use (&$order, $device) {
            $order[] = 'before:'.($device->state->isActivated() ? 'kept' : 'not kept').':'.$context['code'];
        });
        $this->hooks->step('activate.after_keep', function (array $answer, array $context) use (&$order, $device) {
            $order[] = 'after:'.($device->state->isActivated() ? 'kept' : 'not kept').':'.$context['business'];
        });

        Livewire::test(Activate::class)
            ->set('fields.code', '1234-5678-1234-5678')
            ->call('run', 'activate')
            ->assertSee('Device activated');

        expect($order)->toBe(['check', 'before:not kept:1234567812345678', 'after:kept:Demo Ltd', 'event']);
        $this->office->assertCalled('activate', fn (Request $request) => $request['pending_orders'] === 3 && $request['code'] === '1234567812345678');
    });

    it('keeps nothing when the app refuses the answer', function () {
        $device = TestDevice::bind(activated: false);
        Event::fake([DeviceActivated::class]);
        $kept = false;
        $this->hooks->check('activate.answer', fn (array $answer) => isset($answer['terminal']['desktop_pos_id']) ? null : 'The office sent no terminal. Try again.');
        $this->hooks->step('activate.before_keep', function () use (&$kept) {
            $kept = true;
        });

        Livewire::test(Activate::class)
            ->set('fields.code', '1234-5678-1234-5678')
            ->call('run', 'activate')
            ->assertSee('The office sent no terminal. Try again.')
            ->assertDontSee('Device activated');

        expect($device->state->isActivated())->toBeFalse()->and($kept)->toBeFalse();
        Event::assertNotDispatched(DeviceActivated::class);
    });

    it('keeps nothing of an answer without a key', function () {
        $device = TestDevice::bind(activated: false);
        $this->office->with('activate', ['device_key' => '']);

        Livewire::test(Activate::class)
            ->set('fields.code', '1234-5678-1234-5678')
            ->call('run', 'activate')
            ->assertDontSee('Device activated');

        expect($device->state->isActivated())->toBeFalse();
    });

    it('uses another code right after activating without asking when the app turns the question off', function () {
        config(['sylloo-app-base.screens.activate.ask_use_another_code' => false]);
        $device = TestDevice::bind(activated: false);

        Livewire::test(Activate::class)
            ->set('fields.code', '1234-5678-1234-5678')
            ->call('run', 'activate')
            ->call('run', 'use_another_code')
            ->assertDontSee('Use another code?')
            ->assertRedirect(route('sylloo.state'));

        expect($device->state->isReleasing())->toBeTrue();
    });

    it('always asks before "Use another activation code" on This device, even when the app turns the question off', function () {
        config(['sylloo-app-base.screens.activate.ask_use_another_code' => false]);
        TestDevice::bind();

        Livewire::test(Activate::class)
            ->call('run', 'use_another_code')
            ->assertSee('Use another code?')
            ->assertNoRedirect();

        $this->office->assertNotCalled('deactivate');
    });
});

describe('refusals', function () {
    it('tells the app and uses its own text for a code', function () {
        TestDevice::bind(activated: false);
        app('translator')->addLines(['device.refused_invalid_code' => 'That code is not a Sylloo POS code.'], 'en');
        config(['sylloo-app-base.texts.refusals' => 'device.refused_']);
        $told = [];
        $this->hooks->refused(function (array $refusal, array $context) use (&$told) {
            $told[] = [$refusal['call'], $refusal['code'], $refusal['status'], $context['screen']];
        });
        $this->office->refuse('activate', 'invalid_code', 422);

        Livewire::test(Activate::class)
            ->set('fields.code', '1234-5678-1234-5678')
            ->call('run', 'activate')
            ->assertSee('That code is not a Sylloo POS code.');

        expect($told)->toBe([['activate', 'invalid_code', 422, 'activate']]);
    });

    it('maps codes one by one, and keeps the base\'s text for the others', function () {
        app('translator')->addLines(['pos.too_old' => 'Update Sylloo POS first.'], 'en');
        config(['sylloo-app-base.texts.refusals' => ['app_too_old' => 'pos.too_old', 'plan_ended' => 'pos.missing']]);

        expect(Refusal::text(['code' => 'app_too_old']))->toBe('Update Sylloo POS first.')
            ->and(Refusal::text(['code' => 'plan_ended']))->toBe(Refusal::text(['code' => 'plan_ended', 'message' => 'x']))
            ->and(Refusal::appTextKey('plan_ended'))->toBeNull();
    });
});

describe('state screens', function () {
    it('shows "Can\'t reach Sylloo" as a screen when the app asks for it', function () {
        config(['sylloo-app-base.screens.state.unreachable' => 'screen']);
        TestDevice::bind(['blocked' => 'employee_app_off']);
        $this->office->down();

        Livewire::test(State::class)
            ->call('run', 'check_again')
            ->assertSee('Sylloo can not be reached')
            ->assertSee('This device got no answer from Sylloo.')
            ->assertNoRedirect();
    });

    it('runs the app\'s checks on "Check again" and on the poll', function () {
        $device = TestDevice::bind(['blocked' => 'business_suspended']);
        $checks = 0;
        $this->hooks->step('state.check', function (array $context) use (&$checks, $device) {
            $checks++;
            $device->store->set('blocked', 'business_suspended');
        });

        Livewire::test(State::class)
            ->call('run', 'check_again')
            ->assertSee('Business paused')
            ->assertNoRedirect();

        // A state that polls (the app's own, every 5 seconds): its poll runs the checks too.
        app(StateRegistry::class)->register(new StateDefinition('waiting_for_hr', 'HR', 'Waiting for HR', pollSeconds: 5), fn (DeviceState $state) => $state->get('hr') === 'waiting');
        $device->store->set('blocked', null);
        $device->store->set('hr', 'waiting');
        $screen = app(StateScreen::class);
        $screen->act($screen->start(), 'poll');

        expect($checks)->toBe(2);
    });

    it('lets the app choose the sentences, with the date in its way and the business above', function () {
        app()->setLocale('en');
        config(['sylloo-app-base.screens.business_header' => ['state'], 'sylloo-app-base.screens.date_format' => 'j F Y']);
        TestDevice::bind(['plan' => ['active' => false, 'ends_on' => '2026-10-01', 'in_grace' => false, 'valid_until' => '2026-10-08']]);
        $this->hooks->sentences('plan_ended', fn (array $context) => $context['date'] !== null
            ? ['text' => 'The plan ended. Sales work until '.$context['date'].'.', 'todo' => null]
            : 'The plan ended.');

        Livewire::test(State::class)
            ->assertSee('Plan ended')
            ->assertSee('The plan ended. Sales work until 8 October 2026.')
            ->assertDontSee('sylloo-screen-todo')
            ->assertSeeHtml('sylloo-business-header')
            ->assertSee('XYZ Trading');

        expect(StateScreen::formatDate('2026-10-08'))->toBe('8 October 2026')
            ->and(StateScreen::formatDate(null))->toBe('')
            ->and(StateScreen::formatDate('not a date'))->toBe('not a date');

        config(['sylloo-app-base.screens.date_format' => null]);
        expect(StateScreen::formatDate('2026-10-08'))->toBe('08 Oct 2026');
    });

    it('shows no business header by default', function () {
        TestDevice::bind(['blocked' => 'business_suspended']);

        Livewire::test(State::class)->assertDontSeeHtml('sylloo-business-header');
    });
});

describe('sign in and PIN', function () {
    it('asks the office at once when the browser hands back, and tells the app of every link', function () {
        TestDevice::bind();
        config(['sylloo-app-base.app.link_scheme' => 'sylloo-pos']);
        $links = [];
        $this->hooks->step('sign_in.link', function (string $url) use (&$links) {
            $links[] = $url;
        });

        $page = Livewire::test(SignIn::class)->call('run', 'browser');
        $this->office->pending();

        $page->call('openedFromUrl', ['url' => 'sylloo-pos://other'])->assertSee('Waiting for your browser');
        $this->office->assertNotCalled('sign-in/finish');

        $page->call('openedFromUrl', 'sylloo-pos://signed-in');
        $this->office->assertCalled('sign-in/finish');

        expect($links)->toBe(['sylloo-pos://other', 'sylloo-pos://signed-in']);
    });

    it('lets the app finish the sign-in its own way', function () {
        $device = TestDevice::bind();
        Event::fake([PersonSignedIn::class]);
        $this->office->asMember()->with('sign-in/finish', ['pin_set' => true, 'pin_hash' => Hash::make('2468')]);
        $finished = [];
        $this->hooks->finish('signed_in', function ($member, bool $offline, $person, array $context) use (&$finished) {
            $finished[] = [$member->memberKey(), $offline, $person?->kind, $context['screen']];

            return '/terminal';
        });

        Livewire::test(SignIn::class)
            ->call('run', 'browser')
            ->call('run', 'poll')
            ->assertRedirect(url('/terminal'));

        expect($finished)->toBe([['member-01JD89FAQDDDDDDDDDDDDDDDDD', false, 'member', 'sign_in']])
            ->and(app(Presence::class)->memberKey('default'))->toBeNull();
        Event::assertNotDispatched(PersonSignedIn::class);
    });

    it('creates the PIN on the PIN screen when the app wants one page', function () {
        config(['sylloo-app-base.screens.pin.create_here' => true]);
        TestDevice::bind();

        Livewire::test(SignIn::class)
            ->call('run', 'browser')
            ->call('run', 'poll')
            ->assertRedirect(route('sylloo.pin'));

        expect(Handover::get('intent'))->toBe(Handover::INTENT_CREATE_PIN);

        Livewire::test(Pin::class)
            ->assertSee('Create your PIN')
            ->set('fields.pin', '1357')
            ->set('fields.pin_confirmation', '1357')
            ->call('run', 'save')
            ->assertRedirect(url('/'));

        $this->office->assertCalled('pin');
        expect(app(Presence::class)->memberKey('default'))->toBe('employee-01JD89FAQDDDDDDDDDDDDDDDDD');
    });

    it('turns a person without a PIN to the create step on the PIN screen', function () {
        config(['sylloo-app-base.screens.pin.create_here' => true]);
        $device = TestDevice::bind();
        Handover::put('member', $device->addMember(pin: null)->memberKey());

        Livewire::test(Pin::class)->assertSee('Create your PIN')->assertDontSee('Forgot PIN');
    });
});

describe('look pieces', function () {
    it('adds classes and a loading text to one button, and draws a screen with the app\'s body view', function () {
        config([
            'sylloo-app-base.screens.classes.buttons.activate' => 'au-btn-main',
            'sylloo-app-base.screens.loading.activate' => 'Activating…',
        ]);
        TestDevice::bind(activated: false);

        Livewire::test(Activate::class)
            ->assertSeeHtml('au-btn-main')
            ->assertSee('Activating…');

        $folder = sys_get_temp_dir().'/sylloo-app-body-'.uniqid();
        mkdir($folder);
        file_put_contents($folder.'/body.blade.php', '<div class="till-body">{{ $view->title }} @foreach ($view->buttons as $button)<button wire:click="run(\'{{ $button->action }}\')">{{ $button->label }}</button>@endforeach</div>');
        View::addNamespace('tillbody', $folder);
        config(['sylloo-app-base.screens.views.activate' => 'tillbody::body']);

        Livewire::test(Activate::class)
            ->assertSeeHtml('till-body')
            ->assertSee('Activate this device')
            ->assertDontSeeHtml('sylloo-screen-title');
    });
});
