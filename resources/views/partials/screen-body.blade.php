{{--
    One shared screen, drawn from its ScreenView ($view), the open question ($question) and the hook context ($context).
    Used by the full page (livewire/screen) and the modal (livewire/screen-modal). Hook places ending in ".top" are drawn
    first, ".bottom" last, every other place after the fields. Icons are drawn in the app's set (Support\Icons), and each
    part takes the app's extra classes (Screens\Look, config sylloo-app-base.screens.classes).
--}}
@php
    $topPlaces = array_values(array_filter($view->places, fn ($place) => str_ends_with($place, '.top')));
    $bottomPlaces = array_values(array_filter($view->places, fn ($place) => str_ends_with($place, '.bottom')));
    $middlePlaces = array_values(array_diff($view->places, $topPlaces, $bottomPlaces));
    $look = fn (string $part) => \Rishadblack\SyllooAppBase\Screens\Look::classes($screen, $part);
    $icon = fn (?string $name) => \Rishadblack\SyllooAppBase\Support\Icons::of($name);
@endphp

<div class="sylloo-screen sylloo-screen-{{ str_replace('_', '-', $screen) }} {{ $look('screen') }}">
    @foreach ($topPlaces as $place)
        <x-sylloo-app-base::hook :place="$place" :context="$context" />
    @endforeach

    @if ($view->header)
        <div class="sylloo-business-header d-flex align-items-center gap-2 {{ $look('header') }}">
            <div class="sylloo-business-mark">
                @if (filled($view->header['logo'] ?? null))
                    <img src="{{ $view->header['logo'] }}" alt="">
                @else
                    {{ mb_strtoupper(mb_substr((string) ($view->header['name'] ?? '?'), 0, 1)) }}
                @endif
            </div>
            <div class="sylloo-business-name">
                {{ $view->header['name'] ?? '' }}
                @if (filled($view->header['device'] ?? null) || filled($view->header['mode_label'] ?? null))
                    <div class="sylloo-business-device d-flex flex-wrap align-items-center gap-2 small text-muted">
                        @if (filled($view->header['device'] ?? null))
                            <span><i class="{{ $icon('display') }}" aria-hidden="true"></i> {{ $view->header['device'] }}</span>
                        @endif
                        @if (filled($view->header['mode_label'] ?? null))
                            <x-wire-bootstrap::badge :variant="($view->header['mode'] ?? null) === 'practice' ? 'warning' : 'success'">{{ $view->header['mode_label'] }}</x-wire-bootstrap::badge>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if ($view->icon)
        <div class="sylloo-screen-icon text-{{ $view->variant }} {{ $look('icon') }}" aria-hidden="true"><i class="{{ $icon($view->icon) }}"></i></div>
    @endif

    <h1 class="sylloo-screen-title {{ $look('title') }}">{{ $view->title }}</h1>

    @if (filled($view->text))
        <p class="sylloo-screen-text {{ $look('text') }}">{{ $view->text }}</p>
    @endif

    @if (filled($view->todo))
        <p class="sylloo-screen-todo {{ $look('todo') }}"><i class="{{ $icon('arrow-right-circle') }}" aria-hidden="true"></i> {{ $view->todo }}</p>
    @endif

    @foreach ($view->notices as $notice)
        <x-wire-bootstrap::alert :variant="$notice['variant']" :class="$look('notice')">{{ $notice['text'] }}</x-wire-bootstrap::alert>
    @endforeach

    @if ($question)
        <x-wire-bootstrap::card @class(['sylloo-question', $look('question'), 'border-danger' => $question['danger'] ?? false]) role="alertdialog">
            <h2 class="h6">{{ $question['title'] }}</h2>
            <p class="mb-3">{{ $question['text'] }}</p>
            @if (filled($question['detail'] ?? null))
                <p class="mb-3 fw-semibold sylloo-question-detail">{{ $question['detail'] }}</p>
            @endif
            @if (($question['lines'] ?? []) !== [])
                <div class="mb-3">
                    @include('sylloo-app-base::partials.lines', ['lines' => $question['lines'], 'title' => null])
                </div>
            @endif
            <div class="d-flex gap-2 justify-content-end">
                <x-wire-bootstrap::button variant="link" wire:click="run('answer_no')">{{ $question['cancel'] }}</x-wire-bootstrap::button>
                <x-wire-bootstrap::button :variant="($question['danger'] ?? false) ? 'danger' : 'primary'" wire:click="run('answer_yes')">{{ $question['confirm'] }}</x-wire-bootstrap::button>
            </div>
        </x-wire-bootstrap::card>
    @endif

    @if ($view->card)
        <x-wire-bootstrap::card :class="trim('sylloo-confirm-card '.$look('card'))">
            <div class="d-flex align-items-center gap-3">
                <div class="sylloo-business-mark">
                    @if (filled($view->card['logo'] ?? null))
                        <img src="{{ $view->card['logo'] }}" alt="">
                    @else
                        {{ mb_strtoupper(mb_substr((string) ($view->card['business'] ?? '?'), 0, 1)) }}
                    @endif
                </div>
                <div class="flex-grow-1">
                    <div class="sylloo-confirm-business">{{ $view->card['business'] ?? '' }}</div>
                    <div class="d-flex flex-wrap gap-2 mt-1">
                        <x-wire-bootstrap::badge :variant="($view->card['mode'] ?? null) === 'practice' ? 'warning' : 'success'">{{ $view->card['mode_label'] ?? '' }}</x-wire-bootstrap::badge>
                        @if (filled($view->card['kind_label'] ?? null))
                            <x-wire-bootstrap::badge variant="secondary">{{ $view->card['kind_label'] }}</x-wire-bootstrap::badge>
                        @endif
                    </div>
                    @if (filled($view->card['device_name'] ?? null))
                        <div class="text-muted small mt-1"><i class="{{ $icon('display') }}" aria-hidden="true"></i> {{ $view->card['device_name'] }}</div>
                    @endif
                </div>
            </div>
        </x-wire-bootstrap::card>
    @endif

    @if ($view->lines !== [])
        @include('sylloo-app-base::partials.lines', ['lines' => \Rishadblack\SyllooAppBase\Screens\Lines::clean($view->lines), 'title' => $view->linesTitle])
    @endif

    @if ($view->rows !== [])
        <dl class="sylloo-rows {{ $look('rows') }}">
            @foreach ($view->rows as $label => $value)
                @if (filled($value))
                    <dt>{{ $label }}</dt>
                    <dd>{{ $value }}</dd>
                @endif
            @endforeach
        </dl>
    @endif

    @if (! $question)
        @php($submitAction = collect($view->buttons)->first(fn ($button) => $button->variant === 'primary')?->action)
        <form @if ($submitAction) wire:submit="run('{{ $submitAction }}')" @else x-on:submit.prevent @endif class="sylloo-fields {{ $look('fields') }}">
            @foreach ($view->fields as $field)
                @switch ($field->type)
                    @case('code')
                        <x-wire-bootstrap::input.code wire:model="fields.{{ $field->name }}" :label="$field->label" :help="$field->hint" :groups="4" :length="4" separator="-" formatted wrapper-class="mb-3 sylloo-code-field" />
                        @break
                    @case('pin')
                        <x-wire-bootstrap::input.password wire:model="fields.{{ $field->name }}" :label="$field->label" :help="$field->hint" inputmode="numeric" maxlength="6" autocomplete="off" class="sylloo-pin-input" />
                        @break
                    @case('pin_pad')
                        <div class="sylloo-pin">
                            @if (filled($field->options['photo'] ?? null))
                                <img src="{{ $field->options['photo'] }}" class="sylloo-pin-photo" alt="">
                            @endif
                            <x-wire-bootstrap::pin-pad wire:model="fields.{{ $field->name }}" :label="$field->label" :min="4" :max="6" global-keys x-on:wb-pin-submit="$wire.run('{{ $field->action }}')" />
                        </div>
                        @break
                    @case('tiles')
                        <div class="sylloo-tiles" role="list" aria-label="{{ $field->label }}">
                            @foreach ($field->options as $key => $tile)
                                <button type="button" role="listitem" wire:key="tile-{{ $key }}" wire:click="run('{{ $field->action }}', @js((string) $key))" @class(['sylloo-tile', 'is-current' => $tile['current'] ?? false])>
                                    <span class="sylloo-tile-photo">
                                        @if (filled($tile['photo'] ?? null))
                                            <img src="{{ $tile['photo'] }}" alt="">
                                        @else
                                            {{ $tile['initials'] ?? mb_strtoupper(mb_substr((string) ($tile['label'] ?? '?'), 0, 1)) }}
                                        @endif
                                    </span>
                                    <span class="sylloo-tile-label">{{ $tile['label'] ?? '' }}</span>
                                    @if (filled($tile['hint'] ?? null))
                                        <span class="sylloo-tile-hint">{{ $tile['hint'] }}</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                        @break
                    @case('select')
                        <x-wire-bootstrap::input.select wire:model="fields.{{ $field->name }}" :label="$field->label" :help="$field->hint" :options="$field->options" placeholder="" />
                        @break
                    @default
                        <x-wire-bootstrap::input.text wire:model="fields.{{ $field->name }}" :label="$field->label" :help="$field->hint" />
                @endswitch
            @endforeach

            @foreach ($middlePlaces as $place)
                <x-sylloo-app-base::hook :place="$place" :context="$context" />
            @endforeach

            @if ($view->buttons !== [])
                <div class="sylloo-buttons {{ $look('buttons') }}">
                    @foreach ($view->buttons as $button)
                        @php($buttonClass = \Rishadblack\SyllooAppBase\Screens\Look::buttonClasses($screen, $button->action))
                        @php($loadingText = \Rishadblack\SyllooAppBase\Screens\Look::loadingText($button->action))
                        @if ($loadingText !== null)
                            <button type="button" class="btn btn-{{ $button->variant }} {{ $buttonClass }}" wire:click="run('{{ $button->action }}')" wire:key="button-{{ $button->action }}" wire:loading.attr="disabled" wire:target="run('{{ $button->action }}')">
                                <span wire:loading.remove wire:target="run('{{ $button->action }}')">@if ($button->icon)<i class="{{ $icon($button->icon) }}" aria-hidden="true"></i> @endif{{ $button->label }}</span>
                                <span wire:loading wire:target="run('{{ $button->action }}')"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <span class="sylloo-loading-text">{{ $loadingText }}</span></span>
                            </button>
                        @else
                            <x-wire-bootstrap::button :variant="$button->variant" :icon="$icon($button->icon)" :class="$buttonClass" wire:click="run('{{ $button->action }}')" wire:key="button-{{ $button->action }}">{{ $button->label }}</x-wire-bootstrap::button>
                        @endif
                    @endforeach
                </div>
            @endif
        </form>
    @endif

    @if ($view->poll !== null && ! $question)
        <div wire:poll.{{ max(2, $view->poll) }}s="run('poll')" aria-hidden="true"></div>
    @endif

    @foreach ($bottomPlaces as $place)
        <x-sylloo-app-base::hook :place="$place" :context="$context" />
    @endforeach
</div>
