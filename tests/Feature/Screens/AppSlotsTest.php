<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Rishadblack\SyllooAppBase\Contracts\LockSource;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Livewire\Activate;
use Rishadblack\SyllooAppBase\Livewire\State;
use Rishadblack\SyllooAppBase\Pin\Presence;
use Rishadblack\SyllooAppBase\Pin\SessionKeyLockSource;
use Rishadblack\SyllooAppBase\Screens\Hooks;
use Rishadblack\SyllooAppBase\Screens\Look;
use Rishadblack\SyllooAppBase\Screens\Question;
use Rishadblack\SyllooAppBase\Screens\ScreenRoutes;
use Rishadblack\SyllooAppBase\Screens\ScreenState;
use Rishadblack\SyllooAppBase\Screens\StateScreen;
use Rishadblack\SyllooAppBase\Support\Icons;
use Rishadblack\SyllooAppBase\Support\Texts;
use Rishadblack\SyllooAppBase\Testing\PretendOffice;
use Rishadblack\SyllooAppBase\Testing\TestDevice;

/*
 * What an existing app (the till) needs to take the base's screens: its layout, icons and classes, the device name and
 * the devices in use on Activate, questions with counts (inline or livewire-alert), counted lines on the state
 * screens, its own word and texts, its own addresses, and its own idle lock.
 */

beforeEach(function () {
    Sleep::fake();
    $this->office = PretendOffice::start();
});

describe('look slots', function () {
    it('renders the screens in the app\'s layout', function () {
        $folder = sys_get_temp_dir().'/sylloo-app-layout-'.uniqid();
        mkdir($folder);
        file_put_contents($folder.'/till.blade.php', '<div class="till-layout">TILL LAYOUT {{ $screen }}</div>{{ $slot }}');
        View::addNamespace('tillapp', $folder);
        config(['sylloo-app-base.screens.layout' => 'tillapp::till']);
        TestDevice::bind(activated: false);

        expect(Look::layout())->toBe('tillapp::till');

        $this->withoutVite()->get(route('sylloo.activate'))
            ->assertOk()
            ->assertSee('TILL LAYOUT activate')
            ->assertSee('Activate this device')
            ->assertDontSee('sylloo-steps', false);
    });

    it('keeps the base layout without a choice', function () {
        expect(Look::layout())->toBe(Look::BASE_LAYOUT);
    });

    it('adds the app\'s classes to each part', function () {
        config(['sylloo-app-base.screens.classes' => ['*' => ['title' => 'display-6'], 'activate' => ['buttons' => 'd-grid gap-2', 'title' => 'text-center']]]);
        TestDevice::bind(activated: false);

        expect(Look::classes('activate', 'title'))->toBe('display-6 text-center')
            ->and(Look::classes('pin', 'title'))->toBe('display-6');

        Livewire::test(Activate::class)
            ->assertSeeHtml('sylloo-screen-title display-6 text-center')
            ->assertSeeHtml('sylloo-buttons d-grid gap-2');
    });

    it('draws the icons in the app\'s set', function () {
        config(['sylloo-app-base.icons.set' => 'tabler', 'sylloo-app-base.icons.map' => ['lock' => 'my-icon my-lock']]);
        TestDevice::bind(activated: false);

        expect(Icons::of('bi bi-arrow-repeat'))->toBe('ti ti-refresh')
            ->and(Icons::of('check2-circle'))->toBe('ti ti-circle-check')
            ->and(Icons::of('unknown-name'))->toBe('ti ti-unknown-name')
            ->and(Icons::of('lock'))->toBe('my-icon my-lock')
            ->and(Icons::of('fa fa-store'))->toBe('fa fa-store')
            ->and(Icons::of(null))->toBeNull();

        Livewire::test(Activate::class)
            ->assertSeeHtml('ti ti-circle-check')
            ->assertDontSeeHtml('bi bi-');
    });

    it('keeps Bootstrap Icons by default', function () {
        expect(Icons::of('arrow-right'))->toBe('bi bi-arrow-right')
            ->and(Icons::of('bi bi-lock'))->toBe('bi bi-lock');
    });
});

describe('activate', function () {
    it('names the device, with the computer\'s name as the default', function () {
        config(['sylloo-app-base.app.device_name' => 'DESK-7']);
        TestDevice::bind(activated: false);

        Livewire::test(Activate::class)
            ->assertSee('Name this device')
            ->assertSet('fields.device_name', 'DESK-7')
            ->set('fields.code', '1234-5678-1234-5678')
            ->set('fields.device_name', 'Counter 1')
            ->call('run', 'activate')
            ->assertSee('Device activated');

        $this->office->assertCalled('activate', fn (Request $request) => $request['name'] === 'Counter 1');
    });

    it('refuses a name that is too long', function () {
        TestDevice::bind(activated: false);

        Livewire::test(Activate::class)
            ->set('fields.code', '1234-5678-1234-5678')
            ->set('fields.device_name', str_repeat('x', 61))
            ->call('run', 'activate')
            ->assertHasErrors('fields.device_name')
            ->assertSee('Use at most 60 characters.');

        $this->office->assertNotCalled('activate');
    });

    it('does not ask for a name when the app turns it off', function () {
        config(['sylloo-app-base.screens.activate.device_name' => false, 'sylloo-app-base.app.device_name' => 'Till 3']);
        TestDevice::bind(activated: false);

        Livewire::test(Activate::class)
            ->assertDontSee('Name this device')
            ->set('fields.code', '1234-5678-1234-5678')
            ->call('run', 'activate');

        $this->office->assertCalled('activate', fn (Request $request) => $request['name'] === 'Till 3');
    });

    it('lists the devices in use when every device of the plan is in use', function () {
        TestDevice::bind(activated: false);
        $this->office->answer('activate', [
            'message' => 'Every app device of the plan is in use.',
            'code' => 'device_limit_reached_app',
            'allowed' => 2,
            'devices' => [
                ['name' => 'Counter 1', 'last_seen_at' => '2026-11-16T09:30:00Z', 'last_user' => 'Karim Hossain'],
                ['name' => 'Back office', 'last_seen_at' => null, 'last_user' => null],
            ],
        ], 403);

        Livewire::test(Activate::class)
            ->set('fields.code', '1234-5678-1234-5678')
            ->call('run', 'activate')
            ->assertSee('Every device is in use')
            ->assertSee('Devices in use (2 of 2)')
            ->assertSee('Counter 1')
            ->assertSee('Last used 16 Nov 2026, 09:30 by Karim Hossain')
            ->assertSee('Back office')
            ->call('run', 'try_again')
            ->assertDontSee('Last used');
    });

    it('asks the app\'s reset question with its count, inline', function () {
        TestDevice::bind(activated: false);
        $reset = false;
        app(Hooks::class)->ask('activate', 'reset', function (array $context) use (&$reset) {
            return new Question(
                'Reset terminal for the new code?',
                'The new code is for another business.',
                'Reset',
                'Cancel',
                true,
                function () use (&$reset) {
                    $reset = true;
                },
                detail: '14 sales are safe on this terminal',
                lines: [['text' => 'Sales waiting to be sent', 'count' => 0, 'variant' => 'success']],
            );
        });

        $page = Livewire::test(Activate::class)
            ->set('fields.code', '1234-5678-1234-5678')
            ->call('run', 'activate')
            ->assertSee('Reset terminal for the new code?')
            ->assertSee('14 sales are safe on this terminal')
            ->assertSee('Sales waiting to be sent');

        $this->office->assertNotCalled('activate');

        $page->call('run', 'answer_yes')->assertSee('Device activated');

        expect($reset)->toBeTrue();
        $this->office->assertCalled('activate');
    });

    it('asks the question as a livewire-alert when the app says so', function () {
        config(['sylloo-app-base.screens.questions' => 'alert']);
        TestDevice::bind(activated: false);
        app(Hooks::class)->ask('activate', 'reset', fn () => new Question('Reset terminal for the new code?', 'Another business.', 'Reset', 'Cancel', detail: '14 sales are safe on this terminal'));

        $page = Livewire::test(Activate::class)
            ->set('fields.code', '1234-5678-1234-5678')
            ->call('run', 'activate')
            ->assertDontSee('sylloo-question');

        $js = json_encode($page->effects['xjs'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        expect($js)->toContain('answerQuestion')->toContain('Reset terminal for the new code?')->toContain('14 sales are safe on this terminal');

        $page->call('answerQuestion', ['yes' => true])->assertSee('Device activated');
        $this->office->assertCalled('activate');
    });

    it('sends nothing when the alert is answered no', function () {
        config(['sylloo-app-base.screens.questions' => 'alert']);
        TestDevice::bind(activated: false);
        app(Hooks::class)->ask('activate', 'reset', fn () => new Question('Reset?', 'Sure?', 'Reset', 'Cancel'));

        Livewire::test(Activate::class)
            ->set('fields.code', '1234-5678-1234-5678')
            ->call('run', 'activate')
            ->call('answerQuestion', ['yes' => false])
            ->assertSee('Activate this device');

        $this->office->assertNotCalled('activate');
    });
});

describe('state screens', function () {
    it('shows the app\'s counted lines at the state\'s place', function () {
        TestDevice::bind(['blocked' => 'plan_ended']);
        app(Hooks::class)->lines('state.plan_ended', fn (array $context) => [
            ['text' => 'Sales are safe on this terminal', 'count' => 14, 'variant' => 'success'],
            'Offline sales keep working',
            ['count' => 3],
        ]);

        Livewire::test(State::class)
            ->assertSee('Sales are safe on this terminal')
            ->assertSeeHtml('sylloo-line-count')
            ->assertSee('14')
            ->assertSee('Offline sales keep working');

        $screen = app(StateScreen::class);
        $lines = $screen->placeLines($screen->start());

        expect(array_keys($lines))->toBe(['state.plan_ended'])
            ->and($lines['state.plan_ended'])->toHaveCount(2)
            ->and($lines['state.plan_ended'][0])->toBe(['text' => 'Sales are safe on this terminal', 'count' => 14, 'hint' => null, 'variant' => 'success', 'icon' => null]);
    });

    it('shows the releasing counts while the device is released', function () {
        TestDevice::bind([DeviceState::RELEASING_SETTING => true]);
        app(Hooks::class)->lines('state.releasing', fn () => [
            ['text' => 'Refused by the office', 'count' => 1, 'variant' => 'danger'],
            ['text' => 'Sending', 'count' => 12],
            ['text' => 'A shift is still open', 'variant' => 'warning'],
        ]);

        Livewire::test(State::class)
            ->assertSee('Refused by the office')
            ->assertSee('Sending')
            ->assertSee('A shift is still open');
    });

    it('shows nothing extra when the app adds no lines', function () {
        TestDevice::bind(['blocked' => 'plan_ended']);

        Livewire::test(State::class)->assertDontSee('sylloo-hook');
        expect(app(StateScreen::class)->placeLines(new ScreenState('plan_ended')))->toBe([]);
    });
});

describe('texts', function () {
    it('names the device with the app\'s word, in every language', function () {
        config(['sylloo-app-base.app.device_word' => 'terminal']);
        TestDevice::bind(activated: false);

        Livewire::test(Activate::class)->assertSee('Activate this terminal');

        expect(Texts::get('activate_done_title'))->toBe('Terminal activated')
            ->and(Texts::get('refused_session_expired'))->toBe('Your sign-in on this terminal has ended. Sign in with your browser again.')
            ->and(Texts::get('activate_title', [], 'fi'))->toBe('Aktivoi tämä kassa')
            ->and(Texts::get('refused_session_expired', [], 'fi'))->toStartWith('Kirjautumisesi tähän kassaan')
            ->and(Texts::get('activate_title', [], 'bn'))->toBe('এই টার্মিনাল অ্যাক্টিভেট করুন');

        config(['sylloo-app-base.app.device_word' => 'phone']);

        expect(Texts::get('releasing_title', [], 'fi'))->toBe('Tätä puhelinta vapautetaan')
            ->and(Texts::get('limit_title'))->toBe('Every phone is in use');
    });

    it('says "device" when the app names no word, and every form exists in every language', function () {
        expect(Texts::get('activate_title'))->toBe('Activate this device')
            ->and(Texts::get('locked_title'))->toBe('Device locked');

        foreach (['en', 'bn', 'fi'] as $locale) {
            foreach (['device', 'terminal', 'phone'] as $word) {
                config(['sylloo-app-base.app.device_word' => $word]);

                foreach (array_keys(require dirname(__DIR__, 3)."/resources/lang/{$locale}/device.php") as $key) {
                    if ($key !== 'words') {
                        expect(Texts::get($key, [], $locale))->not->toMatch('/:devices?(_[a-z]+)?\b/i', "{$locale} {$word} {$key}");
                    }
                }
            }
        }
    });

    it('lets an app change one text with its own lang file', function () {
        $folder = sys_get_temp_dir().'/sylloo-app-lang-'.uniqid();
        mkdir($folder.'/vendor/sylloo-app-base/en', 0755, true);
        file_put_contents($folder.'/vendor/sylloo-app-base/en/device.php', "<?php return ['activate_title' => 'Start this till', 'words' => ['till' => ['device' => 'till', 'devices' => 'tills']]];");
        app('translation.loader')->addPath($folder);
        config(['sylloo-app-base.app.device_word' => 'till']);

        expect(Texts::get('activate_title'))->toBe('Start this till')
            ->and(Texts::get('activate_done_title'))->toBe('Till activated')
            ->and(Texts::get('limit_title'))->toBe('Every till is in use');
    });
});

describe('routes', function () {
    it('keeps the app\'s own paths and names', function () {
        config(['sylloo-app-base.routes.prefix' => '', 'sylloo-app-base.routes.screens' => [
            'activate' => ['path' => '/till-activate', 'name' => 'till.activate'],
            'sign_in' => ['path' => 'till-login', 'name' => 'till.login'],
            'lock' => false,
        ]]);

        ScreenRoutes::register();
        Route::getRoutes()->refreshNameLookups();

        expect(route('till.activate', absolute: false))->toBe('/till-activate')
            ->and(route('till.login', absolute: false))->toBe('/till-login')
            ->and(Route::has('sylloo.lock'))->toBeTrue()
            ->and(ScreenRoutes::name('lock'))->toBe('sylloo.lock')
            ->and(ScreenRoutes::screens()['lock']['drawn'])->toBeFalse()
            ->and(ScreenRoutes::url('activate'))->toBe(url('/till-activate'))
            ->and(ScreenRoutes::url('sign_in'))->toBe(url('/till-login'));

        TestDevice::bind(activated: false);
        $this->withoutVite()->get('/till-activate')->assertOk()->assertSee('Activate this device');
    });

    it('sends to the app\'s own route for a screen it draws itself', function () {
        Route::get('/login', fn () => 'the app\'s sign-in')->name('login');
        Route::getRoutes()->refreshNameLookups();
        config(['sylloo-app-base.routes.screens.sign_in' => ['name' => 'login', 'enabled' => false]]);

        expect(ScreenRoutes::url('sign_in'))->toBe(url('/login'))
            ->and(ScreenRoutes::screens()['sign_in']['drawn'])->toBeFalse();
    });
});

describe('the idle lock source', function () {
    it('uses the app\'s own lock: a session key and its own minutes', function () {
        $this->app->bind(LockSource::class, fn () => new SessionKeyLockSource('till_locked_at', fn () => 10));
        $device = TestDevice::bind();
        $presence = app(Presence::class);

        expect($presence->isLocked())->toBeFalse()
            ->and(Presence::idleMinutes($device->state))->toBe(10);

        $presence->lock();

        expect(session()->has('till_locked_at'))->toBeTrue()
            ->and($presence->isLocked())->toBeTrue();

        $presence->unlock();

        expect(session()->has('till_locked_at'))->toBeFalse()
            ->and($presence->isLocked())->toBeFalse();
    });

    it('locks after the app\'s idle minutes', function () {
        $this->app->bind(LockSource::class, fn () => new SessionKeyLockSource('till_locked_at', fn () => 10));
        $device = TestDevice::bind();
        $presence = app(Presence::class);
        $presence->enter('default', 'member-1');

        $this->travel(9)->minutes();
        expect($presence->lockWhenIdle($device->state))->toBeFalse();

        $this->travel(2)->minutes();
        expect($presence->lockWhenIdle($device->state))->toBeTrue()
            ->and(session()->has('till_locked_at'))->toBeTrue();
    });

    it('keeps the base\'s own lock by default', function () {
        $device = TestDevice::bind();
        $presence = app(Presence::class);

        $presence->lock();
        expect($presence->isLocked())->toBeFalse();

        $presence->enter('default', 'member-1');
        $presence->lock();

        expect($presence->isLocked())->toBeTrue()
            ->and(Presence::idleMinutes($device->state))->toBe(5);
    });
});

it('never ships the Electron node_modules or tests', function () {
    $root = dirname(__DIR__, 3);
    $ignored = file_get_contents($root.'/.gitattributes');
    $package = json_decode(file_get_contents($root.'/electron/package.json'), true);

    foreach (['/electron/node_modules', '/electron/tests', '/tests'] as $path) {
        expect($ignored)->toMatch('#^'.preg_quote($path, '#').'\s+export-ignore$#m');
    }

    expect($package['files'])->toBe(['src/', 'sync.mjs']);
});
