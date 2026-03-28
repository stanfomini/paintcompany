import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import './pwa';
import './push';

// Lazy loaders for heavy dependencies — only fetched when actually needed on the page
window.loadSortable = async () => {
    if (!window.Sortable) {
        const { default: Sortable } = await import('sortablejs');
        window.Sortable = Sortable;
    }
    return window.Sortable;
};

window.loadHtml2canvas = async () => {
    if (!window.html2canvas) {
        const { default: html2canvas } = await import('html2canvas');
        window.html2canvas = html2canvas;
    }
    return window.html2canvas;
};

window.loadLiveKit = async () => {
    if (!window.LiveKitClient) {
        window.LiveKitClient = await import('livekit-client');
    }
    return window.LiveKitClient;
};

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

// Avatar Cropper Alpine.js component — lazy-loads cropperjs
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

        async initCropper() {
            if (this.cropper) {
                this.cropper.destroy();
            }

            // Lazy-load Cropper and its CSS
            const [{ default: Cropper }] = await Promise.all([
                import('cropperjs'),
                import('cropperjs/dist/cropper.css'),
            ]);

            const image = this.$refs.cropImage;
            this.cropper = new Cropper(image, {
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

// LiveKit: Camera preview for pre-live state
document.addEventListener('alpine:init', () => {
    Alpine.data('cameraPreview', () => ({
        stream: null,
        hasStream: false,
        audioLevel: 0,
        audioContext: null,
        analyser: null,
        animationFrame: null,
        cameraError: null,

        init() {
            this.startPreview();
        },

        async startPreview() {
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: { width: { ideal: 1280 }, height: { ideal: 720 } },
                    audio: { echoCancellation: true, noiseSuppression: true }
                });
                this.$refs.preview.srcObject = this.stream;
                this.hasStream = true;
                this.setupAudioMeter();
            } catch (err) {
                console.error('Camera access error:', err);
                this.cameraError = err.name === 'NotAllowedError'
                    ? 'Camera access denied. Please allow camera access in your browser settings.'
                    : 'Could not access camera: ' + err.message;
            }
        },

        setupAudioMeter() {
            try {
                this.audioContext = new AudioContext();
                const source = this.audioContext.createMediaStreamSource(this.stream);
                this.analyser = this.audioContext.createAnalyser();
                source.connect(this.analyser);
                this.analyser.fftSize = 256;
                this.monitorAudio();
            } catch (err) {
                console.warn('Audio meter setup failed:', err);
            }
        },

        monitorAudio() {
            if (!this.analyser) return;
            const data = new Uint8Array(this.analyser.frequencyBinCount);
            this.analyser.getByteFrequencyData(data);
            this.audioLevel = Math.max(...data) / 255;
            this.animationFrame = requestAnimationFrame(() => this.monitorAudio());
        },

        destroy() {
            if (this.animationFrame) cancelAnimationFrame(this.animationFrame);
            if (this.stream) this.stream.getTracks().forEach(t => t.stop());
            if (this.audioContext) this.audioContext.close();
        }
    }));
});

// LiveKit: Host streaming connection for live state
document.addEventListener('alpine:init', () => {
    Alpine.data('livekitHost', () => ({
        room: null,
        connected: false,
        audioEnabled: true,
        videoEnabled: true,
        viewerCount: 0,
        connectionError: null,

        init() {
            this.$wire.on('livekit-connect', ({ token, url }) => {
                this.connect(token, url);
            });

            this.$wire.on('livekit-disconnect', () => {
                this.disconnect();
            });
        },

        async connect(token, url) {
            try {
                this.connectionError = null;
                const { Room, RoomEvent, VideoPresets } = await window.loadLiveKit();
                this.room = new Room({
                    adaptiveStream: true,
                    dynacast: true,
                    videoCaptureDefaults: {
                        resolution: VideoPresets.h720.resolution,
                    },
                });

                this.room.on(RoomEvent.ParticipantConnected, () => this.updateViewerCount());
                this.room.on(RoomEvent.ParticipantDisconnected, () => this.updateViewerCount());
                this.room.on(RoomEvent.Disconnected, () => {
                    this.connected = false;
                });

                await this.room.connect(url, token);
                await this.room.localParticipant.setCameraEnabled(true);
                await this.room.localParticipant.setMicrophoneEnabled(true);

                const videoEl = this.$refs.hostVideo;
                this.room.localParticipant.videoTrackPublications.forEach((pub) => {
                    if (pub.track) {
                        const el = pub.track.attach();
                        el.style.width = '100%';
                        el.style.height = '100%';
                        el.style.objectFit = 'cover';
                        videoEl.innerHTML = '';
                        videoEl.appendChild(el);
                    }
                });

                this.room.localParticipant.on('localTrackPublished', (pub) => {
                    if (pub.track && pub.track.kind === 'video') {
                        const el = pub.track.attach();
                        el.style.width = '100%';
                        el.style.height = '100%';
                        el.style.objectFit = 'cover';
                        videoEl.innerHTML = '';
                        videoEl.appendChild(el);
                    }
                });

                this.connected = true;
                this.updateViewerCount();
            } catch (err) {
                console.error('LiveKit connection error:', err);
                this.connectionError = 'Failed to connect: ' + err.message;
            }
        },

        updateViewerCount() {
            if (this.room) {
                this.viewerCount = this.room.remoteParticipants.size;
                this.$wire.set('viewerCount', this.viewerCount);
            }
        },

        async toggleAudio() {
            if (!this.room) return;
            this.audioEnabled = !this.audioEnabled;
            await this.room.localParticipant.setMicrophoneEnabled(this.audioEnabled);
        },

        async toggleVideo() {
            if (!this.room) return;
            this.videoEnabled = !this.videoEnabled;
            await this.room.localParticipant.setCameraEnabled(this.videoEnabled);
        },

        async disconnect() {
            if (this.room) {
                await this.room.disconnect();
                this.room = null;
                this.connected = false;
            }
        },

        destroy() {
            this.disconnect();
        }
    }));
});

// Offer countdown timer
document.addEventListener('alpine:init', () => {
    Alpine.data('offerCountdown', (expiresAt) => ({
        remaining: 0,
        interval: null,
        expired: false,

        init() {
            this.tick();
            this.interval = setInterval(() => this.tick(), 1000);
        },

        tick() {
            this.remaining = Math.max(0, Math.floor((new Date(expiresAt) - Date.now()) / 1000));
            if (this.remaining <= 0) {
                clearInterval(this.interval);
                this.expired = true;
            }
        },

        get display() {
            const m = Math.floor(this.remaining / 60);
            const s = this.remaining % 60;
            return `${m}:${s.toString().padStart(2, '0')}`;
        },

        get isUrgent() {
            return this.remaining > 0 && this.remaining <= 60;
        },

        destroy() {
            if (this.interval) clearInterval(this.interval);
        }
    }));
});
