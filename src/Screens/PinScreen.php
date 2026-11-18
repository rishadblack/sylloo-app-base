<?php

namespace Rishadblack\SyllooAppBase\Screens;

use Rishadblack\SyllooAppBase\Contracts\DeviceMember;
use Rishadblack\SyllooAppBase\Erp\OfficeClient;
use Rishadblack\SyllooAppBase\Pin\PinSignIn;

/**
 * PIN (step 3): a person signed in here before types their 4 digits on the PIN pad. Within the offline days the PIN
 * is checked here (works offline); after them the office confirms the sign-in first (`GET me`), and the offline days
 * start again. Five wrong PINs in a row end the person's sign-in here. "Forgot PIN": sign in with the browser, then
 * create a new PIN.
 *
 * With config `sylloo-app-base.screens.pin.create_here` a person without a PIN here (or who forgot it) chooses the new
 * PIN on this screen (step `create`) instead of the Create PIN screen.
 *
 * A person without a PIN here is asked to create one, or (config `screens.pin.no_pin` = `refuse`) told to sign in with
 * the browser; "Forgot PIN?" may start the browser sign-in at once (config `screens.pin.forgot_starts_browser`).
 *
 * Steps: `pad`, `create`. Hook places: `pin.below_pad`, `create_pin.bottom` (step `create`).
 */
class PinScreen extends Screen
{
    public function name(): string
    {
        return 'pin';
    }

    public function start(array $input = []): ScreenState
    {
        $member = $this->memberFor(new ScreenState);

        if ($member === null || ! $member->hasValidToken()) {
            return (new ScreenState('pad'))->goTo('sign_in');
        }

        $asked = Handover::get('intent') === Handover::INTENT_CREATE_PIN;
        // A person without a PIN here creates one at once, unless the app sends them to the browser first (config
        // `screens.pin.no_pin` = `refuse`: the pad says so when they type).
        $noPinHere = blank($this->entry()->members->pinHash($member)) && config('sylloo-app-base.screens.pin.no_pin') !== 'refuse';
        $create = self::createsPinOnPinScreen() && ($asked || $noPinHere);

        if ($asked) {
            Handover::forget('intent');
        }

        return $this->begin($create ? 'create' : 'pad', [...$this->who($member), 'new_pin' => Handover::pull(Handover::NEW_PIN) === true]);
    }

    public function view(ScreenState $state): ScreenView
    {
        if ($state->step === 'create') {
            return $this->createPinView($state, ['create_pin.bottom']);
        }

        return new ScreenView(
            title: $this->t('pin_title', ['name' => (string) ($state->data['name'] ?? '')]),
            text: $this->t('pin_text'),
            step: 3,
            fields: [new Field('pin', 'pin_pad', $this->t('pin_label'), null, ['photo' => $state->data['photo'] ?? null], 'enter')],
            buttons: [
                new Button('forgot', $this->t('pin_forgot'), 'link'),
                new Button('someone_else', $this->t('pin_someone_else'), 'link'),
            ],
            notices: $this->notices($state),
            places: ['pin.below_pad'],
            header: $this->businessHeader(),
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function doEnter(ScreenState $state, array $fields): void
    {
        $member = $this->memberFor($state);

        if ($member === null || ! $member->hasValidToken()) {
            Handover::problem($this->t('sign_in_ended'));
            $state->goTo('sign_in');

            return;
        }

        $offline = $this->confirmWithOffice($state, $member);

        if ($offline === null) {
            return;
        }

        $this->checkPin($state, $member, (string) ($fields['pin'] ?? ''), fn () => $this->enter($state, $member, $offline));
    }

    /**
     * Go in as if the PIN was right, after the app verified the person another way on this device (base 1.1.0: a
     * phone's fingerprint or face through the Keystore prompt the person answered). Not an action: a page can never
     * call it, only the app's own code once its prompt said yes. Everything else of a PIN sign-in holds: the pad must
     * show, the person needs a valid sign-in and a PIN here, the office confirms them after the offline days, and the
     * wrong-PIN count starts again.
     */
    public function enterVerified(ScreenState $state): ScreenState
    {
        $state->settle();

        if ($state->step !== 'pad' || $state->question !== null) {
            return $state;
        }

        $member = $this->memberFor($state);

        if ($member === null || ! $member->hasValidToken()) {
            Handover::problem($this->t('sign_in_ended'));

            return $state->goTo('sign_in');
        }

        if (blank($this->entry()->members->pinHash($member))) {
            $this->createPin($state, $member);

            return $state;
        }

        $offline = $this->confirmWithOffice($state, $member);

        if ($offline === null) {
            return $state;
        }

        PinSignIn::forgetFailures($member);
        $this->enter($state, $member, $offline);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function doSave(ScreenState $state, array $fields): void
    {
        $this->saveNewPin($state, $fields);
    }

    protected function doForgot(ScreenState $state): void
    {
        Handover::put('intent', Handover::INTENT_FORGOT_PIN);

        if (config('sylloo-app-base.screens.pin.forgot_starts_browser', false) === true) {
            Handover::put(Handover::START_BROWSER, true);
        }

        $state->goTo('sign_in');
    }

    protected function doSomeoneElse(ScreenState $state): void
    {
        Handover::forget('member');
        $state->goTo('sign_in');
    }

    /**
     * After the offline days the office confirms the person first. Returns whether the PIN is checked offline (true),
     * online (false), or null when the person may not go on (a problem is set, or they must sign in again).
     */
    protected function confirmWithOffice(ScreenState $state, DeviceMember $member): ?bool
    {
        $entry = $this->entry();

        if (config('sylloo-app-base.pin.offline', true) && PinSignIn::mayUseOffline($member)) {
            return true;
        }

        $answer = (new OfficeClient($entry->members->deviceApi($member)))->me();

        if ($answer->isUnreachable()) {
            $state->problem($this->t('pin_needs_office'));

            return null;
        }

        $go = $this->reportRefusal($state, 'me', $answer);

        if ($go !== null) {
            $state->goTo($go);

            return null;
        }

        if ($answer->status === 401 || $answer->refusal()?->endsSignIn() === true) {
            $member->forgetSignIn();
            Handover::problem($this->t('sign_in_ended'));
            $state->goTo('sign_in');

            return null;
        }

        if ($answer->ok()) {
            $answer->get('pin_set') === false
                ? $entry->members->forgetPinHash($member)
                : $entry->pins()->rememberHash($member, $answer->get('pin_hash'));
            $entry->pins()->confirmOnline($member);
        }

        return false;
    }

    /**
     * Check the PIN; run $whenRight when it is right.
     */
    protected function checkPin(ScreenState $state, DeviceMember $member, string $pin, callable $whenRight): void
    {
        if (preg_match('/^\d{4,6}$/', $pin) !== 1) {
            $state->error('pin', $this->t('pin_format'));

            return;
        }

        $result = $this->entry()->pins()->check($member, $pin);

        match ($result) {
            PinSignIn::CHECKED => $whenRight(),
            PinSignIn::NO_PIN => $this->createPin($state, $member),
            PinSignIn::SIGNED_OUT => $this->signedOut($state),
            default => $state->error('pin', $this->t('pin_wrong', ['tries' => PinSignIn::triesLeft($member)])),
        };
    }

    protected function createPin(ScreenState $state, DeviceMember $member): void
    {
        // An app that sends a person without a PIN here to the browser first (config `screens.pin.no_pin` = `refuse`).
        if (config('sylloo-app-base.screens.pin.no_pin') === 'refuse') {
            $state->error('pin', $this->t('pin_none', ['name' => $member->memberName()]));

            return;
        }

        if (self::createsPinOnPinScreen()) {
            $state->step = 'create';

            return;
        }

        Handover::put('member', $member->memberKey());
        $state->goTo('create_pin');
    }

    protected function signedOut(ScreenState $state): void
    {
        $this->presence->leave();
        Handover::forget('member');
        Handover::problem($this->t('pin_signed_out'));
        $state->goTo('sign_in');
    }

    /**
     * @return array{member: string, name: string, photo: string|null}
     */
    protected function who(DeviceMember $member): array
    {
        return ['member' => $member->memberKey(), 'name' => $member->memberName(), 'photo' => $member->photoUrl()];
    }
}
