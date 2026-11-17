<?php

namespace Rishadblack\SyllooAppBase\Screens;

/**
 * One field a screen asks for. Types: `code` (the grouped 4×4 activation code), `pin` (a masked 4-digit PIN box),
 * `pin_pad` (the PIN pad: dots and digit keys), `tiles` (people or businesses to tap: options `key => [label, photo,
 * hint]`), `select` (options `value => label`), `text`. A face draws each type its own way.
 */
final class Field
{
    /**
     * @param  array<string|int, mixed>  $options
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?string $label = null,
        public readonly ?string $hint = null,
        public readonly array $options = [],
        public readonly ?string $action = null,
    ) {}

    /**
     * @return array{name: string, type: string, label: string|null, hint: string|null, options: array<string|int, mixed>, action: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->label,
            'hint' => $this->hint,
            'options' => $this->options,
            'action' => $this->action,
        ];
    }
}
