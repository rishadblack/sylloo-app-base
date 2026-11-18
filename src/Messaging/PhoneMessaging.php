<?php

namespace Rishadblack\SyllooAppBase\Messaging;

use Rishadblack\SyllooAppBase\Contracts\DeviceEntries;
use Rishadblack\SyllooAppBase\Contracts\MessagingPhone;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Erp\OfficeAnswer;
use Rishadblack\SyllooAppBase\Erp\OfficeClient;
use Rishadblack\SyllooAppBase\Support\AppInfo;
use Rishadblack\SyllooAppBase\Support\Texts;

/**
 * Phone SMS for an app (phone-messaging-plan.md §4, §5): the business's SMS sent from this phone's SIM. What an app
 * calls, so it never talks to the office or the phone package about it itself:
 *
 *     $messaging = app(PhoneMessaging::class);
 *     $messaging->enabled();      // config sylloo-app-base.messages.enabled, and a phone that can send
 *     $messaging->keepGoing();    // at every return to the app: start or stop the background job as the office says
 *     $messaging->needsAnswer();  // the office asks this phone; show the accept screen (a badge, a banner)
 *     $messaging->menuEntry();    // ['screen' => 'messages' | 'messages_accept', 'label' => …, 'badge' => bool]
 *
 * The screens are `messages_accept` (Screens\MessagesAcceptScreen) and `messages` (Screens\MessagesScreen).
 */
class PhoneMessaging
{
    public function __construct(protected DeviceEntries $entries) {}

    /**
     * The app turned phone SMS on and this phone can send (the phone package bound, its job switched on).
     */
    public function enabled(): bool
    {
        return config('sylloo-app-base.messages.enabled', false) === true && $this->phone() !== null && $this->device()->isActivated();
    }

    public function phone(): ?MessagingPhone
    {
        if (! app()->bound(MessagingPhone::class)) {
            return null;
        }

        $phone = app(MessagingPhone::class);

        return $phone->available() ? $phone : null;
    }

    public function device(): DeviceState
    {
        return $this->entries->current()->state;
    }

    /**
     * `GET messages` with this device's key.
     */
    public function inbox(): OfficeAnswer
    {
        return OfficeClient::for($this->device())->messages();
    }

    /**
     * At every return to the app (and when the app opens): ask the office, then hand the key to the background job
     * and wake it while this phone is the connected one, or stop it (forgetting the key) when the connection ended.
     * Offline: only wake the job, it tries by itself. Returns the office's answer when it gave one.
     *
     * @return array<string, mixed>|null
     */
    public function keepGoing(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $phone = $this->phone();
        $answer = $this->inbox();

        if (! $answer->ok()) {
            if ($answer->isRefused() && in_array($answer->code(), ['feature_off', 'device_released'], true)) {
                $phone?->stop();
            } else {
                $phone?->wake();
            }

            return null;
        }

        $this->connected($answer->answer) ? $this->startPhone() : $phone?->stop();

        return $answer->answer;
    }

    /**
     * Hand the device key, the office's address and the app version to the background job.
     */
    public function startPhone(): bool
    {
        $key = $this->device()->deviceKey();
        $phone = $this->phone();

        if ($phone === null || ! is_string($key) || $key === '') {
            return false;
        }

        return $phone->start(rtrim((string) config('erp.base_url'), '/'), $key, AppInfo::version());
    }

    /**
     * The office asks this phone to send the business's SMS (pairing `requested`).
     *
     * @param  array<string, mixed>|null  $inbox  an answer of `GET messages` already in hand
     */
    public function needsAnswer(?array $inbox = null): bool
    {
        if ($inbox === null) {
            if (! $this->enabled()) {
                return false;
            }

            $answer = $this->inbox();
            $inbox = $answer->ok() ? $answer->answer : [];
        }

        return self::pairingState($inbox) === 'requested';
    }

    /**
     * What an app's menu shows (the till's More, Sylloo App's Staff menu): the screen to open, its label, and whether a
     * question waits. Null when phone SMS is off here.
     *
     * @param  array<string, mixed>|null  $inbox
     * @return array{screen: string, label: string, badge: bool}|null
     */
    public function menuEntry(?array $inbox = null): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $asked = $this->needsAnswer($inbox);

        return [
            'screen' => $asked ? 'messages_accept' : 'messages',
            'label' => Texts::get('messages_menu'),
            'badge' => $asked,
        ];
    }

    /**
     * @param  array<string, mixed>  $inbox
     */
    public function connected(array $inbox): bool
    {
        return ($inbox['connected'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $inbox
     */
    public static function pairingState(array $inbox): ?string
    {
        $state = is_array($inbox['pairing'] ?? null) ? ($inbox['pairing']['state'] ?? null) : null;

        return is_string($state) ? $state : null;
    }
}
