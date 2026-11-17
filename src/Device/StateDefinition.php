<?php

namespace Rishadblack\SyllooAppBase\Device;

/**
 * One state screen (plan §4.2 "Device state", §4.4 "Extra states"): its texts (translation keys; `:business`,
 * `:date` and `:app` are filled in), its icon and colour, and the buttons it offers. The shared states are made by
 * {@see StateRegistry}; an app registers its own (the till: Terminal almost ready; the mobile app: Waiting for HR).
 *
 * Buttons: `check_again` (ask the office), `renew_plan` (open the portal), `update_now` (the updater), `sign_out`
 * (forget the person and go to Sign in), or an app action registered with the hooks.
 */
final class StateDefinition
{
    /**
     * @param  list<string>  $buttons
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $text,
        public readonly ?string $todo = null,
        public readonly string $icon = 'bi bi-info-circle',
        public readonly string $variant = 'warning',
        public readonly array $buttons = ['check_again'],
        public readonly ?int $pollSeconds = null,
    ) {}
}
