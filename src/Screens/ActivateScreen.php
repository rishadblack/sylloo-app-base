<?php

namespace Rishadblack\SyllooAppBase\Screens;

use Rishadblack\SyllooAppBase\Device\Activation;
use Rishadblack\SyllooAppBase\Device\DeviceEntry;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Erp\RefusalCode;
use Rishadblack\SyllooAppBase\Events\DeviceActivated;
use Rishadblack\SyllooAppBase\Release\DeviceRelease;
use Rishadblack\SyllooAppBase\Support\AppInfo;

/**
 * Activate (step 1): the person types the 16-digit code (or opens a `<scheme>://activate?code=…` link) and names the
 * device ("Name this device", plan §6.1 #1; config `sylloo-app-base.screens.activate.device_name`, on by default), the
 * base asks the office, and shows the confirm card: business, LIVE / PRACTICE, STAFF / EMPLOYEE, the device name. Then
 * "Continue" goes to Sign in, "Use another code" releases this activation first. When every device of the plan is in
 * use, the `limit` step lists the devices in use (name, last used, by whom).
 *
 * Opened again on an activated device ("This device" on Sign in or in the app's menu; base 1.2.0), the same card shows
 * as "This device" with "Back" and "Use another activation code" (Practice to Live, or another business). That runs the
 * full release ({@see DeviceRelease::deactivate()}, as "Deactivate this device"): the office frees the place, the
 * device stops working and signs everybody out, sends what waits, removes everything of the activation, and Activate
 * asks for the new code. Online only. A device that is releasing always goes to its Releasing screen.
 *
 * Steps: `code`, `confirm`, `limit` (the plan's device limit is reached). Hook places: `activate.top`,
 * `activate.below_code`, `activate.confirm_card`, `activate.limit`, `activate.bottom`. Moments: `activate` (before the
 * code is sent, with `code` and `device_name` in the context: the till's "Reset terminal for the new code?" with "14
 * sales are safe on this terminal" as the question's detail), `use_another_code`. Steps of the app
 * ({@see Hooks::STEPS}): `activate.before_keep`, `activate.after_keep`, `activate.continue`, `use_another_code`.
 */
class ActivateScreen extends Screen
{
    public function name(): string
    {
        return 'activate';
    }

    public function start(array $input = []): ScreenState
    {
        $device = $this->device();

        // A device that is releasing finishes its release first (its Releasing screen).
        if ($device->isReleasing()) {
            return (new ScreenState('code'))->goTo('state');
        }

        // An app that sends an activated device on (config `screens.activate.when_activated` = `leave`): a blocked one
        // to its state screen, else to Sign in. "This device" (`?details=1`) always shows the card.
        $details = Handover::pull(Handover::THIS_DEVICE) === true || ($input['details'] ?? null) === '1';

        if (self::leavesWhenActivated() && $device->isActivated() && ! $details) {
            return (new ScreenState('code'))->goTo($device->screen() !== null ? 'state' : 'sign_in');
        }

        if ($device->isActivated()) {
            return $this->begin('confirm', ['card' => $this->card($this->entry()), 'details' => true]);
        }

        $code = Activation::digitsOf($input['code'] ?? '');
        $released = $device->get(DeviceState::RELEASED_FROM_SETTING);

        return $this->begin('code', [
            'code' => Activation::isCode($code) ? Activation::formatted($code) : null,
            'released_from' => is_string($released) ? $released : null,
            ...(self::asksDeviceName() ? ['device_name' => self::defaultDeviceName()] : []),
        ]);
    }

    public function view(ScreenState $state): ScreenView
    {
        return match ($state->step) {
            'confirm' => ($state->data['details'] ?? false) === true ? $this->detailsView($state) : new ScreenView(
                title: $this->t('activate_done_title'),
                text: $this->t('activate_done_text'),
                step: 1,
                buttons: [
                    new Button('continue', $this->t('continue'), 'primary', 'bi bi-arrow-right'),
                    new Button('use_another_code', $this->t('use_another_code'), 'link'),
                ],
                notices: $this->notices($state),
                card: is_array($state->data['card'] ?? null) ? $state->data['card'] : null,
                places: ['activate.confirm_card'],
                icon: 'bi bi-check-circle',
                variant: 'success',
            ),
            'limit' => new ScreenView(
                title: $this->t('limit_title'),
                text: (string) ($state->data['limit_text'] ?? $this->t('limit_text')),
                step: 1,
                buttons: [new Button('try_again', $this->t('try_again'), 'primary', 'bi bi-arrow-counterclockwise')],
                notices: $this->notices($state),
                places: ['activate.limit'],
                icon: 'bi bi-exclamation-octagon',
                variant: 'warning',
                todo: $this->t('limit_todo'),
                lines: $this->limitLines($state),
                linesTitle: $this->limitTitle($state),
            ),
            default => new ScreenView(
                title: $this->t('activate_title'),
                text: $this->t('activate_text'),
                step: 1,
                fields: [
                    new Field('code', 'code', $this->t('activate_code_label'), $this->t('activate_code_hint')),
                    ...(self::asksDeviceName() ? [new Field('device_name', 'text', $this->t('device_name_label'), $this->t('device_name_hint'))] : []),
                ],
                buttons: [
                    new Button('activate', $this->t('activate_button'), 'primary', 'bi bi-check2-circle'),
                    ...(($state->data['app_too_old'] ?? false) === true ? [new Button('update_now', $this->t('update_now'), 'outline-primary', 'bi bi-cloud-arrow-down')] : []),
                ],
                notices: [
                    ...(is_string($state->data['released_from'] ?? null) ? [['text' => $this->t('released_from', ['business' => $state->data['released_from'] ?: $this->t('this_business')]), 'variant' => 'info']] : []),
                    ...$this->notices($state),
                ],
                places: ['activate.top', 'activate.below_code', 'activate.bottom'],
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function doActivate(ScreenState $state, array $fields): void
    {
        $digits = Activation::digitsOf($fields['code'] ?? '');

        if (! Activation::isCode($digits)) {
            $state->error('code', $this->t('code_invalid'));

            return;
        }

        $name = self::defaultDeviceName();

        if (self::asksDeviceName()) {
            $typed = trim((string) ($fields['device_name'] ?? ''));

            if (mb_strlen($typed) > self::DEVICE_NAME_MAX) {
                $state->error('device_name', $this->t('device_name_too_long', ['max' => self::DEVICE_NAME_MAX]));

                return;
            }

            if ($typed === '' && self::requiresDeviceName()) {
                $state->error('device_name', $this->t('device_name_required'));

                return;
            }

            $name = $typed !== '' ? $typed : $name;
            $state->data['device_name'] = $name;
        }

        if ($this->askFirst($state, 'activate', 'activate', null, ['code' => $digits, 'device_name' => $name])) {
            return;
        }

        $entry = $this->entry();
        $device = $entry->state;
        $context = [...$this->context($state), 'code' => $digits, 'device_name' => $name];
        $extra = $this->hooks->payloadFor('activate', $context);
        $base = Activation::payload($digits, AppInfo::kind(), $device->deviceUid(), $name, [], AppInfo::platform());
        $answer = $entry->office()->activate([...$extra, ...$base]);

        if ($answer->ok()) {
            // Nothing is kept from an answer the base or the app can not use (the till: no `terminal.desktop_pos_id`).
            $problem = Activation::isComplete($answer->answer) ? $this->hooks->problemAt('activate.answer', $answer->answer, $context) : $this->t('unexpected');

            if ($problem !== null) {
                $state->problem($problem);

                return;
            }

            $this->hooks->runSteps('activate.before_keep', $answer->answer, $context);
            $device->rememberActivation($answer->answer, $digits, is_string($answer->get('device.name')) ? $answer->get('device.name') : $name);
            $this->hooks->runSteps('activate.after_keep', $answer->answer, $this->context($state) + $context);
            event(new DeviceActivated($entry->id, $answer->answer));

            $state->step = 'confirm';
            $state->data = ['card' => $this->card($entry)];

            return;
        }

        $go = $this->reportRefusal($state, 'activate', $answer);

        if ($go !== null) {
            $state->goTo($go);

            return;
        }

        if ($answer->refusal()?->isDeviceLimit() === true) {
            $this->showLimit($state, $answer);

            return;
        }

        if ($answer->is(RefusalCode::AppTooOld)) {
            $state->data['app_too_old'] = true;
        }

        if ($answer->is(RefusalCode::InvalidCode) || $answer->fieldError('code') !== null) {
            $state->error('code', $answer->fieldError('code') ?? $answer->text());

            return;
        }

        $state->problem($answer->text());
    }

    protected function doContinue(ScreenState $state): void
    {
        $this->hooks->runSteps('activate.continue', $this->context($state));
        $state->goTo('sign_in');
    }

    protected function doTryAgain(ScreenState $state): void
    {
        $state->step = 'code';
        unset($state->data['limit_text'], $state->data['limit_devices'], $state->data['limit_allowed']);
    }

    protected function doUpdateNow(ScreenState $state): void
    {
        $this->updateNow($state);
    }

    /**
     * "This device": the activation card of a device opened again after its activation (Sign in's "This device", the
     * app's menu), with "Back" and "Use another activation code".
     */
    protected function detailsView(ScreenState $state): ScreenView
    {
        return new ScreenView(
            title: $this->t('this_device_title'),
            text: $this->t('this_device_text'),
            buttons: [
                new Button('back', $this->t('back'), 'primary', 'bi bi-arrow-left'),
                new Button('use_another_code', $this->t('use_another_activation_code'), 'outline-danger', 'bi bi-arrow-repeat'),
            ],
            notices: $this->notices($state),
            card: is_array($state->data['card'] ?? null) ? $state->data['card'] : null,
            places: ['activate.confirm_card'],
            icon: 'bi bi-info-circle',
        );
    }

    /**
     * "Back" from "This device": the app's home (Sign in when nobody is signed in).
     */
    protected function doBack(ScreenState $state): void
    {
        $state->goTo('home');
    }

    /**
     * "Use another code" / "Use another activation code": the full release of this activation, as "Deactivate this
     * device" ({@see DeviceRelease::deactivate()}): the office frees the place, everybody here is signed out, what waits
     * is sent, everything of the activation is removed, then Activate asks for the new code. Online only. Asked first
     * ("This device" always asks; the confirm card right after activating as config `ask_use_another_code` says).
     */
    protected function doUseAnotherCode(ScreenState $state): void
    {
        $details = ($state->data['details'] ?? false) === true;
        $own = $details || config('sylloo-app-base.screens.activate.ask_use_another_code', true) !== false
            ? new Question($this->t('use_another_title'), $this->t('use_another_warning', $this->warningReplace()), $this->t('use_another_confirm'), $this->t('cancel'), true)
            : null;

        if ($this->askFirst($state, 'use_another_code', 'use_another_code', $own)) {
            return;
        }

        $result = $this->entry()->releaseFrame()->deactivate();

        if ($result === DeviceRelease::OFFLINE) {
            $state->problem($this->t('use_another_offline'));

            return;
        }

        if ($result === DeviceRelease::REFUSED) {
            $state->problem($this->t('unexpected'));

            return;
        }

        $this->hooks->runSteps('use_another_code', $this->context($state));
        $state->goTo('state');
    }

    /**
     * The business and LIVE / PRACTICE of the warning before "Use another activation code".
     *
     * @return array<string, string>
     */
    protected function warningReplace(): array
    {
        $device = $this->device();

        return [
            'business' => $device->businessName() ?? $this->t('this_business'),
            'mode' => $device->isPractice() ? $this->t('mode_practice') : $this->t('mode_live'),
        ];
    }

    /** The longest device name the office keeps. */
    public const DEVICE_NAME_MAX = 60;

    /**
     * Whether Activate asks for the device's name (config `sylloo-app-base.screens.activate.device_name`, on by default).
     */
    public static function asksDeviceName(): bool
    {
        return config('sylloo-app-base.screens.activate.device_name', true) !== false;
    }

    /**
     * Whether a device name must be typed (config `sylloo-app-base.screens.activate.device_name_required`, off by
     * default: an empty name takes the default name).
     */
    public static function requiresDeviceName(): bool
    {
        return config('sylloo-app-base.screens.activate.device_name_required', false) === true;
    }

    /**
     * Whether Activate sends an activated device on instead of showing its confirm card (config
     * `sylloo-app-base.screens.activate.when_activated` = `leave`; default `confirm`).
     */
    public static function leavesWhenActivated(): bool
    {
        return config('sylloo-app-base.screens.activate.when_activated') === 'leave';
    }

    /**
     * The name a device gets when the person names none: config `sylloo-app-base.app.device_name`, else the computer's.
     */
    public static function defaultDeviceName(): string
    {
        return mb_substr((string) (config('sylloo-app-base.app.device_name') ?: Activation::computerName(AppInfo::name())), 0, self::DEVICE_NAME_MAX);
    }

    /**
     * The confirm card of an activation.
     *
     * @return array<string, string|null>
     */
    public function card(DeviceEntry $entry): array
    {
        $device = $entry->state;

        return [
            'business' => $device->businessName(),
            'mode' => $device->isPractice() ? 'practice' : 'live',
            'mode_label' => $device->isPractice() ? $this->t('mode_practice') : $this->t('mode_live'),
            'kind' => $device->kind(),
            'kind_label' => DeviceEntry::kindMark($device->kind()),
            'device_name' => $device->deviceName(),
            'logo' => $device->logoSource(),
        ];
    }
}
