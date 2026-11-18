{{--
    The app's own page when the business page can not be shown (Window\ConnectionProblems): whose problem it is, that
    the app tries again by itself, and "Try again" (the Livewire method in `action`).
--}}
@props(['status', 'message' => null, 'action' => 'tryAgain'])

<div {{ $attributes->merge(['class' => 'sylloo-window-problem text-center']) }} style="max-width: 32rem">
    <x-wire-bootstrap::alert variant="warning">{{ $message }}</x-wire-bootstrap::alert>
    @if (\Rishadblack\SyllooAppBase\Window\ConnectionProblems::triesAgain($status))
        <p class="small text-secondary">{{ \Rishadblack\SyllooAppBase\Support\Texts::get('window_tries_again') }}</p>
    @endif
    <x-wire-bootstrap::button wire:click="{{ $action }}" variant="primary">{{ \Rishadblack\SyllooAppBase\Support\Texts::get('try_again') }}</x-wire-bootstrap::button>
</div>
