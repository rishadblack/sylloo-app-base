<?php

namespace Rishadblack\SyllooAppBase\Screens;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rishadblack\SyllooAppBase\Contracts\AppUpdater;
use Rishadblack\SyllooAppBase\Contracts\DeviceEntries;
use Rishadblack\SyllooAppBase\Contracts\DeviceMember;
use Rishadblack\SyllooAppBase\Contracts\Notifier;
use Rishadblack\SyllooAppBase\Device\DeviceEntry;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Erp\OfficeAnswer;
use Rishadblack\SyllooAppBase\Erp\OfficeClient;
use Rishadblack\SyllooAppBase\Erp\Refusal;
use Rishadblack\SyllooAppBase\Events\PersonSignedIn;
use Rishadblack\SyllooAppBase\Events\PinSaved;
use Rishadblack\SyllooAppBase\Pin\PinSignIn;
use Rishadblack\SyllooAppBase\Pin\Presence;
use Rishadblack\SyllooAppBase\SignIn\SignedInPerson;
use Rishadblack\SyllooAppBase\Support\AppInfo;
use Rishadblack\SyllooAppBase\Support\Texts;
use Rishadblack\SyllooAppBase\Update\AppUpdateService;

/**
 * The logic of one shared screen, written once (plan §4.2 "Screens"): a face (the web face in Livewire, later a native
 * face) asks it for the first {@see ScreenState}, draws its {@see ScreenView}, and sends the person's actions to
 * {@see self::act()}. A screen never reads a request or draws HTML; it talks to the office through the base's named
 * results and tells the face what to do next (go to a screen, open the browser, show a short message, ask a question).
 *
 * An action `foo_bar` runs the method `doFooBar(ScreenState, array $fields, ?string $value)`; only the actions the
 * current view offers may run ({@see ScreenView::actions()}).
 */
abstract class Screen
{
    /** The question key of the base's own confirmations (when the app asks nothing at that moment). */
    public const OWN_QUESTION = '_base';

    public function __construct(
        protected DeviceEntries $entries,
        protected Hooks $hooks,
        protected Presence $presence,
    ) {}

    /**
     * The screen's name (the route and hook names use it).
     */
    abstract public function name(): string;

    /**
     * The first state, from what the face was opened with (a link's code, a state name).
     *
     * @param  array<string, mixed>  $input
     */
    abstract public function start(array $input = []): ScreenState;

    abstract public function view(ScreenState $state): ScreenView;

    /**
     * Run an action of the person. While a question is open only its answers run (`answer_yes`, `answer_no`).
     *
     * @param  array<string, mixed>  $fields
     */
    public function act(ScreenState $state, string $action, array $fields = [], ?string $value = null): ScreenState
    {
        $state->settle();
        unset($state->data['handed_problem']);

        if ($state->question !== null) {
            return in_array($action, ['answer_yes', 'answer_no'], true)
                ? $this->answer($state, $action === 'answer_yes', $fields, $value)
                : $state;
        }

        if (! in_array($action, $this->view($state)->actions(), true)) {
            return $state;
        }

        $method = 'do'.Str::studly($action);

        if (! method_exists($this, $method)) {
            return $state;
        }

        $this->{$method}($state, $fields, $value);

        return $state;
    }

    /**
     * What the hooks and questions of this screen get: the entry, the business, the mode, the kind and the screen.
     *
     * @return array<string, mixed>
     */
    public function context(?ScreenState $state = null): array
    {
        $entry = $this->entry();

        return [
            'screen' => $this->name(),
            'step' => $state?->step,
            'entry' => $entry->id,
            'business' => $entry->state->businessName(),
            'device_name' => $entry->state->deviceName(),
            'mode' => $entry->state->isActivated() ? ($entry->state->isPractice() ? 'practice' : 'live') : null,
            'kind' => $entry->state->kind(),
            'app' => AppInfo::name(),
            'data' => $state->data ?? [],
        ];
    }

    /**
     * The counted lines the app added at this view's places ({@see Hooks::lines()}), by place: a native face draws them
     * at their place (the web face's hook places do so already).
     *
     * @return array<string, list<array{text: string, count: int|null, hint: string|null, variant: string, icon: string|null}>>
     */
    public function placeLines(ScreenState $state): array
    {
        $context = $this->context($state);
        $lines = [];

        foreach ($this->view($state)->places as $place) {
            $found = $this->hooks->linesFor([$place], $context);

            if ($found !== []) {
                $lines[$place] = $found;
            }
        }

        return $lines;
    }

    protected function entry(): DeviceEntry
    {
        return $this->entries->current();
    }

    protected function device(): DeviceState
    {
        return $this->entry()->state;
    }

    /**
     * A text of the base (`sylloo-app-base::device.<key>`).
     *
     * @param  array<string, mixed>  $replace
     */
    protected function t(string $key, array $replace = []): string
    {
        return Texts::get($key, $replace);
    }

    /**
     * Ask before an action: the app's question at this moment (a hook), else the base's own when given. Returns true
     * when a question is now open (the action waits for the answer); false when the action may go on (nothing to ask,
     * or the person already said yes).
     *
     * @param  array<string, mixed>  $context
     */
    protected function askFirst(ScreenState $state, string $moment, string $action, ?Question $own = null, array $context = []): bool
    {
        if (($state->data['confirmed'][$action] ?? false) === true) {
            unset($state->data['confirmed'][$action]);

            return false;
        }

        $asked = $this->hooks->question($moment, [...$this->context($state), ...$context]);
        $key = $asked['key'] ?? self::OWN_QUESTION;
        $question = $asked['question'] ?? $own;

        if ($question === null) {
            return false;
        }

        $state->question = ['moment' => $moment, 'key' => $key, 'action' => $action, ...$question->toArray()];

        return true;
    }

    /**
     * The person answered the open question: "yes" runs the app's yes and then the action again; "no" runs the app's
     * no and nothing is sent.
     *
     * @param  array<string, mixed>  $fields
     */
    protected function answer(ScreenState $state, bool $yes, array $fields, ?string $value): ScreenState
    {
        $question = $state->question ?? [];
        $state->question = null;
        $moment = (string) ($question['moment'] ?? '');
        $key = (string) ($question['key'] ?? self::OWN_QUESTION);
        $action = (string) ($question['action'] ?? '');

        if ($key !== self::OWN_QUESTION) {
            $said = $this->hooks->answer($moment, $key, $yes, $this->context($state));

            if ($said !== null) {
                $state->toast($said['text'], $said['variant']);
            }
        }

        if (! $yes || $action === '') {
            return $state;
        }

        $state->data['confirmed'][$action] = true;

        return $this->act($state, $action, $fields, $value);
    }

    /**
     * A person is in: remember who (the idle lock watches them) and tell the app.
     */
    protected function enter(ScreenState $state, DeviceMember $member, bool $offline, ?SignedInPerson $person = null): void
    {
        $entry = $this->entry();
        $finish = $this->hooks->finisher('signed_in');

        Handover::forget('member');
        Handover::forget('intent');

        if ($finish !== null) {
            // The app signs the person in its own way (its login, session, workers) and says where to go: a screen
            // name, `home`, or an address of its own (`/terminal`); null stays here.
            $go = $finish($member, $offline, $person, $this->context($state));

            if (is_string($go) && $go !== '') {
                $state->goTo($go);
            }

            return;
        }

        $this->presence->enter($entry->id, $member->memberKey(), $offline);

        event(new PersonSignedIn($entry->id, $member, $offline, $person));

        $state->goTo('home');
    }

    /**
     * The "choose a PIN" view (Create PIN, or the PIN screen with `screens.pin.create_here`).
     *
     * @param  list<string>  $places
     */
    protected function createPinView(ScreenState $state, array $places): ScreenView
    {
        return new ScreenView(
            title: ($state->data['new_pin'] ?? false) === true ? $this->t('new_pin_title') : $this->t('create_pin_title'),
            text: $this->t('create_pin_text', ['name' => (string) ($state->data['name'] ?? ''), 'business' => $this->device()->businessName() ?? $this->t('this_business')]),
            step: 3,
            fields: [
                new Field('pin', 'pin', $this->t('pin_label'), $this->t('pin_hint')),
                new Field('pin_confirmation', 'pin', $this->t('pin_again_label')),
            ],
            buttons: [new Button('save', $this->t('create_pin_button'), 'primary', 'bi bi-shield-lock')],
            notices: $this->notices($state),
            places: $places,
            header: $this->businessHeader(),
        );
    }

    /**
     * Save a new PIN: the office keeps it (`POST pin`), then the person is in.
     *
     * @param  array<string, mixed>  $fields
     */
    protected function saveNewPin(ScreenState $state, array $fields): void
    {
        $pin = (string) ($fields['pin'] ?? '');
        $problem = PinSignIn::problemWith($pin);

        if ($problem !== null) {
            $state->error('pin', (string) __($problem));

            return;
        }

        if ($pin !== (string) ($fields['pin_confirmation'] ?? '')) {
            $state->error('pin_confirmation', $this->t('pin_mismatch'));

            return;
        }

        $entry = $this->entry();
        $member = $this->memberFor($state);

        if ($member === null || ! $member->hasValidToken()) {
            Handover::problem($this->t('sign_in_ended'));
            $state->goTo('sign_in');

            return;
        }

        $answer = (new OfficeClient($entry->members->deviceApi($member)))->setPin($pin);

        if ($answer->isUnreachable()) {
            $state->problem($this->t('pin_needs_office'));

            return;
        }

        if (! $answer->ok()) {
            $go = $this->reportRefusal($state, 'pin', $answer);

            if ($go !== null) {
                $state->goTo($go);

                return;
            }

            if ($answer->refusal()?->endsSignIn() === true || $answer->status === 401) {
                $member->forgetSignIn();
                Handover::problem($answer->text());
                $state->goTo('sign_in');

                return;
            }

            $answer->fieldError('pin') !== null ? $state->error('pin', (string) $answer->fieldError('pin')) : $state->problem($answer->text());

            return;
        }

        $entry->pins()->rememberPin($member, $pin, $answer->get('pin_hash'));
        event(new PinSaved($entry->id, $member->memberKey()));

        $this->enter($state, $member, false);
    }

    /**
     * Whether the PIN screen asks for a new PIN itself (config `sylloo-app-base.screens.pin.create_here`), instead of
     * the Create PIN screen.
     */
    public static function createsPinOnPinScreen(): bool
    {
        return config('sylloo-app-base.screens.pin.create_here', false) === true;
    }

    /**
     * The office refused a call of this screen: tell the app ({@see Hooks::refused()}), and return where the app sends
     * the screen now ({@see Hooks::goOnRefusal()}), or null to show the refusal as usual.
     */
    protected function reportRefusal(ScreenState $state, string $call, OfficeAnswer $answer): ?string
    {
        if (! $answer->isRefused()) {
            return null;
        }

        $refusal = [
            'call' => $call,
            'code' => $answer->code(),
            'status' => $answer->status,
            'message' => is_string($answer->answer['message'] ?? null) ? $answer->answer['message'] : null,
            'answer' => $answer->answer,
        ];
        $context = $this->context($state);

        if ($this->hooks->hasStep('refused')) {
            $this->hooks->runSteps('refused', $refusal, $context);
        }

        return $this->hooks->refusalGo($refusal, $context);
    }

    /**
     * The all-devices-in-use step: the refusal's text (with `:allowed`) and the devices in use.
     */
    protected function showLimit(ScreenState $state, OfficeAnswer $answer): void
    {
        $limit = Refusal::limitFrom($answer->answer);
        $state->step = 'limit';
        $state->data['limit_text'] = $answer->text(['allowed' => $limit['allowed'] ?? '']);
        $state->data['limit_devices'] = $limit['devices'];
        $state->data['limit_allowed'] = $limit['allowed'];
    }

    /**
     * The devices in use, as lines: the name, when it was last used and by whom.
     *
     * @return list<array{text: string, hint: string|null, icon: string}>
     */
    protected function limitLines(ScreenState $state): array
    {
        $devices = is_array($state->data['limit_devices'] ?? null) ? $state->data['limit_devices'] : [];
        $lines = [];

        foreach ($devices as $device) {
            if (! is_array($device) || ! is_string($device['name'] ?? null)) {
                continue;
            }

            $seen = $device['last_seen_at'] ?? null;
            $when = is_string($seen) ? rescue(fn () => Carbon::parse($seen)->locale(app()->getLocale())->isoFormat('D MMM YYYY, HH:mm'), null, false) : null;
            $user = is_string($device['last_user'] ?? null) && $device['last_user'] !== '' ? $device['last_user'] : null;

            $lines[] = [
                'text' => $device['name'],
                'hint' => match (true) {
                    $when !== null && $user !== null => $this->t('limit_device_used_by', ['when' => $when, 'name' => $user]),
                    $when !== null => $this->t('limit_device_used', ['when' => $when]),
                    default => null,
                },
                'icon' => 'bi bi-display',
            ];
        }

        return $lines;
    }

    /**
     * "Devices in use (2 of 2)" above the list, or null without devices.
     */
    protected function limitTitle(ScreenState $state): ?string
    {
        if (! is_array($state->data['limit_devices'] ?? null) || $state->data['limit_devices'] === []) {
            return null;
        }

        $count = count($state->data['limit_devices']);
        $allowed = $state->data['limit_allowed'] ?? null;

        return is_int($allowed)
            ? $this->t('limit_devices_of', ['count' => $count, 'allowed' => $allowed])
            : $this->t('limit_devices', ['count' => $count]);
    }

    /**
     * The business above the screen when the app asks for it for this screen (config `screens.business_header`: true,
     * or a list of screen names such as `['state', 'sign_in', 'pin']`): its logo and name; on the screens of an
     * activated device other than the state screens also this device's name and LIVE / PRACTICE.
     *
     * @return array{name: string|null, logo: string|null, device?: string|null, mode?: string, mode_label?: string}|null
     */
    protected function businessHeader(): ?array
    {
        $wanted = config('sylloo-app-base.screens.business_header', false);

        if ($wanted !== true && ! (is_array($wanted) && in_array($this->name(), $wanted, true))) {
            return null;
        }

        $device = $this->device();
        $header = ['name' => $device->businessName(), 'logo' => $device->logoSource()];

        if ($this->name() === 'state' || ! $device->isActivated()) {
            return $header;
        }

        return [
            ...$header,
            'device' => $device->deviceName(),
            'mode' => $device->isPractice() ? 'practice' : 'live',
            'mode_label' => $device->isPractice() ? $this->t('mode_practice') : $this->t('mode_live'),
        ];
    }

    /**
     * The member a PIN screen is for: handed over by the screen before, else the person who is in, else the only
     * person signed in here.
     */
    protected function memberFor(ScreenState $state): ?DeviceMember
    {
        $members = $this->entry()->members;
        $key = $state->data['member'] ?? Handover::get('member') ?? $this->presence->memberKey($this->entry()->id);

        if (is_string($key)) {
            return $members->member($key);
        }

        $signedIn = array_values(is_array($all = $members->signedInMembers()) ? $all : iterator_to_array($all, false));

        return count($signedIn) === 1 ? $signedIn[0] : null;
    }

    /**
     * "Update now": the app's updater when it has one (desktop), else a line that says where to update (phone store).
     */
    protected function updateNow(ScreenState $state): void
    {
        if (app()->bound(AppUpdater::class) && app()->bound(Notifier::class)) {
            app(AppUpdateService::class)->checkForUpdates(true);
            $state->toast($this->t('update_checking'), 'info');

            return;
        }

        $state->toast($this->t('update_by_hand'), 'info');
    }

    /**
     * The notices every screen shows: the problem the screen before handed over, then this action's problem.
     *
     * @return list<array{text: string, variant: string}>
     */
    protected function notices(ScreenState $state): array
    {
        $notices = [];

        if (is_string($state->data['handed_problem'] ?? null)) {
            $notices[] = ['text' => $state->data['handed_problem'], 'variant' => 'warning'];
        }

        if ($state->problem !== null) {
            $notices[] = ['text' => $state->problem, 'variant' => 'danger'];
        }

        return $notices;
    }

    /**
     * A new state at a step, with the problem the screen before handed over.
     *
     * @param  array<string, mixed>  $data
     */
    protected function begin(string $step, array $data = []): ScreenState
    {
        $handed = Handover::pullProblem();

        return new ScreenState($step, [...$data, ...($handed !== null ? ['handed_problem' => $handed] : [])]);
    }
}
