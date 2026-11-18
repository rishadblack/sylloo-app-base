<?php

namespace Rishadblack\SyllooAppBase\Screens;

use Rishadblack\SyllooAppBase\Contracts\DeviceEntries;
use Rishadblack\SyllooAppBase\Erp\OfficeClient;
use Rishadblack\SyllooAppBase\Messaging\PhoneMessaging;
use Rishadblack\SyllooAppBase\Pin\Presence;

/**
 * The Messages screen of a phone that sends the business's SMS (phone-messaging-plan.md §5.1): how many wait, sent
 * today of the daily limit, failed today, the SIM, paused or not (Pause / Resume, with the member's token), and what is
 * missing now: the internet (to ask the office) or the signal (to send). "Check now" wakes the background job.
 *
 * Steps: `status`, `not_connected`, `unavailable`. Actions: `pause`, `resume`, `check_now`, `allow_android`,
 * `answer` (the office asks this phone: open the accept screen), `poll` (every 15 s). Hook places: `messages.top`,
 * `messages.bottom`.
 */
class MessagesScreen extends Screen
{
    public const POLL_SECONDS = 15;

    public function __construct(DeviceEntries $entries, Hooks $hooks, Presence $presence, protected PhoneMessaging $messaging)
    {
        parent::__construct($entries, $hooks, $presence);
    }

    public function name(): string
    {
        return 'messages';
    }

    public function start(array $input = []): ScreenState
    {
        $state = $this->begin('status');
        $this->load($state);

        return $state;
    }

    public function view(ScreenState $state): ScreenView
    {
        $places = ['messages.top', 'messages.bottom'];
        $data = $state->data;
        $business = (string) ($this->device()->businessName() ?? $this->t('this_business'));

        if ($state->step === 'unavailable') {
            return new ScreenView(
                title: $this->t('messages_unavailable_title'),
                text: $this->t('messages_unavailable_text'),
                notices: $this->notices($state),
                places: $places,
                icon: 'bi bi-chat-dots',
                variant: 'secondary',
            );
        }

        if ($state->step === 'not_connected') {
            $asked = ($data['pairing'] ?? null) === 'requested';

            return new ScreenView(
                title: $this->t('messages_title'),
                text: $asked ? $this->t('messages_accept_asks_short', ['business' => $business]) : $this->t('messages_not_connected'),
                buttons: $asked ? [new Button('answer', $this->t('messages_answer'), 'primary', 'bi bi-chat-dots')] : [new Button('check_now', $this->t('check_again'), 'outline-secondary', 'bi bi-arrow-clockwise')],
                notices: $this->missing($state),
                poll: self::POLL_SECONDS,
                places: $places,
                icon: 'bi bi-chat-dots',
                variant: 'secondary',
            );
        }

        $paused = ($data['paused'] ?? false) === true;
        $limit = (int) ($data['daily_limit'] ?? 0);
        $sentToday = (int) ($data['sent_today'] ?? 0);

        $rows = [
            $this->t('messages_row_waiting') => isset($data['waiting']) ? (string) $data['waiting'] : '–',
            $this->t('messages_row_sent_today') => $limit > 0 ? $this->t('messages_of_limit', ['count' => $sentToday, 'limit' => $limit]) : (string) $sentToday,
            $this->t('messages_row_failed_today') => (string) (int) ($data['failed_today'] ?? 0),
            $this->t('messages_row_sim') => (string) ($data['sim_label'] ?? '') !== '' ? (string) $data['sim_label'] : '–',
            $this->t('messages_row_daily_limit') => $limit > 0 ? (string) $limit : '–',
        ];

        $buttons = [];

        if (($data['can_send'] ?? true) === false) {
            $buttons[] = new Button('allow_android', $this->t('messages_allow_android'), 'primary', 'bi bi-shield-check');
        }

        $buttons[] = $paused
            ? new Button('resume', $this->t('messages_resume'), 'primary', 'bi bi-play-circle')
            : new Button('pause', $this->t('messages_pause'), 'outline-warning', 'bi bi-pause-circle');
        $buttons[] = new Button('check_now', $this->t('messages_check_now'), 'outline-secondary', 'bi bi-arrow-clockwise');

        return new ScreenView(
            title: $this->t('messages_title'),
            text: $paused
                ? $this->t('messages_paused_text', ['business' => $business])
                : $this->t('messages_sending_text', ['business' => $business, 'sim' => (string) ($data['sim_label'] ?? '')]),
            buttons: $buttons,
            notices: $this->missing($state),
            rows: $rows,
            poll: self::POLL_SECONDS,
            places: $places,
            icon: $paused ? 'bi bi-pause-circle' : 'bi bi-chat-dots',
            variant: $paused ? 'warning' : 'primary',
        );
    }

    protected function doPoll(ScreenState $state): void
    {
        $this->load($state);
    }

    protected function doCheckNow(ScreenState $state): void
    {
        $this->messaging->phone()?->wake();
        $this->load($state);
    }

    protected function doAnswer(ScreenState $state): void
    {
        $state->goTo('messages_accept');
    }

    protected function doAllowAndroid(ScreenState $state): void
    {
        if ($this->messaging->phone()?->askPermission() !== true) {
            $state->problem($this->t('messages_permission_failed'));
        }
    }

    protected function doPause(ScreenState $state): void
    {
        $this->setPaused($state, true);
    }

    protected function doResume(ScreenState $state): void
    {
        $this->setPaused($state, false);
    }

    protected function setPaused(ScreenState $state, bool $paused): void
    {
        $member = $this->memberFor($state);

        if ($member === null || ! $member->hasValidToken()) {
            Handover::problem($this->t('messages_sign_in_first'));
            $state->goTo('sign_in');

            return;
        }

        $answer = (new OfficeClient($this->entry()->members->deviceApi($member)))->messagesPause($paused);

        if (! $answer->ok()) {
            $state->problem($answer->isUnreachable() ? $this->t('unreachable') : ($answer->isRefused() ? $answer->text() : $this->t('unexpected')));

            return;
        }

        if (! $paused) {
            $this->messaging->phone()?->wake();
        }

        $state->toast($this->t($paused ? 'messages_paused' : 'messages_resumed'));
        $this->load($state);
    }

    /**
     * What the office and the phone say now.
     */
    protected function load(ScreenState $state): void
    {
        if (! $this->messaging->enabled()) {
            $state->step = 'unavailable';

            return;
        }

        $answer = $this->messaging->inbox();
        $state->data['offline'] = $answer->isUnreachable();

        if ($answer->ok()) {
            $inbox = $answer->answer;
            $sim = is_array($inbox['sim'] ?? null) ? $inbox['sim'] : [];

            $state->data = [
                ...$state->data,
                'connected' => $this->messaging->connected($inbox),
                'pairing' => PhoneMessaging::pairingState($inbox),
                'paused' => ($inbox['paused'] ?? false) === true,
                'paused_reason' => is_string($inbox['paused_reason'] ?? null) ? $inbox['paused_reason'] : null,
                'sim_slot' => is_int($sim['slot'] ?? null) ? $sim['slot'] : null,
                'sim_label' => is_string($sim['label'] ?? null) ? $sim['label'] : '',
                'daily_limit' => (int) ($inbox['daily_limit'] ?? 0),
                'sent_today' => (int) ($inbox['sent_today'] ?? 0),
                'waiting' => is_array($inbox['messages'] ?? null) ? count($inbox['messages']) : 0,
            ];
        } elseif ($answer->isRefused() && $answer->code() === 'feature_off') {
            $state->data['connected'] = false;
        }

        $phone = $this->messaging->phone();
        $status = $phone?->status(is_int($state->data['sim_slot'] ?? null) ? $state->data['sim_slot'] : null) ?? [];
        $state->data['can_send'] = ($status['can_send'] ?? true) !== false;
        $state->data['service'] = is_string($status['service'] ?? null) ? $status['service'] : null;
        $state->data['failed_today'] = (int) ($status['failed_today'] ?? 0);
        $state->data['phone_offline'] = ($status['online'] ?? true) === false;

        $state->step = ($state->data['connected'] ?? false) === true ? 'status' : (($state->data['offline'] ?? false) && isset($state->data['sim_label']) ? 'status' : 'not_connected');
    }

    /**
     * What is missing now, in plain words: the internet, the signal, Android's permission, or the ERP's pause.
     *
     * @return list<array{text: string, variant: string}>
     */
    protected function missing(ScreenState $state): array
    {
        $data = $state->data;
        $notices = [];

        if (($data['offline'] ?? false) === true || ($data['phone_offline'] ?? false) === true) {
            $notices[] = ['text' => $this->t('messages_missing_internet'), 'variant' => 'danger'];
        }

        $service = $data['service'] ?? null;

        $serviceText = match ($service) {
            'no_signal' => $this->t('messages_missing_no_signal'),
            'no_service' => $this->t('messages_missing_no_service'),
            'radio_off' => $this->t('messages_missing_radio_off'),
            'no_sim' => $this->t('messages_missing_no_sim'),
            default => null,
        };

        if ($serviceText !== null) {
            $notices[] = ['text' => $serviceText, 'variant' => 'warning'];
        }

        if (($data['can_send'] ?? true) === false) {
            $notices[] = ['text' => $this->t('messages_missing_permission'), 'variant' => 'warning'];
        }

        if (($data['paused'] ?? false) === true) {
            $notices[] = ['text' => match ($data['paused_reason'] ?? null) {
                'phone' => $this->t('messages_paused_by_phone'),
                'failures' => $this->t('messages_paused_by_failures'),
                default => $this->t('messages_paused_by_member'),
            }, 'variant' => 'warning'];
        }

        return [...$notices, ...$this->notices($state)];
    }
}
