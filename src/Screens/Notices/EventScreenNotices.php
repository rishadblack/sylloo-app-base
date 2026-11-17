<?php

namespace Rishadblack\SyllooAppBase\Screens\Notices;

use Livewire\Component;
use Rishadblack\SyllooAppBase\Contracts\ScreenNotices;

/**
 * Toasts and questions as browser events, for an app that draws its own notices (config `screens.notices` = `events`;
 * the till's `app-notices`):
 *
 * - `notify`: `{type: success|info|warning|error, title, message: null}`;
 * - `confirm-dialog`: `{componentId, title, message, detail, lines, method: 'answerQuestion', params: [{yes: true}],
 *   confirmLabel, cancelLabel, danger, cancelMethod: 'answerQuestion', cancelParams: [{yes: false}], icon}`. The
 *   dialog calls `method(...params)` on the component for "yes", `cancelMethod(...cancelParams)` for "no".
 *   `message` holds the text, the detail and the lines as plain text; `detail` and `lines` are there for a richer
 *   dialog.
 */
final class EventScreenNotices implements ScreenNotices
{
    public function toast(Component $page, string $text, string $variant): void
    {
        $page->dispatch('notify', type: $variant === 'danger' ? 'error' : $variant, title: $text, message: null);
    }

    public function ask(Component $page, array $question): void
    {
        $page->dispatch(
            'confirm-dialog',
            componentId: $page->getId(),
            title: (string) ($question['title'] ?? ''),
            message: self::message($question),
            detail: $question['detail'] ?? null,
            lines: $question['lines'] ?? [],
            method: 'answerQuestion',
            params: [['yes' => true]],
            confirmLabel: (string) ($question['confirm'] ?? 'OK'),
            cancelLabel: (string) ($question['cancel'] ?? ''),
            danger: (bool) ($question['danger'] ?? false),
            cancelMethod: 'answerQuestion',
            cancelParams: [['yes' => false]],
            icon: ($question['danger'] ?? false) ? 'alert-triangle' : 'help-circle',
        );
    }

    /**
     * A question's text, detail and counted lines as plain text.
     *
     * @param  array<string, mixed>  $question
     */
    public static function message(array $question): string
    {
        $lines = array_map(
            fn ($line) => is_array($line) ? (($line['count'] ?? null) !== null ? $line['count'].' · ' : '').($line['text'] ?? '') : (string) $line,
            is_array($question['lines'] ?? null) ? $question['lines'] : [],
        );

        return implode("\n", array_filter([(string) ($question['text'] ?? ''), (string) ($question['detail'] ?? ''), ...$lines], fn ($part) => $part !== ''));
    }
}
