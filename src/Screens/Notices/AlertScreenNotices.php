<?php

namespace Rishadblack\SyllooAppBase\Screens\Notices;

use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;
use Rishadblack\SyllooAppBase\Contracts\ScreenNotices;

/**
 * Toasts and questions as livewire-alert (SweetAlert2): the default (config `screens.notices` = `alert`).
 */
final class AlertScreenNotices implements ScreenNotices
{
    public function toast(Component $page, string $text, string $variant): void
    {
        $alert = (new LivewireAlert($page))->title($text)->toast()->position('top-end');

        match ($variant) {
            'danger', 'error' => $alert->error(),
            'warning' => $alert->warning(),
            'info' => $alert->info(),
            default => $alert->success(),
        };

        $alert->show();
    }

    public function ask(Component $page, array $question): void
    {
        (new LivewireAlert($page))
            ->title((string) ($question['title'] ?? ''))
            ->text(EventScreenNotices::message($question))
            ->question()
            ->withConfirmButton((string) ($question['confirm'] ?? 'OK'))
            ->withDenyButton((string) ($question['cancel'] ?? ''))
            ->timer(null)
            ->onConfirm('answerQuestion', ['yes' => true])
            ->onDeny('answerQuestion', ['yes' => false])
            ->onDismiss('answerQuestion', ['yes' => false])
            ->show();
    }
}
