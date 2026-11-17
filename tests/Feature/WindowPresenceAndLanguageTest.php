<?php

use Illuminate\Support\Facades\Event;
use Rishadblack\SyllooAppBase\Device\StateRegistry;
use Rishadblack\SyllooAppBase\Erp\RefusalCode;
use Rishadblack\SyllooAppBase\Events\DeviceLocked;
use Rishadblack\SyllooAppBase\Pin\Presence;
use Rishadblack\SyllooAppBase\Testing\TestDevice;
use Rishadblack\SyllooAppBase\Window\WindowRules;

describe('business window', function () {
    $hosts = ['xyz.sylloo.app', 'xyz.sylloo.dev'];

    it('opens only a ticket address on the business host at the entrance', function () use ($hosts) {
        expect(WindowRules::isTicketUrl('https://xyz.sylloo.app/app/session?ticket=abc', $hosts))->toBeTrue()
            ->and(WindowRules::isTicketUrl('https://xyz.sylloo.app/employee/app/session?t=1', $hosts, '/employee/app/session'))->toBeTrue()
            ->and(WindowRules::isTicketUrl('https://evil.example/app/session', $hosts))->toBeFalse()
            ->and(WindowRules::isTicketUrl('https://user:pw@xyz.sylloo.app/app/session', $hosts))->toBeFalse()
            ->and(WindowRules::isTicketUrl('https://xyz.sylloo.app/member', $hosts))->toBeFalse();
    });

    it('shows only the allowed paths; the rest opens in the browser', function () use ($hosts) {
        $staff = WindowRules::allowedPaths([]);
        $employee = WindowRules::allowedPaths([], true);
        $fromOffice = WindowRules::allowedPaths(['rules' => ['allowed_paths' => ['/member', 'nope']]]);

        expect(WindowRules::mayShow('https://xyz.sylloo.app/member/sales', $hosts, $staff))->toBeTrue()
            ->and(WindowRules::mayShow('https://xyz.sylloo.app/memberx', $hosts, $staff))->toBeFalse()
            ->and(WindowRules::mayShow('https://xyz.sylloo.app/member/../admin', $hosts, $staff))->toBeFalse()
            ->and(WindowRules::mayShow('https://xyz.sylloo.app/employee/leave', $hosts, $employee))->toBeTrue()
            ->and(WindowRules::mayShow('https://xyz.sylloo.app/member', $hosts, $employee))->toBeFalse()
            ->and(WindowRules::mayShow('https://google.com/member', $hosts, $staff))->toBeFalse()
            ->and($fromOffice)->toBe(['/member']);
    });

    it('limits quiet new tickets', function () {
        config(['sylloo-app-base.window.quiet_sign_ins_per_minute' => 2]);

        expect(WindowRules::mayQuietlySignIn('a'))->toBeTrue()
            ->and(WindowRules::mayQuietlySignIn('a'))->toBeTrue()
            ->and(WindowRules::mayQuietlySignIn('a'))->toBeFalse()
            ->and(WindowRules::mayQuietlySignIn('b'))->toBeTrue();

        $this->travel(61)->seconds();

        expect(WindowRules::mayQuietlySignIn('a'))->toBeTrue();
    });
});

describe('presence and the idle lock', function () {
    it('locks after the idle time and opens again', function () {
        Event::fake([DeviceLocked::class]);
        $device = TestDevice::bind();
        $presence = app(Presence::class);

        $presence->enter('default', 'member-1');

        expect($presence->lockWhenIdle($device->state))->toBeFalse();

        $this->travel(6)->minutes();

        expect($presence->lockWhenIdle($device->state))->toBeTrue()
            ->and($presence->memberKey('default'))->toBe('member-1');
        Event::assertDispatched(DeviceLocked::class, fn (DeviceLocked $event) => $event->memberKey === 'member-1');

        $presence->unlock();

        expect($presence->isLocked())->toBeFalse();
    });

    it('never locks by itself when the person chose never', function () {
        $device = TestDevice::bind([Presence::IDLE_SETTING => 0]);
        $presence = app(Presence::class);
        $presence->enter('default', 'member-1');

        $this->travel(2)->hours();

        expect($presence->lockWhenIdle($device->state))->toBeFalse();
    });
});

describe('texts', function () {
    it('has every text in English, Bangla and Finnish', function () {
        $base = dirname(__DIR__, 2).'/resources/lang';
        $english = array_keys(require "{$base}/en/device.php");

        foreach (['bn', 'fi'] as $language) {
            $keys = array_keys(require "{$base}/{$language}/device.php");

            expect(array_values(array_diff($english, $keys)))->toBe([], "{$language} misses texts")
                ->and(array_values(array_diff($keys, $english)))->toBe([], "{$language} has texts English has not");
        }
    });

    it('has a text for every refusal code and every base state', function () {
        $english = require dirname(__DIR__, 2).'/resources/lang/en/device.php';

        foreach (RefusalCode::cases() as $code) {
            if ($code->value !== 'sign_in_pending') {
                expect($english)->toHaveKey('refused_'.$code->value);
            }
        }

        foreach (app(StateRegistry::class)->all() as $state) {
            foreach (array_filter([$state->title, $state->text, $state->todo]) as $key) {
                expect(__($key))->not->toBe($key);
            }
        }
    });

    it('uses every text key the code names', function () {
        $root = dirname(__DIR__, 2);
        $english = require "{$root}/resources/lang/en/device.php";
        $files = [
            ...glob("{$root}/src/*/*.php"),
            ...glob("{$root}/src/*/*/*.php"),
            ...glob("{$root}/resources/views/*/*.blade.php"),
            ...glob("{$root}/resources/views/*/*/*.blade.php"),
        ];
        $missing = [];

        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            preg_match_all("/(?:->t\\('|sylloo-app-base::device\\.)([a-z_]+)/", $text, $matches);

            foreach ($matches[1] as $key) {
                if (! array_key_exists($key, $english) && $key !== 'refused_') {
                    $missing[] = basename($file).': '.$key;
                }
            }
        }

        expect($missing)->toBe([]);
    });
});
