<?php

use Illuminate\Support\Facades\Blade;
use Rishadblack\SyllooAppBase\Events\WindowLoadFailed;
use Rishadblack\SyllooAppBase\Testing\FakeWindowConnection;
use Rishadblack\SyllooAppBase\Window\ConnectionProblems;

/*
 * Connection problems of the business window (moved from Sylloo App in 1.1.0; the Tauri app's checklist): a thin bar
 * while the internet is off, the app's own page that says whose problem it is when a page can not load, tried again by
 * itself once the internet is back, and a one-use ticket that is never loaded twice.
 */

beforeEach(function () {
    $this->window = FakeWindowConnection::bind();
    $this->problems = app(ConnectionProblems::class);
});

it('shows the thin bar while the internet is off and keeps the page', function () {
    $this->window->opened('a');

    expect($this->problems->check('a', ConnectionProblems::OK)->offline)->toBeFalse();

    $this->window->online = false;
    $check = $this->problems->check('a', ConnectionProblems::OK);

    expect($check->offline)->toBeTrue()
        ->and($check->loadFailed)->toBeFalse()
        ->and($this->window->windows['a']['visible'])->toBeTrue();
});

it('hides a page that could not load and says whose problem it is, also when its event was missed', function () {
    $this->window->failLoad('a', 'https://demo.sylloo.app/member/orders');
    $this->window->online = false;

    $check = $this->problems->check('a', ConnectionProblems::OK);

    expect($check->loadFailed)->toBeTrue()
        ->and($check->failedOffline)->toBeTrue()
        ->and($check->offline)->toBeFalse()
        ->and($this->window->windows['a']['visible'])->toBeFalse()
        ->and(ConnectionProblems::message(true))->toBe('Your internet is off, so the page could not open.')
        ->and(ConnectionProblems::message(false))->toBe('Sylloo is not answering right now (it may be updating). The status is at status.sylloo.app.');
});

it('tries a failed page again only once the internet is back, and loads the same page (no new ticket)', function () {
    $this->window->failLoad('a', 'https://demo.sylloo.app/member/orders');
    $this->window->online = false;

    expect($this->problems->check('a', ConnectionProblems::LOAD_FAILED)->tryAgain)->toBeFalse();

    $this->window->online = true;

    expect($this->problems->check('a', ConnectionProblems::LOAD_FAILED)->tryAgain)->toBeTrue()
        ->and($this->problems->check('a', ConnectionProblems::LOAD_FAILED, retry: false)->tryAgain)->toBeFalse()
        ->and($this->problems->tryAgain('a', ConnectionProblems::LOAD_FAILED))->toBeTrue()
        ->and($this->window->windows['a'])->toBe(['failed' => null, 'visible' => true, 'open' => true])
        ->and($this->window->calls)->not->toContain('close a');
});

it('never loads a one-use ticket twice: the window closes and a new ticket is needed', function () {
    $this->window->failLoad('a');

    expect($this->problems->tryAgain('a', ConnectionProblems::LOAD_FAILED))->toBeFalse()
        ->and($this->window->calls)->toBe(['retry a', 'close a'])
        ->and($this->window->windows)->not->toHaveKey('a');
});

it('tries the office again by itself when it gave no answer', function () {
    expect($this->problems->check('a', ConnectionProblems::UNREACHABLE)->tryAgain)->toBeTrue()
        ->and($this->problems->tryAgain('a', ConnectionProblems::UNREACHABLE))->toBeFalse()
        ->and($this->window->calls)->not->toContain('retry a');

    $this->window->online = false;

    expect($this->problems->check('a', ConnectionProblems::UNREACHABLE)->tryAgain)->toBeFalse();
});

it('decides nothing when the window does not answer', function () {
    $this->window->silent = true;
    $check = $this->problems->check('a', ConnectionProblems::OK);

    expect($check->offline || $check->loadFailed || $check->tryAgain)->toBeFalse();
});

it('draws the bar and the app\'s own page in the chosen language', function () {
    $bar = Blade::render('<x-sylloo-app-base::window.offline-bar :offline="$offline" />', ['offline' => true]);
    $none = Blade::render('<x-sylloo-app-base::window.offline-bar :offline="false" />');
    $page = Blade::render('<x-sylloo-app-base::window.problem :status="$status" :message="$message" />', [
        'status' => ConnectionProblems::LOAD_FAILED,
        'message' => ConnectionProblems::message(true),
    ]);
    $refused = Blade::render('<x-sylloo-app-base::window.problem status="refused" message="No." action="open" />');

    expect($bar)->toContain('No internet. Your work is still here.')
        ->and(trim($none))->toBe('')
        ->and($page)->toContain('Your internet is off')->toContain('tries again by itself.')->toContain('wire:click="tryAgain"')->toContain('Try again')
        ->and($refused)->not->toContain('tries again by itself.')->toContain('wire:click="open"');

    app()->setLocale('fi');

    expect(Blade::render('<x-sylloo-app-base::window.offline-bar :offline="true" />'))->toContain('Ei internetiä.');
});

it('carries the business and whether the internet was off, never an address', function () {
    $event = new WindowLoadFailed('a', offline: true);

    expect(get_object_vars($event))->toBe(['businessId' => 'a', 'offline' => true]);
});
