{{--
    The layout of the shared screens: the practice bar, the business (logo or first letter) with LIVE / PRACTICE and the
    online mark, the three steps (1 Activate · 2 Sign in · 3 PIN) on the screens that have a step, the screen, and a
    footer with About and Language (wire-modals) and the app's version. Built on <x-wire-bootstrap::layout>; the look
    is resources/scss/sylloo-app-base.scss. Hook places: layout.header, layout.footer.
--}}
@php
    $entry = rescue(fn () => app(\Rishadblack\SyllooAppBase\Contracts\DeviceEntries::class)->current(), null, false);
    $device = $entry?->state;
    $activated = $device?->isActivated() ?? false;
    $practice = $activated && $device->isPractice();
    $business = $activated ? $device->businessName() : null;
    $logo = $activated ? $device->logoSource() : null;
    $step = $step ?? null;
    $context = ['screen' => $screen ?? null, 'entry' => $entry?->id, 'business' => $business, 'mode' => $activated ? ($practice ? 'practice' : 'live') : null];
    $icon = fn (string $name) => \Rishadblack\SyllooAppBase\Support\Icons::of($name);
    $steps = [1 => __('sylloo-app-base::device.step_activate'), 2 => __('sylloo-app-base::device.step_sign_in'), 3 => __('sylloo-app-base::device.step_pin')];
@endphp

<x-wire-bootstrap::layout :title="\Rishadblack\SyllooAppBase\Support\AppInfo::name()" @class(['sylloo-app', 'is-practice' => $practice])>
    @if ($practice)
        <div class="sylloo-practice-bar" role="status">
            <i class="{{ $icon('cone-striped') }}" aria-hidden="true"></i> {{ __('sylloo-app-base::device.practice_bar') }}
        </div>
    @endif

    <header class="sylloo-header">
        <div class="sylloo-brand">
            <span class="sylloo-business-mark">
                @if ($logo)
                    <img src="{{ $logo }}" alt="">
                @else
                    {{ mb_strtoupper(mb_substr((string) ($business ?? \Rishadblack\SyllooAppBase\Support\AppInfo::name()), 0, 1)) }}
                @endif
            </span>
            <span class="sylloo-brand-name">{{ $business ?? \Rishadblack\SyllooAppBase\Support\AppInfo::name() }}</span>
            @if ($activated)
                <x-wire-bootstrap::badge :variant="$practice ? 'warning' : 'success'" class="sylloo-mode">
                    {{ $practice ? __('sylloo-app-base::device.mode_practice') : __('sylloo-app-base::device.mode_live') }}
                </x-wire-bootstrap::badge>
            @endif
        </div>
        <div class="sylloo-header-end">
            <x-sylloo-app-base::hook place="layout.header" :context="$context" />
            <span class="sylloo-online" x-data="syllooOnlineMark" x-bind:class="online ? 'is-online' : 'is-offline'">
                <i class="{{ $icon('wifi') }}" x-show="online" aria-hidden="true"></i>
                <i class="{{ $icon('wifi-off') }}" x-show="! online" x-cloak aria-hidden="true"></i>
                <span x-text="online ? @js(__('sylloo-app-base::device.online')) : @js(__('sylloo-app-base::device.offline'))">{{ __('sylloo-app-base::device.online') }}</span>
            </span>
        </div>
    </header>

    <main class="sylloo-main">
        <div class="sylloo-panel">
            @if ($step)
                <ol class="sylloo-steps" aria-label="{{ __('sylloo-app-base::device.steps') }}">
                    @foreach ($steps as $number => $name)
                        <li @class(['is-done' => $number < $step, 'is-current' => $number === $step]) @if ($number === $step) aria-current="step" @endif>
                            <span class="sylloo-step-number">{{ $number }}</span>
                            <span class="sylloo-step-name">{{ $name }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif

            {{ $slot }}
        </div>
    </main>

    <footer class="sylloo-footer">
        <button type="button" class="btn btn-link btn-sm" wire:modal="sylloo-app-base.about"><i class="{{ $icon('info-circle') }}" aria-hidden="true"></i> {{ __('sylloo-app-base::device.about_title') }}</button>
        <button type="button" class="btn btn-link btn-sm" wire:modal="sylloo-app-base.language"><i class="{{ $icon('translate') }}" aria-hidden="true"></i> {{ __('sylloo-app-base::device.language_title') }}</button>
        <x-sylloo-app-base::hook place="layout.footer" :context="$context" />
        <span class="sylloo-version">{{ \Rishadblack\SyllooAppBase\Support\AppInfo::name() }} {{ \Rishadblack\SyllooAppBase\Support\AppInfo::version() }}</span>
    </footer>

    <livewire:wire-modals.host />
</x-wire-bootstrap::layout>
