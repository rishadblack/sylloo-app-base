<div>
    <x-wire-modals::modal :id="$modal" :title="$view->title">
        @include($body ?? 'sylloo-app-base::partials.screen-body')
    </x-wire-modals::modal>
</div>
