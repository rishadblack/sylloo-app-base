<?php

namespace Rishadblack\SyllooAppBase\Screens;

use Rishadblack\SyllooAppBase\Events\SignInEnded;

/**
 * Lock: the device locked itself after the idle time (or "Lock now"). Only the person who is in opens it again with
 * their PIN (checked here; five wrong PINs end their sign-in), or "Switch person" leaves it for someone else's sign-in
 * (the person's sign-in here stays; their PIN works next time). "Sign out" ends their sign-in here.
 *
 * Step: `pad`. Hook place: `lock.below_pad`. Moment: `sign_out`.
 */
class LockScreen extends PinScreen
{
    public function name(): string
    {
        return 'lock';
    }

    public function start(array $input = []): ScreenState
    {
        $key = $this->presence->memberKey($this->entry()->id);
        $member = $key !== null ? $this->entry()->members->member($key) : null;

        if ($member === null || ! $member->hasValidToken()) {
            $this->presence->leave();

            return (new ScreenState('pad'))->goTo('sign_in');
        }

        if (! $this->presence->isLocked()) {
            $this->presence->lock();
        }

        return $this->begin('pad', $this->who($member));
    }

    public function view(ScreenState $state): ScreenView
    {
        return new ScreenView(
            title: $this->t('lock_title'),
            text: $this->t('lock_text', ['name' => (string) ($state->data['name'] ?? '')]),
            fields: [new Field('pin', 'pin_pad', $this->t('pin_label'), null, ['photo' => $state->data['photo'] ?? null], 'enter')],
            buttons: [
                new Button('switch_person', $this->t('lock_switch'), 'link'),
                new Button('sign_out', $this->t('sign_out'), 'link'),
            ],
            notices: $this->notices($state),
            places: ['lock.below_pad'],
            icon: 'bi bi-lock',
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function doEnter(ScreenState $state, array $fields): void
    {
        $member = $this->memberFor($state);

        if ($member === null || ! $member->hasValidToken()) {
            $this->signedOut($state);

            return;
        }

        $this->checkPin($state, $member, (string) ($fields['pin'] ?? ''), function () use ($state): void {
            $this->presence->unlock();
            $state->goTo('home');
        });
    }

    protected function doSwitchPerson(ScreenState $state): void
    {
        $this->presence->leave();
        Handover::forget('member');
        $state->goTo('sign_in');
    }

    protected function doSignOut(ScreenState $state): void
    {
        $own = new Question($this->t('sign_out_title'), $this->t('sign_out_text'), $this->t('sign_out'), $this->t('cancel'), true);

        if ($this->askFirst($state, 'sign_out', 'sign_out', $own)) {
            return;
        }

        $entry = $this->entry();
        $member = $this->memberFor($state);

        if ($member !== null) {
            $entry->pins()->endSignIn($member);
            event(new SignInEnded($entry->id, $member->memberKey(), 'signed_out'));
        }

        $this->presence->leave();
        Handover::forget('member');
        $state->goTo('sign_in');
    }
}
