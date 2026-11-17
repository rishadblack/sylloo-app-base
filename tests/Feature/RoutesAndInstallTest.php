<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Rishadblack\SyllooAppBase\Console\Installer;
use Rishadblack\SyllooAppBase\Screens\FirstScreen;
use Rishadblack\SyllooAppBase\Testing\TestDevice;

beforeEach(function () {
    $this->withoutVite();

    Route::middleware(['web', 'sylloo.device'])->get('/work', fn () => 'working');
});

describe('routes and the device middleware', function () {
    it('draws a whole screen with the layout and the three steps', function () {
        TestDevice::bind(activated: false);

        $this->get(route('sylloo.activate'))
            ->assertOk()
            ->assertSee('Activate this device')
            ->assertSee('sylloo-steps', false)
            ->assertSee('Sign in')
            ->assertSee('wire-modals', false);
    });

    it('shows PRACTICE on a practice activation', function () {
        TestDevice::bind(['license_mode' => 'practice']);

        $this->get(route('sylloo.about'))
            ->assertOk()
            ->assertSee('PRACTICE')
            ->assertSee('Practice: nothing you do here is real business data.');
    });

    it('sends a person to the screen the device needs', function () {
        TestDevice::bind(activated: false);
        $this->get('/work')->assertRedirect(route('sylloo.activate'));

        TestDevice::bind(['blocked' => 'business_suspended']);
        $this->get('/work')->assertRedirect(route('sylloo.state'));

        $device = TestDevice::bind();
        $device->addMember();
        $this->get('/work')->assertRedirect(route('sylloo.sign-in'));

        $presence = fn (int $lastSeen) => ['sylloo-app-base.presence' => ['entry' => 'default', 'member' => 'member-1', 'offline' => false, 'locked' => false, 'last_seen' => $lastSeen]];

        $this->withSession($presence(now()->timestamp))->get('/work')->assertOk()->assertSee('working');
        $this->withSession($presence(now()->subMinutes(10)->timestamp))->get('/work')->assertRedirect(route('sylloo.lock'));
    });

    it('answers the same first screen for any face, without routes', function () {
        $first = fn () => app(FirstScreen::class)->name();

        TestDevice::bind(activated: false);
        expect($first())->toBe(FirstScreen::ACTIVATE);

        TestDevice::bind(['blocked' => 'business_suspended']);
        expect($first())->toBe(FirstScreen::STATE);

        TestDevice::bind()->addMember();
        expect($first())->toBe(FirstScreen::SIGN_IN);

        session(['sylloo-app-base.presence' => ['entry' => 'default', 'member' => 'member-1', 'offline' => false, 'locked' => false, 'last_seen' => now()->timestamp]]);
        expect($first())->toBeNull();

        session(['sylloo-app-base.presence' => ['entry' => 'default', 'member' => 'member-1', 'offline' => false, 'locked' => false, 'last_seen' => now()->subMinutes(10)->timestamp]]);
        expect($first())->toBe(FirstScreen::LOCK);
    });
});

describe('install', function () {
    it('adds the style and the script once', function () {
        $folder = sys_get_temp_dir().'/sylloo-app-base-install-'.uniqid();
        mkdir($folder.'/resources/js', 0755, true);
        file_put_contents($folder.'/resources/js/app.js', "import './bootstrap';\n");

        $installer = new Installer($folder);

        expect($installer->addImports())->toBe(['resources/scss/app.scss' => true, 'resources/js/app.js' => true])
            ->and($installer->addImports())->toBe(['resources/scss/app.scss' => false, 'resources/js/app.js' => false]);

        $script = (string) file_get_contents($folder.'/resources/js/app.js');

        expect($script)->toStartWith("import './bootstrap';")
            ->and(substr_count($script, 'sylloo-app-base.js'))->toBe(1)
            ->and((string) file_get_contents($folder.'/resources/scss/app.scss'))->toContain("@import '../../vendor/rishadblack/sylloo-app-base/resources/scss/sylloo-app-base';");

        File::deleteDirectory($folder);
    });

    it('runs as an artisan command', function () {
        $this->artisan('sylloo-app-base:install', ['--no-assets' => true])->assertSuccessful();
    });
});
