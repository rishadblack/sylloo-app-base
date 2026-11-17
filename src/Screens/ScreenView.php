<?php

namespace Rishadblack\SyllooAppBase\Screens;

use Rishadblack\SyllooAppBase\Support\Icons;

/**
 * What a screen shows now, in plain values a face draws: the title and lines, the step (1 Activate · 2 Sign in · 3 PIN,
 * or none), the fields and buttons, notices, an optional card (the confirm card of Activate: business, LIVE/PRACTICE,
 * STAFF/EMPLOYEE, device name), rows (About), how often the face asks again (`poll` seconds, action `poll`), and the
 * hook places it offers, and counted lines (the devices in use; what an app adds at a place: "14 sales are safe"). The
 * web face is Blade; a native face draws the same view (icons by name: {@see Icons}).
 */
final class ScreenView
{
    /**
     * @param  list<Field>  $fields
     * @param  list<Button>  $buttons
     * @param  list<array{text: string, variant: string}>  $notices
     * @param  array<string, string|null>|null  $card
     * @param  array<string, string|null>  $rows
     * @param  list<string>  $places
     * @param  list<array{text: string, count?: int|null, hint?: string|null, variant?: string, icon?: string|null}>  $lines
     * @param  array{name: string|null, logo: string|null}|null  $header  the business above the screen (logo and name)
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $text = null,
        public readonly ?int $step = null,
        public readonly array $fields = [],
        public readonly array $buttons = [],
        public readonly array $notices = [],
        public readonly ?array $card = null,
        public readonly array $rows = [],
        public readonly ?int $poll = null,
        public readonly array $places = [],
        public readonly ?string $icon = null,
        public readonly string $variant = 'primary',
        public readonly ?string $todo = null,
        public readonly array $lines = [],
        public readonly ?string $linesTitle = null,
        public readonly ?array $header = null,
    ) {}

    /**
     * The actions the person may run on this view: its buttons and the fields that run one (tiles, PIN pad), plus
     * `poll` when the view polls.
     *
     * @return list<string>
     */
    public function actions(): array
    {
        $actions = array_map(fn (Button $button) => $button->action, $this->buttons);

        foreach ($this->fields as $field) {
            if ($field->action !== null) {
                $actions[] = $field->action;
            }
        }

        if ($this->poll !== null) {
            $actions[] = 'poll';
        }

        return array_values(array_unique($actions));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'text' => $this->text,
            'step' => $this->step,
            'fields' => array_map(fn (Field $field) => $field->toArray(), $this->fields),
            'buttons' => array_map(fn (Button $button) => $button->toArray(), $this->buttons),
            'notices' => $this->notices,
            'card' => $this->card,
            'rows' => $this->rows,
            'poll' => $this->poll,
            'places' => $this->places,
            'icon' => $this->icon,
            'variant' => $this->variant,
            'todo' => $this->todo,
            'lines' => Lines::clean($this->lines),
            'lines_title' => $this->linesTitle,
            'header' => $this->header,
        ];
    }
}
