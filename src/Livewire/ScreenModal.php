<?php

namespace Rishadblack\SyllooAppBase\Livewire;

use Illuminate\Contracts\View\View;
use Rishadblack\SyllooAppBase\Screens\Look;
use Rishadblack\SyllooAppBase\Screens\ScreenState;
use Rishadblack\WireModals\Attributes\ModalAction;
use Rishadblack\WireModals\Traits\WithModals;

/**
 * A shared screen in a wire-modals modal (About and Language from the layout's footer): the same logic and drawing as
 * the full page, inside `<x-wire-modals::modal>`. Open it with `wire:modal="sylloo-app-base.about"`.
 */
abstract class ScreenModal extends ScreenPage
{
    use WithModals;

    public const MODAL = 'SyllooScreenModal';

    /**
     * @param  array{id?: int|string|null, data?: array<string, mixed>}  $data
     */
    #[ModalAction(default: true)]
    public function openScreenModal(array $data = []): void
    {
        $this->modalOpen(self::MODAL);
    }

    public function render(): View
    {
        $screen = $this->screen();
        $state = ScreenState::fromArray($this->state);
        $this->resetErrorBag();

        foreach ($state->errors as $field => $text) {
            $this->addError('fields.'.$field, $text);
        }

        return app('view')->make('sylloo-app-base::livewire.screen-modal', [
            'modal' => self::MODAL,
            'screen' => $screen->name(),
            'view' => $screen->view($state),
            'body' => Look::bodyView($screen->name()),
            'question' => Look::questions() === 'inline' ? $state->question : null,
            'context' => $screen->context($state),
        ]);
    }

    protected function keep(ScreenState $state): void
    {
        if ($state->go === 'back') {
            $state->go = null;
            parent::keep($state);
            $this->modalClose(self::MODAL);
            $this->js('window.location.reload()');

            return;
        }

        parent::keep($state);
    }
}
