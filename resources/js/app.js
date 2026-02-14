import Sortable from 'sortablejs';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import html2canvas from 'html2canvas';
import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';

window.Sortable = Sortable;
window.html2canvas = html2canvas;
window.Cropper = Cropper;

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

// Avatar Cropper Alpine.js component
document.addEventListener('alpine:init', () => {
    Alpine.data('avatarCropper', () => ({
        cropper: null,
        showModal: false,
        imageSrc: null,
        uploading: false,

        selectFile(event) {
            const file = event.target.files[0];
            if (!file) return;

            // Validate file type client-side (size doesn't matter - we crop before upload)
            const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
            if (!validTypes.includes(file.type)) {
                alert('Please select a JPG, PNG, or WebP image.');
                event.target.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = (e) => {
                this.imageSrc = e.target.result;
                this.showModal = true;
                this.$nextTick(() => this.initCropper());
            };
            reader.readAsDataURL(file);

            // Reset input so same file can be re-selected
            event.target.value = '';
        },

        initCropper() {
            if (this.cropper) {
                this.cropper.destroy();
            }

            const image = this.$refs.cropImage;
            this.cropper = new window.Cropper(image, {
                aspectRatio: 1,
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 1,
                cropBoxResizable: true,
                cropBoxMovable: true,
                guides: true,
                center: true,
                background: false,
            });
        },

        async cropAndUpload() {
            if (!this.cropper) return;

            this.uploading = true;

            const canvas = this.cropper.getCroppedCanvas({
                width: 512,
                height: 512,
                imageSmoothingEnabled: true,
                imageSmoothingQuality: 'high',
            });

            canvas.toBlob((blob) => {
                const file = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });

                this.$wire.upload('avatar', file, () => {
                    // Success
                    this.uploading = false;
                    this.close();
                }, () => {
                    // Error
                    this.uploading = false;
                    alert('Upload failed. Please try again.');
                }, (event) => {
                    // Progress - could add a progress bar
                });
            }, 'image/jpeg', 0.9);
        },

        close() {
            this.showModal = false;
            if (this.cropper) {
                this.cropper.destroy();
                this.cropper = null;
            }
            this.imageSrc = null;
        },
    }));
});

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
