<?php

namespace Rishadblack\SyllooAppBase\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Rishadblack\SyllooAppBase\Contracts\BrowserOpener;
use Rishadblack\SyllooAppBase\Contracts\ScreenNotices;
use Rishadblack\SyllooAppBase\Screens\Look;
use Rishadblack\SyllooAppBase\Screens\Screen;
use Rishadblack\SyllooAppBase\Screens\ScreenRoutes;
use Rishadblack\SyllooAppBase\Screens\ScreenState;

/**
 * The web face of a shared screen (Livewire 4 full page + wire-bootstrap tags). It holds the screen's state (locked:
 * the browser can not change it) and the typed fields, sends each action to the screen's logic, and does what the
 * logic asks: go to a screen, open the browser (the app's BrowserOpener, else a new browser tab), show a toast
 * and ask a question as a dialog (config `sylloo-app-base.screens.questions` = `alert`) through {@see ScreenNotices}
 * (livewire-alert, or browser events: config `screens.notices`); questions are inline by default. Every
 * screen draws with one Blade view (`sylloo-app-base::livewire.screen`) in the app's layout (`screens.layout`), with the
 * app's icon set and extra classes ({@see Look}); publish the views to change more.
 */
abstract class ScreenPage extends Component
{
    /** @var array<string, mixed> */
    #[Locked]
    public array $state = [];

    /** @var array<string, mixed> */
    public array $fields = [];

    /**
     * @return class-string<Screen>
     */
    abstract protected function screenClass(): string;

    public function mount(): void
    {
        $screen = $this->screen();
        $state = $screen->start($this->input());

        foreach ($screen->view($state)->fields as $field) {
            $value = $state->data[$field->name] ?? '';
            $this->fields[$field->name] = is_scalar($value) ? (string) $value : '';
        }

        $this->keep($state);
    }

    /**
     * Run an action of the screen (a button, a tile, the PIN pad); `$value` is the tile's key.
     */
    public function run(string $action, ?string $value = null): void
    {
        $screen = $this->screen();
        $state = $screen->act(ScreenState::fromArray($this->state), $action, $this->fields, $value);

        foreach ($screen->view($state)->fields as $field) {
            if (in_array($field->type, ['pin', 'pin_pad'], true)) {
                $this->fields[$field->name] = '';
            }
        }

        $this->keep($state);
    }

    /**
     * The answer of a question asked as a livewire-alert (its confirm, deny or dismiss).
     *
     * @param  array<string, mixed>  $data
     */
    public function answerQuestion(array $data = []): void
    {
        $this->run(($data['yes'] ?? false) === true ? 'answer_yes' : 'answer_no');
    }

    public function render(): View
    {
        $screen = $this->screen();
        $state = ScreenState::fromArray($this->state);
        $this->resetErrorBag();

        foreach ($state->errors as $field => $text) {
            $this->addError('fields.'.$field, $text);
        }

        $view = $this->screenView($screen, $state);

        return $view instanceof \Illuminate\View\View
            ? $view->layout(Look::layout(), ['screen' => $screen->name(), 'step' => $screen->view($state)->step])
            : $view;
    }

    protected function screenView(Screen $screen, ScreenState $state): View
    {
        return app('view')->make('sylloo-app-base::livewire.screen', [
            'screen' => $screen->name(),
            'view' => $screen->view($state),
            'question' => Look::questions() === 'inline' ? $state->question : null,
            'context' => $screen->context($state),
            'body' => Look::bodyView($screen->name()),
        ]);
    }

    protected function screen(): Screen
    {
        return app($this->screenClass());
    }

    /**
     * What the page was opened with (`?code=` of an activation link, `?state=`).
     *
     * @return array<string, mixed>
     */
    protected function input(): array
    {
        return array_filter((array) request()->query(), 'is_string');
    }

    /**
     * Keep the state and do what it asks of the face.
     */
    protected function keep(ScreenState $state): void
    {
        $asked = ($this->state['question'] ?? null) !== null;
        $this->state = $state->toArray();

        if ($state->open !== null) {
            app()->bound(BrowserOpener::class)
                ? app(BrowserOpener::class)->open($state->open)
                : $this->js('window.open('.json_encode($state->open, JSON_UNESCAPED_SLASHES).', "_blank")');
        }

        if ($state->toast !== null) {
            app(ScreenNotices::class)->toast($this, $state->toast['text'], $state->toast['variant']);
        }

        if ($state->question !== null && ! $asked && Look::questions() === 'alert') {
            app(ScreenNotices::class)->ask($this, $state->question);
        }

        if ($state->go !== null) {
            $this->redirect(ScreenRoutes::url($state->go));
        }
    }
}
