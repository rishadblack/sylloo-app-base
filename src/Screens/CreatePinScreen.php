<?php

namespace Rishadblack\SyllooAppBase\Screens;

/**
 * Create PIN (step 3): after the browser sign-in, a person without a PIN here (or who forgot it) chooses 4 digits,
 * twice. The office keeps it (`POST pin`), so it also works on the person's other devices; it works here at once.
 * With config `sylloo-app-base.screens.pin.create_here` the PIN screen asks for the new PIN itself ({@see PinScreen}).
 *
 * Step: `pin`. Hook place: `create_pin.bottom`.
 */
class CreatePinScreen extends Screen
{
    public function name(): string
    {
        return 'create_pin';
    }

    public function start(array $input = []): ScreenState
    {
        // Only the person the sign-in or the PIN screen sent here (Handover::CREATE_PIN_FOR, taken once). A link
        // (`<scheme>://create-pin`) or a reload goes where the device must go first, else home: never a new PIN for
        // the person who is in on a locked device.
        $for = Handover::pull(Handover::CREATE_PIN_FOR);

        if (! is_string($for) || $for === '') {
            return (new ScreenState('pin'))->goTo(app(FirstScreen::class)->name() ?? 'home');
        }

        $member = $this->entry()->members->member($for);

        if ($member === null || ! $member->hasValidToken()) {
            return (new ScreenState('pin'))->goTo('sign_in');
        }

        return $this->begin('pin', ['member' => $member->memberKey(), 'name' => $member->memberName(), 'new_pin' => Handover::pull(Handover::NEW_PIN) === true]);
    }

    public function view(ScreenState $state): ScreenView
    {
        return $this->createPinView($state, ['create_pin.bottom']);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function doSave(ScreenState $state, array $fields): void
    {
        $this->saveNewPin($state, $fields);
    }
}
