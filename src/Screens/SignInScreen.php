<?php

namespace Rishadblack\SyllooAppBase\Screens;

use Rishadblack\SyllooAppBase\Contracts\DeviceMember;
use Rishadblack\SyllooAppBase\Erp\OfficeAnswer;
use Rishadblack\SyllooAppBase\Pin\PinSignIn;
use Rishadblack\SyllooAppBase\SignIn\BrowserSignIn;
use Rishadblack\SyllooAppBase\SignIn\SignedInPerson;
use Rishadblack\SyllooAppBase\SignIn\SignInRequest;

/**
 * Sign in (step 2): the name tiles of the people already signed in here (tap one: their PIN), and "Sign in with
 * browser" (device-api.md §1 `sign-in/start` → the browser → `sign-in/finish`). While the person signs in in the
 * browser the screen waits and asks the office every `poll_seconds`; the app also runs `poll` at once when the browser
 * hands back (`<scheme>://signed-in`). A member and an employee answer both work ({@see SignedInPerson}).
 *
 * After the browser: a person with a PIN here is in; a person without one (or who forgot it) creates one.
 *
 * The office's browser address must be a web page (https; http outside production). An app may list the devices in
 * use when the plan's devices are full (config `screens.sign_in.device_limit` = `list`: step `limit`).
 *
 * Steps: `choose`, `waiting`, `limit`. Hook places: `sign_in.top`, `sign_in.below_tiles`, `sign_in.waiting`, `sign_in.bottom`.
 */
class SignInScreen extends Screen
{
    public const REQUEST_SESSION_KEY = 'sylloo-app-base.sign_in.';

    public function name(): string
    {
        return 'sign_in';
    }

    public function start(array $input = []): ScreenState
    {
        if (! $this->device()->isActivated()) {
            return (new ScreenState('choose'))->goTo('activate');
        }

        $forgot = Handover::get('intent') === Handover::INTENT_FORGOT_PIN;
        $state = $this->begin($this->storedRequest() !== null ? 'waiting' : 'choose', ['forgot' => $forgot]);

        // "Forgot PIN?" of an app that starts the browser sign-in at once (config `screens.pin.forgot_starts_browser`).
        if (Handover::pull(Handover::START_BROWSER) === true && $state->step === 'choose') {
            $this->doBrowser($state);
        }

        return $state;
    }

    public function view(ScreenState $state): ScreenView
    {
        if ($state->step === 'waiting') {
            $request = $this->storedRequest();

            $host = parse_url((string) ($request['url'] ?? ''), PHP_URL_HOST);

            return new ScreenView(
                title: $this->t('sign_in_waiting_title'),
                text: $this->t('sign_in_waiting_text', ['host' => is_string($host) ? $host : '']),
                step: 2,
                buttons: [
                    new Button('poll', $this->t('sign_in_check'), 'primary', 'bi bi-arrow-repeat'),
                    new Button('open_again', $this->t('sign_in_open_again'), 'outline-primary', 'bi bi-box-arrow-up-right'),
                    new Button('cancel', $this->t('cancel'), 'link'),
                ],
                notices: [
                    ...(($state->data['offline'] ?? false) === true ? [['text' => $this->t('unreachable'), 'variant' => 'warning']] : []),
                    ...$this->notices($state),
                ],
                poll: $request['poll'] ?? 3,
                places: ['sign_in.waiting'],
                icon: 'bi bi-browser-chrome',
                header: $this->businessHeader(),
            );
        }

        if ($state->step === 'limit') {
            return new ScreenView(
                title: $this->t('limit_title'),
                text: (string) ($state->data['limit_text'] ?? $this->t('limit_text')),
                step: 2,
                buttons: [new Button('try_again', $this->t('try_again'), 'primary', 'bi bi-arrow-counterclockwise')],
                notices: $this->notices($state),
                icon: 'bi bi-exclamation-octagon',
                variant: 'warning',
                todo: $this->t('limit_todo'),
                lines: $this->limitLines($state),
                linesTitle: $this->limitTitle($state),
                header: $this->businessHeader(),
            );
        }

        $tiles = [];

        if (($state->data['forgot'] ?? false) !== true) {
            foreach ($this->entry()->members->signedInMembers() as $member) {
                $tiles[$member->memberKey()] = ['label' => $member->memberName(), 'photo' => $member->photoUrl(), 'initials' => self::initialsOf($member->memberName())];
            }
        }

        return new ScreenView(
            title: $this->t('sign_in_title'),
            text: ($state->data['forgot'] ?? false) === true ? $this->t('sign_in_forgot_text') : ($tiles !== [] ? $this->t('sign_in_tiles_text') : $this->t('sign_in_text')),
            step: 2,
            fields: $tiles !== [] ? [new Field('member', 'tiles', $this->t('sign_in_who'), null, $tiles, 'pick')] : [],
            buttons: [
                new Button('browser', $this->t('sign_in_browser'), 'primary', 'bi bi-box-arrow-up-right'),
                ...(config('sylloo-app-base.screens.sign_in.this_device', true) !== false ? [new Button('this_device', $this->t('this_device_title'), 'link', 'bi bi-info-circle')] : []),
            ],
            notices: $this->notices($state),
            places: ['sign_in.top', 'sign_in.below_tiles', 'sign_in.bottom'],
            header: $this->businessHeader(),
        );
    }

    /**
     * The screen's context, with the address the browser was sent to while it waits (`browser_url`: the till's "Copy
     * the link").
     *
     * @return array<string, mixed>
     */
    public function context(?ScreenState $state = null): array
    {
        $context = parent::context($state);

        if ($state?->step === 'waiting') {
            $context['browser_url'] = $this->storedRequest()['url'] ?? null;
        }

        return $context;
    }

    /**
     * Tap a name tile: their PIN.
     *
     * @param  array<string, mixed>  $fields
     */
    protected function doPick(ScreenState $state, array $fields, ?string $value): void
    {
        $members = $this->entry()->members;
        $member = is_string($value) ? $members->member($value) : null;

        if ($member instanceof DeviceMember && $this->hooks->hasStep('sign_in.pick')) {
            // The app's work on a tap (the till asks the office for the person's PIN hash); it may end the sign-in.
            $this->hooks->runSteps('sign_in.pick', $member, $this->context($state));
            $member = $members->member($member->memberKey());
        }

        if (! $member instanceof DeviceMember || ! $member->hasValidToken()) {
            $state->problem($this->t('sign_in_ended'));

            return;
        }

        Handover::put('member', $member->memberKey());
        $state->goTo('pin');
    }

    /**
     * "This device": the activation card (which business, LIVE / PRACTICE, the device) and "Use another activation
     * code", without signing in.
     */
    protected function doThisDevice(ScreenState $state): void
    {
        Handover::put(Handover::THIS_DEVICE, true);
        $state->goTo('activate');
    }

    /**
     * "Sign in with browser": start a request at the office and open its page in the browser.
     */
    protected function doBrowser(ScreenState $state): void
    {
        $secret = BrowserSignIn::newSecret();
        $answer = $this->entry()->office()->signInStart(BrowserSignIn::challengeFor($secret));

        if ($answer->isUnreachable()) {
            $state->problem($this->t('sign_in_unreachable'));

            return;
        }

        if (! $answer->ok()) {
            $this->refused($state, $answer);

            return;
        }

        // Only a real request and a web page are kept and opened (never `javascript:` or a file).
        if (! is_string($answer->get('request_id')) || $answer->get('request_id') === '' || ! self::isWebPage($answer->get('browser_url'))) {
            $state->problem($this->t('unexpected'));

            return;
        }

        $expiresIn = is_numeric($answer->get('expires_in')) ? (int) $answer->get('expires_in') : 600;
        $poll = is_numeric($answer->get('poll_seconds')) ? max(2, min(30, (int) $answer->get('poll_seconds'))) : 3;
        $request = new SignInRequest((string) $answer->get('request_id'), $secret, now()->addSeconds(max(60, $expiresIn))->timestamp);

        session()->put($this->sessionKey(), ['request' => $request->toArray(), 'url' => (string) $answer->get('browser_url'), 'poll' => $poll]);

        $state->step = 'waiting';
        $state->open = (string) $answer->get('browser_url');
    }

    protected function doOpenAgain(ScreenState $state): void
    {
        $state->open = $this->storedRequest()['url'] ?? null;
    }

    protected function doCancel(ScreenState $state): void
    {
        session()->forget($this->sessionKey());
        $state->step = 'choose';
    }

    /**
     * Ask the office whether the person pressed Allow (every few seconds, "I signed in", the browser's hand-back).
     */
    protected function doPoll(ScreenState $state): void
    {
        $stored = $this->storedRequest();
        $request = SignInRequest::fromArray($stored['request'] ?? null);

        if ($request === null || $request->isExpired()) {
            session()->forget($this->sessionKey());
            $state->step = 'choose';
            $state->problem($this->t('sign_in_expired'));

            return;
        }

        $answer = $this->entry()->office()->signInFinish($request->requestId, $request->secret);
        $state->data['offline'] = $answer->isUnreachable();

        if ($answer->isPending() || $answer->isUnreachable()) {
            return;
        }

        session()->forget($this->sessionKey());
        $person = $answer->ok() ? SignedInPerson::fromAnswer($answer->answer) : null;

        if ($person === null) {
            $state->step = 'choose';
            $answer->ok() ? $state->problem($this->t('unexpected')) : $this->refused($state, $answer);

            return;
        }

        $this->signedIn($state, $person);
    }

    /**
     * The office said who signed in: keep their sign-in, then they are in (PIN known here) or create a PIN.
     */
    protected function signedIn(ScreenState $state, SignedInPerson $person): void
    {
        $entry = $this->entry();
        $member = $entry->members->keepSignIn($person);
        $pins = $entry->pins();
        $this->hooks->runSteps('sign_in.kept', $member, $person, $this->context($state));

        PinSignIn::forgetFailures($member);
        $pins->rememberHash($member, $person->pinHash);
        $pins->confirmOnline($member);

        $forgot = Handover::pull('intent') === Handover::INTENT_FORGOT_PIN;

        if (! $forgot && filled($entry->members->pinHash($member))) {
            $this->enter($state, $member, false, $person);

            return;
        }

        Handover::put('member', $member->memberKey());

        if ($forgot) {
            Handover::put(Handover::NEW_PIN, true);
        }

        if (self::createsPinOnPinScreen()) {
            Handover::put('intent', Handover::INTENT_CREATE_PIN);
            $state->goTo('pin');

            return;
        }

        $state->goTo('create_pin');
    }

    protected function refused(ScreenState $state, OfficeAnswer $answer): void
    {
        $go = $this->reportRefusal($state, 'sign_in', $answer);

        if ($go !== null) {
            $state->goTo($go);

            return;
        }

        // The plan's devices are all in use: list them (config `screens.sign_in.device_limit` = `list`).
        if ($answer->refusal()?->isDeviceLimit() === true && config('sylloo-app-base.screens.sign_in.device_limit') === 'list') {
            $this->showLimit($state, $answer);

            return;
        }

        $state->problem($answer->text());
    }

    /**
     * "Try again" on the all-devices-in-use step: back to the names and the browser button.
     */
    protected function doTryAgain(ScreenState $state): void
    {
        $state->step = 'choose';
        unset($state->data['limit_text'], $state->data['limit_devices'], $state->data['limit_allowed']);
    }

    /**
     * The first letters of the first two words of a name ("Ayesha Rahman" → "AR"), for a tile without a photo.
     */
    public static function initialsOf(string $name): string
    {
        $words = array_slice(array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: [], fn (string $word) => $word !== '')), 0, 2);

        return implode('', array_map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)), $words)) ?: '?';
    }

    /**
     * Whether the office's browser address is a web page the device may open: https, or http outside production.
     */
    public static function isWebPage(mixed $url): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'https' || ($scheme === 'http' && ! app()->isProduction());
    }

    /**
     * The app was opened from a link while Sign in shows (NativePHP's `OpenedFromURL`, a phone's intent): the app's
     * `sign_in.link` steps run, and the browser's hand-back (`<scheme>://signed-in`) asks the office at once.
     */
    public function openedFromLink(ScreenState $state, mixed $url): ScreenState
    {
        $link = is_array($url) ? ($url['url'] ?? ($url[0] ?? null)) : $url;

        if (! is_string($link) || $link === '') {
            return $state;
        }

        $this->hooks->runSteps('sign_in.link', $link, $this->context($state));

        if ($state->step === 'waiting' && BrowserSignIn::isSignedInLink($link, (string) config('sylloo-app-base.app.link_scheme', 'sylloo-app'))) {
            return $this->act($state, 'poll');
        }

        return $state;
    }

    /**
     * @return array{request: array<string, mixed>, url: string, poll: int}|null
     */
    protected function storedRequest(): ?array
    {
        $stored = session()->get($this->sessionKey());

        return is_array($stored) && is_array($stored['request'] ?? null)
            ? ['request' => $stored['request'], 'url' => (string) ($stored['url'] ?? ''), 'poll' => (int) ($stored['poll'] ?? 3)]
            : null;
    }

    protected function sessionKey(): string
    {
        return self::REQUEST_SESSION_KEY.$this->entry()->id;
    }
}
