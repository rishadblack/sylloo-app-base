@props(['place', 'context' => []])
{{-- What the app added at this place (Screens\Hooks): its pieces, then its counted lines; nothing when it added nothing. --}}
@php
    $hooks = app(\Rishadblack\SyllooAppBase\Screens\Hooks::class);
    $placeLines = $hooks->linesFor([$place], $context);
@endphp
@if ($hooks->has($place) || $placeLines !== [])
    <div class="sylloo-hook" data-place="{{ $place }}">
        {{ $hooks->render($place, $context) }}
        @if ($placeLines !== [])
            @include('sylloo-app-base::partials.lines', ['lines' => $placeLines, 'title' => null, 'screen' => (string) ($context['screen'] ?? '')])
        @endif
    </div>
@endif
