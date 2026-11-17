<?php

namespace Rishadblack\SyllooAppBase\Device;

use Closure;

/**
 * Which state screens exist (plan §4.4 "Extra states"). The shared ones are here from the start; an app adds its own
 * with a test that says when it applies, checked after the shared states (DeviceState::screen()):
 *
 *     app(StateRegistry::class)->register(
 *         new StateDefinition('almost_ready', 'pos.almost_ready_title', 'pos.almost_ready_text', 'pos.almost_ready_todo'),
 *         fn (DeviceState $device) => ! $device->get('outlet_ready'),
 *     );
 *
 * Bound as a singleton; register in a service provider's boot().
 */
class StateRegistry
{
    /** @var array<string, StateDefinition> */
    protected array $definitions = [];

    /** @var array<string, Closure(DeviceState): bool> */
    protected array $tests = [];

    public function __construct()
    {
        $t = fn (string $key) => 'sylloo-app-base::device.'.$key;

        $this->define(new StateDefinition(DeviceState::RELEASING, $t('releasing_title'), $t('releasing_text'), null, 'bi bi-box-arrow-right', 'warning', [], 5));
        $this->define(new StateDefinition(DeviceState::LOCKED, $t('locked_title'), $t('locked_text'), $t('locked_todo'), 'bi bi-lock', 'danger'));
        $this->define(new StateDefinition(DeviceState::BUSINESS_SUSPENDED, $t('suspended_title'), $t('suspended_text'), $t('suspended_todo'), 'bi bi-pause-circle', 'warning'));
        $this->define(new StateDefinition(DeviceState::PLAN_ENDED, $t('plan_ended_title'), $t('plan_ended_text'), $t('plan_ended_todo'), 'bi bi-calendar-x', 'danger', ['renew_plan', 'check_again']));
        $this->define(new StateDefinition(DeviceState::UPDATE_NEEDED, $t('update_title'), $t('update_text'), $t('update_todo'), 'bi bi-cloud-arrow-down', 'info', ['update_now', 'check_again']));
        $this->define(new StateDefinition(DeviceState::EMPLOYEE_APP_OFF, $t('employee_app_off_title'), $t('employee_app_off_text'), $t('employee_app_off_todo'), 'bi bi-slash-circle', 'warning'));
        $this->define(new StateDefinition(DeviceState::UNREACHABLE, $t('unreachable_title'), $t('unreachable_text'), $t('unreachable_todo'), 'bi bi-wifi-off', 'secondary'));
    }

    /**
     * Add (or replace) a state screen. With a test it is shown when the test says so, after the shared states.
     *
     * @param  (Closure(DeviceState): bool)|null  $appliesWhen
     */
    public function register(StateDefinition $definition, ?Closure $appliesWhen = null): static
    {
        $this->define($definition);

        if ($appliesWhen !== null) {
            $this->tests[$definition->name] = $appliesWhen;
        }

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    public function get(string $name): ?StateDefinition
    {
        return $this->definitions[$name] ?? null;
    }

    /**
     * @return array<string, StateDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * The first app state that applies to the device, or null.
     */
    public function resolve(DeviceState $device): ?string
    {
        foreach ($this->tests as $name => $applies) {
            if (rescue(fn () => $applies($device), false, false) === true) {
                return $name;
            }
        }

        return null;
    }

    protected function define(StateDefinition $definition): void
    {
        $this->definitions[$definition->name] = $definition;
    }
}
