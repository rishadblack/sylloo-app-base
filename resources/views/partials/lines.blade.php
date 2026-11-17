{{--
    Counted lines (Screens\Lines): the devices in use, what an app added at a place ("14 sales are safe"). $lines, an
    optional $title and the $screen (for the extra classes of the `lines` part).
--}}
<div class="sylloo-lines {{ \Rishadblack\SyllooAppBase\Screens\Look::classes($screen ?? '', 'lines') }}">
    @if (filled($title ?? null))
        <div class="sylloo-lines-title">{{ $title }}</div>
    @endif
    <ul class="list-group">
        @foreach ($lines as $line)
            <li class="list-group-item d-flex align-items-center gap-2 sylloo-line">
                @if ($icon = \Rishadblack\SyllooAppBase\Support\Icons::of($line['icon'] ?? null))
                    <i class="{{ $icon }} text-{{ $line['variant'] ?? 'secondary' }}" aria-hidden="true"></i>
                @endif
                <span class="flex-grow-1">
                    <span class="sylloo-line-text">{{ $line['text'] }}</span>
                    @if (filled($line['hint'] ?? null))
                        <span class="d-block small text-muted sylloo-line-hint">{{ $line['hint'] }}</span>
                    @endif
                </span>
                @if (($line['count'] ?? null) !== null)
                    <x-wire-bootstrap::badge :variant="$line['variant'] ?? 'secondary'" class="sylloo-line-count">{{ $line['count'] }}</x-wire-bootstrap::badge>
                @endif
            </li>
        @endforeach
    </ul>
</div>
