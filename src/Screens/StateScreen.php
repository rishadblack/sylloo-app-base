<?php

namespace Rishadblack\SyllooAppBase\Screens;

use Illuminate\Support\Carbon;
use Rishadblack\SyllooAppBase\Contracts\DeviceEntries;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Device\DeviceStateCheck;
use Rishadblack\SyllooAppBase\Device\StateDefinition;
use Rishadblack\SyllooAppBase\Device\StateRegistry;
use Rishadblack\SyllooAppBase\Pin\Presence;
use Rishadblack\SyllooAppBase\Release\DeviceRelease;
use Rishadblack\SyllooAppBase\Support\Texts;

/**
 * The state screens (plan §4.2 "Device state"): releasing, locked from the office, business paused, plan ended,
 * update needed, employee app off, office not reached, and every state an app registers ({@see StateRegistry}). Each
 * says what happened and what to do, with its buttons: check again, renew plan (the portal in the browser), update now,
 * sign out. Releasing runs the release frame every few seconds until the device is clean, then goes to Activate.
 *
 * The app may choose the sentences of a state ({@see Hooks::sentences()}), run its own checks on "Check again" and the
 * poll (step `state.check`), show the business (logo and name) above the screen (config `screens.business_header`), and
 * show "Can't reach Sylloo" as a screen instead of a short message when a check gets no answer (config
 * `screens.state.unreachable` = `screen`). Dates (`:date`) are written with config `screens.date_format`.
 *
 * An app may also start the release at once when Releasing opens (config `screens.state.release_on_open`), say "Nothing
 * changed yet" after a check that changed nothing (config `screens.state.still_blocked`), and give a state the button
 * `try_again` (on Releasing: the next release step; elsewhere: Check again). "Renew plan" without any address says where
 * to renew.
 *
 * Step: the state's name. Hook places: `state.top`, `state.<name>`, `state.bottom`.
 */
class StateScreen extends Screen
{
    public function __construct(
        DeviceEntries $entries,
        Hooks $hooks,
        Presence $presence,
        protected StateRegistry $states,
    ) {
        parent::__construct($entries, $hooks, $presence);
    }

    public function name(): string
    {
        return 'state';
    }

    public function start(array $input = []): ScreenState
    {
        $name = is_string($input['state'] ?? null) && $this->states->has($input['state']) ? $input['state'] : $this->device()->screen();

        if ($name === DeviceState::NOT_ACTIVATED) {
            return (new ScreenState('activate'))->goTo('activate');
        }

        if ($name === null || ! $this->states->has($name)) {
            return (new ScreenState('none'))->goTo('home');
        }

        $state = $this->begin($name);

        // An app that cleans a released device at once when the screen opens (config
        // `screens.state.release_on_open`): the first release step runs now, not after the first poll.
        if ($name === DeviceState::RELEASING && config('sylloo-app-base.screens.state.release_on_open', false) === true) {
            $this->releaseStep($state);
        }

        return $state;
    }

    public function view(ScreenState $state): ScreenView
    {
        $definition = $this->states->get($state->step) ?? $this->states->get(DeviceState::UNREACHABLE);
        assert($definition instanceof StateDefinition);
        $replace = $this->replacements();
        $chosen = $this->hooks->sentencesFor($definition->name, [...$this->context($state), ...$this->stateContext($replace)]);
        $todo = array_key_exists('todo', $chosen) ? $chosen['todo'] : ($definition->todo !== null ? (string) __($definition->todo, $replace) : null);

        return new ScreenView(
            title: $chosen['title'] ?? (string) __($definition->title, $replace),
            text: $chosen['text'] ?? (string) __($definition->text, $replace),
            buttons: array_values(array_filter(array_map(fn (string $action) => $this->button($action), $definition->buttons))),
            notices: $this->notices($state),
            poll: $definition->pollSeconds,
            places: ['state.top', 'state.'.$definition->name, 'state.bottom'],
            icon: $definition->icon,
            variant: $definition->variant,
            todo: $todo,
            header: $this->businessHeader(),
        );
    }

    protected function doCheckAgain(ScreenState $state): void
    {
        $entry = $this->entry();
        $before = $entry->state->screen();
        $answer = (new DeviceStateCheck($entry->state, fn () => $entry->office()->deviceApi()))->askState();
        $this->hooks->runSteps('state.check', $this->context($state));

        if ($answer === null) {
            if (self::unreachableAsScreen() && $this->states->has(DeviceState::UNREACHABLE)) {
                $state->step = DeviceState::UNREACHABLE;

                return;
            }

            $state->toast($this->t('unreachable'), 'warning');
        }

        $this->follow($state);

        // The office answered and nothing changed: say so (config `screens.state.still_blocked`).
        if ($answer !== null && $state->go === null && $state->step === $before && config('sylloo-app-base.screens.state.still_blocked', false) === true) {
            $state->toast($this->t('still_blocked'), 'info');
        }
    }

    /**
     * "Try again": on Releasing the next release step, on any other state the same as "Check again".
     */
    protected function doTryAgain(ScreenState $state): void
    {
        if ($state->step === DeviceState::RELEASING) {
            $this->releaseStep($state);

            return;
        }

        $this->doCheckAgain($state);
    }

    /**
     * Releasing: one step of the release every few seconds (send what waits, then remove everything).
     */
    protected function doPoll(ScreenState $state): void
    {
        if ($state->step !== DeviceState::RELEASING) {
            $this->hooks->runSteps('state.check', $this->context($state));
            $this->follow($state);

            return;
        }

        $this->releaseStep($state);
    }

    /**
     * One step of the release frame; Activate once the device is clean.
     */
    protected function releaseStep(ScreenState $state): void
    {
        $result = $this->entry()->releaseFrame()->run();

        if ($result === DeviceRelease::REFUSED) {
            $state->problem($this->t('releasing_refused'));
        }

        if (in_array($result, [DeviceRelease::CLEANED, DeviceRelease::NONE], true)) {
            $this->presence->leave();
            $state->goTo('activate');
        }
    }

    protected function doRenewPlan(ScreenState $state): void
    {
        $url = $this->device()->portalUrl() ?? config('sylloo-app-base.app.portal_url');

        // No address from the office and none in the app's config: say where to renew instead.
        if (! is_string($url) || $url === '') {
            $state->toast($this->t('renew_no_link'), 'info');

            return;
        }

        $state->open = $url;
    }

    protected function doUpdateNow(ScreenState $state): void
    {
        $this->updateNow($state);
    }

    protected function doSignOut(ScreenState $state): void
    {
        $this->presence->leave();
        $state->goTo('sign_in');
    }

    /**
     * After a check: the state the device is in now, or on to work.
     */
    protected function follow(ScreenState $state): void
    {
        $now = $this->device()->screen();

        if ($now === DeviceState::NOT_ACTIVATED) {
            $state->goTo('activate');
        } elseif ($now === null || ! $this->states->has($now)) {
            $state->goTo('home');
        } else {
            $state->step = $now;
        }
    }

    protected function button(string $action): ?Button
    {
        return match ($action) {
            'check_again' => new Button('check_again', $this->t('check_again'), 'primary', 'bi bi-arrow-repeat'),
            'renew_plan' => new Button('renew_plan', $this->t('renew_plan'), 'primary', 'bi bi-box-arrow-up-right'),
            'update_now' => new Button('update_now', $this->t('update_now'), 'primary', 'bi bi-cloud-arrow-down'),
            'try_again' => new Button('try_again', $this->t('try_again'), 'primary', 'bi bi-arrow-repeat'),
            'sign_out' => new Button('sign_out', $this->t('sign_out'), 'link'),
            default => null,
        };
    }

    /**
     * Whether a check without an answer shows the "Can't reach Sylloo" screen (config `screens.state.unreachable` =
     * `screen`) instead of a short message (`toast`, the default).
     */
    public static function unreachableAsScreen(): bool
    {
        return config('sylloo-app-base.screens.state.unreachable') === 'screen';
    }

    /**
     * A date in the app's way: config `screens.date_format` (PHP date letters, default `d M Y`), in the app's language.
     * A callable in that key formats it itself; an empty or unreadable date stays as it is.
     */
    public static function formatDate(?string $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        $format = config('sylloo-app-base.screens.date_format', 'd M Y');

        if (is_callable($format) && ! is_string($format)) {
            return (string) $format($date);
        }

        return (string) rescue(fn () => Carbon::parse($date)->locale(app()->getLocale())->translatedFormat(is_string($format) && $format !== '' ? $format : 'd M Y'), $date, false);
    }

    /**
     * What a sentence chooser gets besides the screen's context.
     *
     * @param  array<string, string>  $replace
     * @return array<string, mixed>
     */
    protected function stateContext(array $replace): array
    {
        $device = $this->device();

        return [
            'plan' => $device->plan(),
            'date' => $replace['date'] !== '' ? $replace['date'] : null,
            'device_state' => $device,
            'replace' => $replace,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function replacements(): array
    {
        $device = $this->device();
        $plan = $device->plan();

        return [
            ...Texts::parameters(),
            'business' => $device->businessName() ?? $this->t('this_business'),
            'date' => self::formatDate($plan['valid_until'] ?? $plan['ends_on'] ?? null),
            'version' => (string) $device->get('min_version'),
        ];
    }
}
