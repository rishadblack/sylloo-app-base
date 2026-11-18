{{-- The thin bar while the internet is off and a business page is open (Window\ConnectionProblems): the page stays. --}}
@props(['offline' => false])

@if ($offline)
    <div {{ $attributes->merge(['class' => 'sylloo-offline-bar px-2 py-1 small bg-warning-subtle border-bottom']) }} role="status">
        <i class="bi bi-wifi-off me-1" aria-hidden="true"></i>{{ \Rishadblack\SyllooAppBase\Support\Texts::get('window_offline_bar') }}
    </div>
@endif
