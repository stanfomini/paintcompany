import Sortable from 'sortablejs';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import html2canvas from 'html2canvas';
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';

window.Sortable = Sortable;
window.html2canvas = html2canvas;

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

// FullCalendar Alpine.js component for planner calendar view
document.addEventListener('alpine:init', () => {
    Alpine.data('calendarView', () => ({
        calendar: null,
        init() {
            this.$nextTick(() => {
                this.calendar = new Calendar(this.$refs.calendar, {
                    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
                    initialView: 'timeGridWeek',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,timeGridDay',
                    },
                    events: '/api/activities/calendar',
                    eventClick: (info) => {
                        if (info.event.url) {
                            info.jsEvent.preventDefault();
                            if (window.Livewire) {
                                Livewire.navigate(info.event.url);
                            } else {
                                window.location.href = info.event.url;
                            }
                        }
                    },
                    eventDidMount: (info) => {
                        const label = info.event.extendedProps.subject_label;
                        if (label) {
                            info.el.setAttribute('title', label);
                        }
                    },
                    height: 'auto',
                    nowIndicator: true,
                    slotMinTime: '06:00:00',
                    slotMaxTime: '21:00:00',
                });
                this.calendar.render();
            });
        },
        destroy() {
            if (this.calendar) {
                this.calendar.destroy();
                this.calendar = null;
            }
        },
    }));
});
