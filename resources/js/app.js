import Sortable from 'sortablejs';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Sortable = Sortable;

window.Pusher = Pusher;

// Only initialize Echo if user is authenticated and Reverb is configured
if (document.querySelector('meta[name="user-id"]')) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
