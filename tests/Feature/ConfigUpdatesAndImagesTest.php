<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Rishadblack\SyllooAppBase\Config\RemoteConfig;
use Rishadblack\SyllooAppBase\Contracts\AppUpdater;
use Rishadblack\SyllooAppBase\Contracts\Notifier;
use Rishadblack\SyllooAppBase\Device\DeviceTestRunner;
use Rishadblack\SyllooAppBase\Erp\ApiService;
use Rishadblack\SyllooAppBase\Erp\DeviceApi;
use Rishadblack\SyllooAppBase\Images\ImageCache;
use Rishadblack\SyllooAppBase\Update\AppUpdateService;

/**
 * Notices and updater calls, recorded.
 */
function fakePlatform(bool $updatesOn = true): object
{
    $platform = new class implements AppUpdater, Notifier
    {
        public bool $enabled = true;

        /** @var list<string> */
        public array $calls = [];

        /** @var list<array{0: string, 1: string, 2: ?string}> */
        public array $notices = [];

        public function isEnabled(): bool
        {
            return $this->enabled;
        }

        public function checkForUpdates(): void
        {
            $this->calls[] = 'check';
        }

        public function quitAndInstall(): void
        {
            $this->calls[] = 'install';
        }

        public function notify(string $title, string $message, ?string $reference = null): void
        {
            $this->notices[] = [$title, $message, $reference];
        }
    };
    $platform->enabled = $updatesOn;
    app()->instance(AppUpdater::class, $platform);
    app()->instance(Notifier::class, $platform);

    return $platform;
}

describe('the signed remote config', function () {
    beforeEach(function () {
        $keyPair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
        config([
            'erp.remote_config.enabled' => true,
            'erp.remote_config.url' => 'https://releases.example/app-config.json',
            'erp.remote_config.public_key' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
            'erp.remote_config.allowed_domains' => ['sylloo.dev'],
            'nativephp.version' => '1.0.0',
        ]);
        Storage::fake(RemoteConfig::DISK);
        Http::preventStrayRequests();
        fakePlatform();
    });

    it('takes a signed file, applies its ERP address and keeps it', function () {
        $payload = ['schema' => 1, 'serial' => 2, 'erp' => ['base_url' => 'https://erp.sylloo.dev']];
        Http::fake(['releases.example/*' => Http::response(['payload' => $payload, 'signature' => RemoteConfig::sign($payload, $this->secretKey)])]);

        expect(app(RemoteConfig::class)->refresh())->toBeTrue()
            ->and(config('erp.base_url'))->toBe('https://erp.sylloo.dev');

        Storage::disk(RemoteConfig::DISK)->assertExists(RemoteConfig::PATH);
    });

    it('refuses a file with a wrong signature or an address off the allowed domains', function () {
        $foreign = ['schema' => 1, 'serial' => 2, 'erp' => ['base_url' => 'https://evil.example']];
        $config = new RemoteConfig;

        expect($config->verify(['payload' => $foreign, 'signature' => RemoteConfig::sign($foreign, $this->secretKey)]))->toBeNull()
            ->and($config->lastRefusal)->toContain('erp.base_url')
            ->and($config->verify(['payload' => ['schema' => 1, 'serial' => 2], 'signature' => base64_encode(str_repeat('x', 64))]))->toBeNull();
    });

    it('asks the updater when a newer version is out', function () {
        $platform = fakePlatform();
        $payload = ['schema' => 1, 'serial' => 3, 'release' => ['latest_version' => '1.2.0']];
        Http::fake(['*' => Http::response(['payload' => $payload, 'signature' => RemoteConfig::sign($payload, $this->secretKey)])]);

        app(RemoteConfig::class)->refresh();

        expect($platform->calls)->toBe(['check']);
    });
});

describe('update notices', function () {
    beforeEach(function () {
        config(['app.name' => 'Sylloo App', 'nativephp.version' => '1.0.0']);
    });

    it('announces a version once per launch and offers to install it', function () {
        $platform = fakePlatform();
        $updates = app(AppUpdateService::class);

        $updates->checkForUpdates();
        $updates->updateAvailable('1.1.0');
        $updates->updateAvailable('1.1.0');
        $updates->updateDownloaded('1.1.0');
        $updates->installNow();

        expect($platform->notices)->toBe([
            ['Sylloo App 1.1.0 is available', 'It is downloading in the background. You can keep working.', null],
            ['Sylloo App 1.1.0 is ready to install', 'Click to restart now, or it installs when you close the app.', AppUpdateService::READY_NOTIFICATION],
        ])->and($platform->calls)->toBe(['check', 'install']);
    });

    it('always answers a check by hand', function () {
        $platform = fakePlatform();
        $updates = app(AppUpdateService::class);

        $updates->checkForUpdates(manual: true);
        $updates->updateNotAvailable();
        $updates->checkForUpdates(manual: true);
        $updates->updateFailed('offline');

        expect(array_column($platform->notices, 0))->toBe(['Sylloo App is up to date', "Couldn't check for updates"]);
    });

    it('says when updates are off', function () {
        $platform = fakePlatform(updatesOn: false);

        app(AppUpdateService::class)->checkForUpdates(manual: true);

        expect($platform->calls)->toBe([])
            ->and($platform->notices[0][0])->toBe('Updates are turned off');
    });

    it('speaks the person\'s language', function (string $locale) {
        app()->setLocale($locale);
        $platform = fakePlatform();
        $updates = app(AppUpdateService::class);

        $updates->checkForUpdates(manual: true);
        $updates->updateAvailable('1.1.0');

        expect($platform->notices[0][0])->toBe(__('sylloo-app-base::device.update_available_title', ['app' => 'Sylloo App', 'version' => '1.1.0']))
            ->toContain('Sylloo App 1.1.0')
            ->and($platform->notices[0][1])->toBe(__('sylloo-app-base::device.update_available_text'))
            ->not->toBe('sylloo-app-base::device.update_available_text');
    })->with(['bn', 'fi']);

    it('speaks the office texts locale when the app sets one', function () {
        app()->setLocale('fi');
        config(['sylloo-app-base.texts.locale' => 'en']);
        $platform = fakePlatform();
        $updates = app(AppUpdateService::class);

        $updates->checkForUpdates(manual: true);
        $updates->updateAvailable('1.1.0');

        expect($platform->notices[0][0])->toBe('Sylloo App 1.1.0 is available');
    });

    it('takes the updater\'s event objects as well as strings', function () {
        $platform = fakePlatform();
        $updates = app(AppUpdateService::class);

        $updates->checkForUpdates();
        $updates->updateAvailable((object) ['version' => '1.2.0']);
        $updates->updateDownloaded((object) ['info' => ['version' => '1.2.0']]);
        $updates->checkForUpdates(manual: true);
        $updates->updateNotAvailable((object) ['info' => []]);
        $updates->checkForUpdates(manual: true);
        $updates->updateFailed((object) ['message' => 'offline', 'name' => 'Error']);

        expect(array_column($platform->notices, 0))->toBe([
            'Sylloo App 1.2.0 is available',
            'Sylloo App 1.2.0 is ready to install',
            'Sylloo App is up to date',
            "Couldn't check for updates",
        ])->and(AppUpdateService::versionOf((object) ['info' => (object) ['version' => '2.0.0']]))->toBe('2.0.0')
            ->and(AppUpdateService::versionOf('3.0.0'))->toBe('3.0.0');
    });
});

it('answers the connection test with the shared checks and the app\'s own', function () {
    config(['erp.base_url' => 'https://sylloo-erp.example']);
    $platform = fakePlatform();
    Http::fake(['*/device/api/v1/test-result' => Http::response(['status' => 'passed'])]);

    $runner = new class extends DeviceTestRunner
    {
        protected function deviceApi(): DeviceApi
        {
            return new DeviceApi(new ApiService(activatedDevice()));
        }

        protected function askState(): ?array
        {
            return stateAnswer();
        }

        protected function moreChecks(): array
        {
            return [$this->check('window', false, 'The window did not open.')];
        }
    };

    $result = $runner->run('01JTEST');

    expect($result['sent'])->toBeTrue()
        ->and($result['checks'])->toBe([
            ['key' => 'connection', 'ok' => true],
            ['key' => 'socket', 'ok' => true],
            ['key' => 'notification', 'ok' => true],
            ['key' => 'window', 'ok' => false, 'detail' => 'The window did not open.'],
        ])
        ->and($platform->notices[0][0])->toBe('Sylloo test');
});

describe('the image cache', function () {
    beforeEach(function () {
        config(['erp.base_url' => 'https://sylloo-erp.example', 'erp.image_hosts' => ['sylloo.app']]);
        Storage::fake(ImageCache::DISK);
        Http::preventStrayRequests();
    });

    it('downloads only images of the ERP hosts', function (string $url, bool $allowed) {
        expect(ImageCache::isAllowedSource($url))->toBe($allowed);
    })->with([
        ['https://cdn.sylloo.app/logo.png', true],
        ['https://sylloo-erp.example/logo.png', true],
        ['http://cdn.sylloo.app/logo.png', false],
        ['https://192.168.0.5/logo.png', false],
        ['https://sylloo.app.evil.example/logo.png', false],
    ]);

    it('keeps a copy of an image once and refuses what is not an image', function () {
        Http::fake([
            'cdn.sylloo.app/logo.png' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
            'cdn.sylloo.app/page' => Http::response('<html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $cache = new ImageCache;

        $path = $cache->cache('https://cdn.sylloo.app/logo.png');

        expect($path)->toBe(ImageCache::DIRECTORY.'/'.sha1('https://cdn.sylloo.app/logo.png').'.png')
            ->and(ImageCache::isCachedPath($path))->toBeTrue()
            ->and($cache->cache('https://cdn.sylloo.app/logo.png'))->toBe($path)
            ->and($cache->cache('https://cdn.sylloo.app/page'))->toBeNull();

        Http::assertSentCount(2);
    });
});
