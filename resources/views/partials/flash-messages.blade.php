<div
    x-data
    x-init="
        @if(session('success'))
            $dispatch('toast', { message: {{ Js::from(session('success')) }}, type: 'success' });
        @endif
        @if(session('warning'))
            $dispatch('toast', { message: {{ Js::from(session('warning')) }}, type: 'warning' });
        @endif
        @if(session('error'))
            $dispatch('toast', { message: {{ Js::from(session('error')) }}, type: 'error' });
        @endif
        @if(session('info'))
            $dispatch('toast', { message: {{ Js::from(session('info')) }}, type: 'info' });
        @endif
    "
    class="hidden"
></div>
