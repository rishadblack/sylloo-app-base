<?php

namespace Rishadblack\SyllooAppBase\Screens;

use Closure;

/**
 * A question an app asks inside a shared screen before the screen goes on (plan §4.4 "Hooks"): the till's "Reset
 * terminal for the new code?" before Activate asks the office. "Yes" runs `$onConfirm` with the screen's context and
 * the screen goes on; "No" runs `$onCancel` and nothing is sent. Either may return a short toast (a text, or
 * `['text' => …, 'variant' => …]`: the till's "Nothing changed" after "No"). A question may show a detail line and counted lines
 * ("14 sales are safe on this terminal"); a face shows it inline or as a livewire-alert (config
 * `sylloo-app-base.screens.questions`).
 */
final class Question
{
    /**
     * @param  (Closure(array<string, mixed>): mixed)|null  $onConfirm  may return a toast (Hooks::answer())
     * @param  (Closure(array<string, mixed>): mixed)|null  $onCancel  may return a toast (Hooks::answer())
     * @param  list<array{text: string, count?: int|null, hint?: string|null, variant?: string, icon?: string|null}>  $lines
     */
    public function __construct(
        public readonly string $title,
        public readonly string $text,
        public readonly string $confirmLabel,
        public readonly string $cancelLabel,
        public readonly bool $danger = false,
        public readonly ?Closure $onConfirm = null,
        public readonly ?Closure $onCancel = null,
        public readonly ?string $detail = null,
        public readonly array $lines = [],
    ) {}

    /**
     * What a face shows (no closures).
     *
     * @return array{title: string, text: string, confirm: string, cancel: string, danger: bool, detail: string|null, lines: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'text' => $this->text,
            'confirm' => $this->confirmLabel,
            'cancel' => $this->cancelLabel,
            'danger' => $this->danger,
            'detail' => $this->detail,
            'lines' => Lines::clean($this->lines),
        ];
    }
}
