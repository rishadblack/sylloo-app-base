<?php

namespace Rishadblack\SyllooAppBase\Screens;

use Rishadblack\SyllooAppBase\Erp\OfficeClient;
use Rishadblack\SyllooAppBase\Events\PinSaved;
use Rishadblack\SyllooAppBase\Pin\PinSignIn;
use Rishadblack\SyllooAppBase\Pin\Presence;
use Rishadblack\SyllooAppBase\Release\DeviceRelease;

/**
 * PIN and lock settings of the person who is in: change the PIN (current PIN first; the office keeps the new one),
 * how soon the device locks itself, "Lock now", and "Deactivate this device" (asks first; then the release runs).
 *
 * Step: `settings`. Hook place: `pin_settings.bottom`. Moment: `deactivate`.
 */
class PinSettingsScreen extends Screen
{
    public function name(): string
    {
        return 'pin_settings';
    }

    public function start(array $input = []): ScreenState
    {
        // Only for the person who is in (not a member some screen handed over), and never while locked: a link
        // (`<scheme>://settings/pin`) must not change the idle lock, deactivate the device or turn the fingerprint on
        // (audit H3).
        $key = $this->presence->memberKey($this->entry()->id);
        $member = $key !== null ? $this->entry()->members->member($key) : null;

        if ($member === null) {
            return (new ScreenState('settings'))->goTo('sign_in');
        }

        if ($this->presence->lockWhenIdle($this->device())) {
            return (new ScreenState('settings'))->goTo('lock');
        }

        return $this->begin('settings', ['member' => $member->memberKey(), 'idle' => (string) Presence::idleMinutes($this->device())]);
    }

    /**
     * Every action first asks whether the device locked meanwhile (idle, "Lock now" elsewhere) or the person left:
     * then nothing runs and the screen goes there (audit H3).
     *
     * @param  array<string, mixed>  $fields
     */
    public function act(ScreenState $state, string $action, array $fields = [], ?string $value = null): ScreenState
    {
        if ($this->presence->memberKey($this->entry()->id) !== ($state->data['member'] ?? null)) {
            $state->goTo('sign_in');

            return $state;
        }

        if ($this->presence->lockWhenIdle($this->device())) {
            $state->goTo('lock');

            return $state;
        }

        $this->presence->touch();

        return parent::act($state, $action, $fields, $value);
    }

    public function view(ScreenState $state): ScreenView
    {
        $choices = ['0' => $this->t('idle_never')];

        foreach ((array) config('sylloo-app-base.pin.idle_choices', []) as $minutes) {
            $choices[(string) $minutes] = $this->t('idle_minutes', ['minutes' => (int) $minutes]);
        }

        return new ScreenView(
            title: $this->t('pin_settings_title'),
            text: $this->t('pin_settings_text'),
            fields: [
                new Field('current_pin', 'pin', $this->t('pin_current_label')),
                new Field('new_pin', 'pin', $this->t('pin_new_label'), $this->t('pin_hint')),
                new Field('new_pin_confirmation', 'pin', $this->t('pin_again_label')),
                new Field('idle', 'select', $this->t('idle_label'), $this->t('idle_hint'), $choices),
            ],
            buttons: [
                new Button('change_pin', $this->t('pin_change_button'), 'primary', 'bi bi-shield-lock'),
                new Button('save_idle', $this->t('idle_save'), 'outline-primary', 'bi bi-hourglass-split'),
                new Button('lock_now', $this->t('lock_now'), 'outline-secondary', 'bi bi-lock'),
                new Button('deactivate', $this->t('deactivate'), 'outline-danger', 'bi bi-power'),
            ],
            notices: $this->notices($state),
            places: ['pin_settings.bottom'],
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function doChangePin(ScreenState $state, array $fields): void
    {
        $entry = $this->entry();
        $member = $this->memberFor($state);

        if ($member === null || ! $member->hasValidToken()) {
            $state->goTo('sign_in');

            return;
        }

        $current = (string) ($fields['current_pin'] ?? '');

        // An empty or short Current PIN is asked for again; it is not a wrong try (phone check 2026-11-20).
        if (preg_match('/^\d{4,6}$/', $current) !== 1) {
            $state->error('current_pin', $this->t($current === '' ? 'pin_current_needed' : 'pin_format'));

            return;
        }

        $result = $entry->pins()->check($member, $current);

        if ($result === PinSignIn::SIGNED_OUT) {
            $this->presence->leave();
            Handover::problem($this->t('pin_signed_out'));
            $state->goTo('sign_in');

            return;
        }

        if ($result === PinSignIn::WRONG) {
            $state->error('current_pin', $this->t('pin_wrong', ['tries' => PinSignIn::triesLeft($member)]));

            return;
        }

        $pin = (string) ($fields['new_pin'] ?? '');
        $problem = PinSignIn::problemWith($pin);

        if ($problem !== null) {
            $state->error('new_pin', (string) __($problem));

            return;
        }

        if ($pin !== (string) ($fields['new_pin_confirmation'] ?? '')) {
            $state->error('new_pin_confirmation', $this->t('pin_mismatch'));

            return;
        }

        $answer = (new OfficeClient($entry->members->deviceApi($member)))->setPin($pin);

        if (! $answer->ok()) {
            $state->problem($answer->isUnreachable() ? $this->t('pin_needs_office') : $answer->text());

            return;
        }

        $entry->pins()->rememberPin($member, $pin, $answer->get('pin_hash'));
        event(new PinSaved($entry->id, $member->memberKey()));
        $state->toast($this->t('pin_changed'));
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function doSaveIdle(ScreenState $state, array $fields): void
    {
        $minutes = (string) ($fields['idle'] ?? '');
        $allowed = ['0', ...array_map('strval', (array) config('sylloo-app-base.pin.idle_choices', []))];

        if (! in_array($minutes, $allowed, true)) {
            $state->error('idle', $this->t('idle_invalid'));

            return;
        }

        $this->device()->store()->set(Presence::IDLE_SETTING, (int) $minutes);
        $state->data['idle'] = $minutes;
        $state->toast($this->t('idle_saved'));
    }

    protected function doLockNow(ScreenState $state): void
    {
        $this->presence->lock();
        $state->goTo('lock');
    }

    /**
     * "Deactivate this device": asks first (the app may ask its own question), then the office frees the seat and the
     * release runs (send what waits, remove everything). Online only.
     */
    protected function doDeactivate(ScreenState $state): void
    {
        $own = new Question($this->t('deactivate_title'), $this->t('deactivate_text', ['business' => $this->device()->businessName() ?? $this->t('this_business')]), $this->t('deactivate'), $this->t('cancel'), true);

        if ($this->askFirst($state, 'deactivate', 'deactivate', $own)) {
            return;
        }

        $result = $this->entry()->releaseFrame()->deactivate();

        if ($result === DeviceRelease::OFFLINE) {
            $state->problem($this->t('deactivate_offline'));

            return;
        }

        if ($result === DeviceRelease::REFUSED) {
            $state->problem($this->t('unexpected'));

            return;
        }

        $this->presence->leave();
        $state->goTo('state');
    }
}
