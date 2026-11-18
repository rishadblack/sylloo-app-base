<?php

use Rishadblack\SyllooAppBase\Testing\WindowCases;
use Rishadblack\SyllooAppBase\Window\WindowRules;

/*
 * The shared business-window cases (resources/testing/window-cases.json): the base's WindowRules hold to every case,
 * and a window filter (decide) and WindowRules agree on what the window shows. Read as production, as an installed
 * app does (http refused).
 */

beforeEach(function () {
    $this->app['env'] = 'production';
    WindowCases::useOffice();
});

afterEach(function () {
    $this->app['env'] = 'testing';
});

it('decides every shared case in WindowRules::mayShow as the file says', function () {
    foreach (WindowCases::cases() as $case) {
        expect(WindowRules::mayShow($case['url'], WindowCases::hosts(), WindowCases::allowedPaths()))->toBe($case['mayShow'], $case['url']);
    }
});

it('agrees with a window filter on what the window shows (sign-in and sign-out pages are the filter\'s own)', function () {
    foreach (WindowCases::cases() as $case) {
        if (in_array($case['decide'], ['signIn', 'signOut'], true) || $case['url'] === 'about:blank') {
            continue;
        }

        expect($case['mayShow'])->toBe($case['decide'] === 'allow', $case['url']);
    }
});

it('checks every shared ticket address as the file says', function () {
    foreach (WindowCases::tickets() as $case) {
        expect(WindowRules::isTicketUrl($case['url'], WindowCases::hosts()))->toBe($case['ticket'], $case['url']);
    }
});

it('allows a port only when the office address itself has that port', function () {
    $hosts = ['xyz.sylloo.test'];

    expect(WindowRules::mayShow('https://xyz.sylloo.test:8443/member', $hosts, ['/member']))->toBeFalse()
        ->and(WindowRules::isTicketUrl('https://xyz.sylloo.test:8443/app/session?ticket=a', $hosts))->toBeFalse();

    config(['erp.base_url' => 'https://sylloo.test:8443']);

    expect(WindowRules::mayShow('https://xyz.sylloo.test:8443/member', $hosts, ['/member']))->toBeTrue()
        ->and(WindowRules::isTicketUrl('https://xyz.sylloo.test:8443/app/session?ticket=a', $hosts))->toBeTrue()
        ->and(WindowRules::mayShow('https://xyz.sylloo.test:9000/member', $hosts, ['/member']))->toBeFalse()
        ->and(WindowRules::mayShow('https://xyz.sylloo.test/member', $hosts, ['/member']))->toBeTrue();
});

it('wants the exact entrance path for an employee ticket too', function () {
    $hosts = WindowCases::hosts();

    expect(WindowRules::isTicketUrl('https://xyz.sylloo.app/employee/app/session?t=1', $hosts, '/employee/app/session'))->toBeTrue()
        ->and(WindowRules::isTicketUrl('https://xyz.sylloo.app/employee/app/session/?t=1', $hosts, '/employee/app/session'))->toBeFalse()
        ->and(WindowRules::isTicketUrl('https://xyz.sylloo.app/employee/app/session?t=1', $hosts, '/employee/app/session/'))->toBeTrue();
});

it('ships the file in a release: no export-ignore covers it', function () {
    $root = dirname(__DIR__, 2);
    $relative = str_replace('\\', '/', substr(WindowCases::path(), strlen((string) realpath($root))));

    expect(is_file(WindowCases::path()))->toBeTrue();

    foreach (file($root.'/.gitattributes', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('#^(\S+)\s+.*export-ignore#', $line, $match)) {
            $pattern = rtrim($match[1], '/');

            expect(str_starts_with($relative.'/', $pattern.'/'))->toBeFalse("{$match[1]} would drop {$relative} from the release");
        }
    }
});
