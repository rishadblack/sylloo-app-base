<?php

namespace Rishadblack\SyllooAppBase\Screens;

use Rishadblack\SyllooAppBase\Contracts\DeviceEntries;
use Rishadblack\SyllooAppBase\Contracts\DeviceMember;
use Rishadblack\SyllooAppBase\Erp\OfficeAnswer;
use Rishadblack\SyllooAppBase\Erp\OfficeClient;
use Rishadblack\SyllooAppBase\Messaging\PhoneMessaging;
use Rishadblack\SyllooAppBase\Pin\Presence;

/**
 * The phone's yes to "May this phone send the business's SMS?" (phone-messaging-plan.md §4 point 4): Android's SMS
 * permission first, then one plain sentence with the SIM and who pays ("This phone will send XYZ's SMS from SIM 1 ·
 * Grameenphone. SMS are paid from this SIM."), a SIM to choose on a two-SIM phone, Allow or Refuse. The answer goes
 * to the office with the signed-in member's token (`POST messages/pairing`); the office checks the member's messaging
 * right. After Allow the background job gets the device key and starts.
 *
 * Steps: `permission`, `ask`, `no_sim`, `connected`, `nothing`, `offline`, `unavailable`. Actions: `allow_android`,
 * `accept`, `refuse`, `check_again`, `open_messages`, `poll` (while Android's question is open). Hook places:
 * `messages_accept.top`, `messages_accept.bottom`. Moment: `messages_accept` (before Allow is sent: the till asks for
 * the manager's approval here).
 */
class MessagesAcceptScreen extends Screen
{
    public function __construct(DeviceEntries $entries, Hooks $hooks, Presence $presence, protected PhoneMessaging $messaging)
    {
        parent::__construct($entries, $hooks, $presence);
    }

    public function name(): string
    {
        return 'messages_accept';
    }

    public function start(array $input = []): ScreenState
    {
        $state = $this->begin('nothing');
        $this->load($state);

        return $state;
    }

    public function view(ScreenState $state): ScreenView
    {
        $business = (string) ($state->data['business'] ?? $this->device()->businessName() ?? $this->t('this_business'));
        $places = ['messages_accept.top', 'messages_accept.bottom'];
        $refuse = new Button('refuse', $this->t('messages_refuse'), 'outline-danger', 'bi bi-x-circle');
        $again = new Button('check_again', $this->t('check_again'), 'outline-secondary', 'bi bi-arrow-clockwise');

        return match ($state->step) {
            'permission' => new ScreenView(
                title: $this->t('messages_accept_title'),
                text: $this->t('messages_accept_asks', ['business' => $business]),
                buttons: [new Button('allow_android', $this->t('messages_allow_android'), 'primary', 'bi bi-shield-check'), $refuse],
                notices: $this->notices($state),
                poll: ($state->data['asked'] ?? false) === true ? 2 : null,
                places: $places,
                icon: 'bi bi-chat-dots',
                todo: $this->t('messages_permission_todo'),
            ),
            'ask' => new ScreenView(
                title: $this->t('messages_accept_title'),
                text: $this->t('messages_accept_sentence', ['business' => $business, 'sim' => $this->chosenLabel($state)]),
                fields: count($this->sims($state)) > 1 ? [new Field('sim', 'select', $this->t('messages_sim_label'), $this->t('messages_sim_hint'), $this->simOptions($state))] : [],
                buttons: [new Button('accept', $this->t('messages_allow'), 'primary', 'bi bi-check-circle'), $refuse],
                notices: $this->notices($state),
                places: $places,
                icon: 'bi bi-chat-dots',
                todo: $this->t('messages_accept_note'),
            ),
            'no_sim' => new ScreenView(
                title: $this->t('messages_no_sim_title'),
                text: $this->t('messages_no_sim_text'),
                buttons: [$again, $refuse],
                notices: $this->notices($state),
                places: $places,
                icon: 'bi bi-sim',
                variant: 'warning',
            ),
            'connected' => new ScreenView(
                title: $this->t('messages_connected_title'),
                text: $this->t('messages_connected_text', ['business' => $business, 'sim' => (string) ($state->data['sim_label'] ?? '')]),
                buttons: [new Button('open_messages', $this->t('messages_open'), 'primary', 'bi bi-chat-dots')],
                notices: $this->notices($state),
                places: $places,
                icon: 'bi bi-check-circle',
                variant: 'success',
            ),
            'offline' => new ScreenView(
                title: $this->t('messages_accept_title'),
                text: $this->t('unreachable'),
                buttons: [$again],
                notices: $this->notices($state),
                places: $places,
                icon: 'bi bi-wifi-off',
                variant: 'warning',
            ),
            'unavailable' => new ScreenView(
                title: $this->t('messages_unavailable_title'),
                text: $this->t('messages_unavailable_text'),
                notices: $this->notices($state),
                places: $places,
                icon: 'bi bi-chat-dots',
                variant: 'secondary',
            ),
            default => new ScreenView(
                title: $this->t('messages_nothing_title'),
                text: $this->t('messages_nothing_text'),
                buttons: [$again],
                notices: $this->notices($state),
                places: $places,
                icon: 'bi bi-chat-dots',
                variant: 'secondary',
            ),
        };
    }

    protected function doAllowAndroid(ScreenState $state): void
    {
        $phone = $this->messaging->phone();

        if ($phone === null) {
            $state->step = 'unavailable';

            return;
        }

        if ($phone->hasPermission()) {
            $this->toAsk($state);

            return;
        }

        $state->data['asked'] = $phone->askPermission();

        if ($state->data['asked'] !== true) {
            $state->problem($this->t('messages_permission_failed'));
        }
    }

    /**
     * While Android's question is open: go on once the person allowed it.
     */
    protected function doPoll(ScreenState $state): void
    {
        if ($this->messaging->phone()?->hasPermission() === true) {
            $this->toAsk($state);
        }
    }

    protected function doCheckAgain(ScreenState $state): void
    {
        $this->load($state);
    }

    protected function doOpenMessages(ScreenState $state): void
    {
        $state->goTo('messages');
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function doAccept(ScreenState $state, array $fields): void
    {
        $sims = $this->sims($state);
        $slot = (string) ($fields['sim'] ?? $state->data['sim'] ?? '');
        $sim = collect($sims)->first(fn (array $sim) => (string) $sim['slot'] === $slot) ?? (count($sims) === 1 ? $sims[0] : null);

        if ($sim === null) {
            $state->error('sim', $this->t('messages_sim_choose'));

            return;
        }

        $state->data['sim'] = (string) $sim['slot'];

        if ($this->askFirst($state, 'messages_accept', 'accept', null, ['sim' => $sim])) {
            return;
        }

        $member = $this->member($state);

        if ($member === null) {
            return;
        }

        $answer = (new OfficeClient($this->entry()->members->deviceApi($member)))->messagesPairing('accept', ['slot' => (int) $sim['slot'], 'label' => mb_substr((string) $sim['label'], 0, 60)]);

        if (! $this->answered($state, $answer)) {
            return;
        }

        if (($answer->answer['connected'] ?? false) === true) {
            $this->messaging->startPhone();
        }

        $state->toast($this->t('messages_accepted'));
        $state->goTo('messages');
    }

    protected function doRefuse(ScreenState $state): void
    {
        $member = $this->member($state);

        if ($member === null) {
            return;
        }

        $answer = (new OfficeClient($this->entry()->members->deviceApi($member)))->messagesPairing('refuse');

        if (! $this->answered($state, $answer)) {
            return;
        }

        $this->messaging->phone()?->stop();
        $state->toast($this->t('messages_refused'), 'info');
        $state->goTo('home');
    }

    /**
     * Read the office's answer; false (and the screen says why) when it was not taken.
     */
    protected function answered(ScreenState $state, OfficeAnswer $answer): bool
    {
        if ($answer->ok()) {
            return true;
        }

        if ($answer->isUnreachable()) {
            $state->problem($this->t('unreachable'));

            return false;
        }

        if ($answer->code() === 'no_pairing') {
            $state->step = 'nothing';
            $state->problem($answer->text());

            return false;
        }

        if ($answer->status === 401) {
            $this->memberFor($state)?->forgetSignIn();
            Handover::problem($answer->text());
            $state->goTo('sign_in');

            return false;
        }

        $state->problem($answer->isRefused() ? $answer->text() : $this->t('unexpected'));

        return false;
    }

    /**
     * The member who answers (the office needs a member's token and right); none: sign in first.
     */
    protected function member(ScreenState $state): ?DeviceMember
    {
        $member = $this->memberFor($state);

        if ($member === null || ! $member->hasValidToken()) {
            Handover::problem($this->t('messages_sign_in_first'));
            $state->goTo('sign_in');

            return null;
        }

        return $member;
    }

    /**
     * Ask the office and the phone where this screen starts.
     */
    protected function load(ScreenState $state): void
    {
        unset($state->data['asked']);

        if (! $this->messaging->enabled()) {
            $state->step = 'unavailable';

            return;
        }

        $answer = $this->messaging->inbox();

        if (! $answer->ok()) {
            $state->step = 'offline';

            if (! $answer->isUnreachable()) {
                $state->problem($answer->isRefused() ? $answer->text() : $this->t('unexpected'));
            }

            return;
        }

        $inbox = $answer->answer;
        $business = is_array($inbox['pairing'] ?? null) && is_string($inbox['pairing']['business'] ?? null) ? $inbox['pairing']['business'] : null;
        $state->data['business'] = $business ?? $this->device()->businessName();
        $state->data['sim_label'] = is_array($inbox['sim'] ?? null) ? (string) ($inbox['sim']['label'] ?? '') : '';

        if (PhoneMessaging::pairingState($inbox) === 'requested') {
            $this->messaging->phone()?->hasPermission() === true ? $this->toAsk($state) : $state->step = 'permission';

            return;
        }

        $state->step = $this->messaging->connected($inbox) ? 'connected' : 'nothing';
    }

    protected function toAsk(ScreenState $state): void
    {
        unset($state->data['asked']);
        $sims = $this->messaging->phone()?->sims() ?? [];
        $state->data['sims'] = $sims;

        if ($state->data['sims'] === []) {
            $state->step = 'no_sim';

            return;
        }

        $state->data['sim'] ??= (string) $state->data['sims'][0]['slot'];
        $state->step = 'ask';
    }

    /**
     * @return list<array{slot: int, label: string}>
     */
    protected function sims(ScreenState $state): array
    {
        return is_array($state->data['sims'] ?? null) ? array_values($state->data['sims']) : [];
    }

    /**
     * The SIMs to choose from, keyed by slot.
     *
     * @return array<int, string>
     */
    protected function simOptions(ScreenState $state): array
    {
        $options = [];

        foreach ($this->sims($state) as $sim) {
            $options[$sim['slot']] = $sim['label'];
        }

        return $options;
    }

    protected function chosenLabel(ScreenState $state): string
    {
        $sims = $this->sims($state);

        if (count($sims) > 1 && ! isset($state->data['sim'])) {
            return $this->t('messages_sim_chosen');
        }

        foreach ($sims as $sim) {
            if ((string) $sim['slot'] === (string) ($state->data['sim'] ?? '')) {
                return $sim['label'];
            }
        }

        return $sims[0]['label'] ?? $this->t('messages_sim_chosen');
    }
}
