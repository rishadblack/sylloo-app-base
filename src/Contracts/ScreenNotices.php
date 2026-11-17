<?php

namespace Rishadblack\SyllooAppBase\Contracts;

use Livewire\Component;
use Rishadblack\SyllooAppBase\Screens\Notices\AlertScreenNotices;
use Rishadblack\SyllooAppBase\Screens\Notices\EventScreenNotices;

/**
 * How a shared screen's web face shows a short message (a toast) and asks a question as a dialog (config
 * `sylloo-app-base.screens.questions` = `alert`). Config `sylloo-app-base.screens.notices` picks the base's own:
 * `alert` (livewire-alert, the default: {@see AlertScreenNotices}) or `events` (the browser events `notify` and
 * `confirm-dialog`, for an app that draws its own notices: {@see EventScreenNotices}). An app may bind its own.
 *
 * A question's answer comes back through the page's `answerQuestion(['yes' => bool])`.
 */
interface ScreenNotices
{
    /**
     * @param  string  $variant  `success`, `info`, `warning` or `danger`
     */
    public function toast(Component $page, string $text, string $variant): void;

    /**
     * @param  array{title?: string, text?: string, confirm?: string, cancel?: string, danger?: bool, detail?: string|null, lines?: list<array<string, mixed>>}  $question
     */
    public function ask(Component $page, array $question): void;
}
