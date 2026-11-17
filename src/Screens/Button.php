<?php

namespace Rishadblack\SyllooAppBase\Screens;

/**
 * One button of a screen: the action it runs (only actions a screen offers in its current view may run), its label,
 * its look (`primary`, `link`, `danger`, `outline-secondary`, …) and an icon class (Bootstrap Icons).
 */
final class Button
{
    public function __construct(
        public readonly string $action,
        public readonly string $label,
        public readonly string $variant = 'primary',
        public readonly ?string $icon = null,
    ) {}

    /**
     * @return array{action: string, label: string, variant: string, icon: string|null}
     */
    public function toArray(): array
    {
        return ['action' => $this->action, 'label' => $this->label, 'variant' => $this->variant, 'icon' => $this->icon];
    }
}
