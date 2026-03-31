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

window.loadFrameCompositor = async () => {
    if (!window.FrameCompositor) {
        const module = await import('./studio/FrameCompositor.js');
        window.FrameCompositor = module.default;
    }
    return window.FrameCompositor;
};

window.loadSourceManager = async () => {
    if (!window.StudioSourceManager) {
        const module = await import('./studio/SourceManager.js');
        window.StudioSourceManager = module.default;
    }
    return window.StudioSourceManager;
};

window.loadPeerManager = async () => {
    if (!window.StudioPeerManager) {
        const module = await import('./studio/PeerManager.js');
        window.StudioPeerManager = module.default;
    }
    return window.StudioPeerManager;
};

window.loadGuestPeerConnection = async () => {
    if (!window.StudioGuestPeerConnection) {
        const module = await import('./studio/GuestPeerConnection.js');
        window.StudioGuestPeerConnection = module.default;
    }
    return window.StudioGuestPeerConnection;
};

window.loadOverlayRenderer = async () => {
    if (!window.StudioOverlayRenderer) {
        const module = await import('./studio/OverlayRenderer.js');
        window.StudioOverlayRenderer = module.default;
    }
    return window.StudioOverlayRenderer;
};

window.loadCaptionEngine = async () => {
    if (!window.StudioCaptionEngine) {
        const module = await import('./studio/CaptionEngine.js');
        window.StudioCaptionEngine = module.default;
    }
    return window.StudioCaptionEngine;
};

window.loadCanvasEditor = async () => {
    if (!window.StudioCanvasEditor) {
        const module = await import('./studio/CanvasEditor.js');
        window.StudioCanvasEditor = module.default;
    }
    return window.StudioCanvasEditor;
};

window.loadTwilioVoice = async () => {
    if (!window.TwilioDevice) {
        const { Device } = await import('@twilio/voice-sdk');
        window.TwilioDevice = Device;
    }
    return window.TwilioDevice;
};

window.Pusher = Pusher;

// Expose Echo constructor for pages that need custom auth (e.g., live event guest viewers)
window.EchoLib = Echo;

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
// Non-cloneable browser objects (MediaStream, AudioContext) stored in closures
// to avoid Livewire/Alpine structuredClone errors
document.addEventListener('alpine:init', () => {
    Alpine.data('cameraPreview', () => {
        let stream = null;
        let audioContext = null;
        let analyser = null;
        let animationFrame = null;

        return {
            hasStream: false,
            audioLevel: 0,
            cameraError: null,

            init() {
                this.startPreview();
            },

            async startPreview() {
                try {
                    stream = await navigator.mediaDevices.getUserMedia({
                        video: { width: { ideal: 1280 }, height: { ideal: 720 } },
                        audio: { echoCancellation: true, noiseSuppression: true }
                    });
                    this.$refs.preview.srcObject = stream;
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
                    audioContext = new AudioContext();
                    const source = audioContext.createMediaStreamSource(stream);
                    analyser = audioContext.createAnalyser();
                    source.connect(analyser);
                    analyser.fftSize = 256;
                    this.monitorAudio();
                } catch (err) {
                    console.warn('Audio meter setup failed:', err);
                }
            },

            monitorAudio() {
                if (!analyser) return;
                const data = new Uint8Array(analyser.frequencyBinCount);
                analyser.getByteFrequencyData(data);
                this.audioLevel = Math.max(...data) / 255;
                animationFrame = requestAnimationFrame(() => this.monitorAudio());
            },

            destroy() {
                if (animationFrame) cancelAnimationFrame(animationFrame);
                if (stream) stream.getTracks().forEach(t => t.stop());
                if (audioContext) audioContext.close();
            }
        };
    });
});

// LiveKit: Host streaming connection for live state
// Room object stored in closure to prevent structuredClone errors
document.addEventListener('alpine:init', () => {
    Alpine.data('livekitHost', () => {
        let room = null;

        return {
            connected: false,
            audioEnabled: true,
            videoEnabled: true,
            viewerCount: 0,
            connectionError: null,
            cameras: [],
            microphones: [],
            activeCameraId: '',
            activeMicId: '',
            showDeviceMenu: false,

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
                    room = new Room({
                        adaptiveStream: true,
                        dynacast: true,
                        videoCaptureDefaults: {
                            resolution: VideoPresets.h720.resolution,
                        },
                    });

                    room.on(RoomEvent.ParticipantConnected, () => this.updateViewerCount());
                    room.on(RoomEvent.ParticipantDisconnected, () => this.updateViewerCount());
                    room.on(RoomEvent.Disconnected, () => {
                        this.connected = false;
                    });

                    await room.connect(url, token);
                    await room.localParticipant.setCameraEnabled(true);
                    await room.localParticipant.setMicrophoneEnabled(true);

                    const videoEl = this.$refs.hostVideo;
                    let videoAttached = false;

                    const attachVideo = (track) => {
                        if (videoAttached) return;
                        videoAttached = true;
                        const el = track.attach();
                        el.autoplay = true;
                        el.muted = true;
                        el.playsInline = true;
                        el.style.width = '100%';
                        el.style.height = '100%';
                        el.style.objectFit = 'cover';
                        el.style.transform = 'scaleX(-1)';
                        videoEl.innerHTML = '';
                        videoEl.appendChild(el);
                        el.play().catch(() => {});
                    };

                    room.localParticipant.on('localTrackPublished', (pub) => {
                        if (pub.track && pub.track.kind === 'video') {
                            attachVideo(pub.track);
                        }
                    });

                    // Also handle track reattach after camera switch
                    room.localParticipant.on('localTrackUnpublished', () => {
                        videoAttached = false;
                    });

                    room.localParticipant.videoTrackPublications.forEach((pub) => {
                        if (pub.track) attachVideo(pub.track);
                    });

                    this.connected = true;
                    this.updateViewerCount();
                    await this.loadDevices();
                } catch (err) {
                    console.error('LiveKit connection error:', err);
                    this.connectionError = 'Failed to connect: ' + err.message;
                }
            },

            async loadDevices() {
                try {
                    const devices = await navigator.mediaDevices.enumerateDevices();
                    this.cameras = devices
                        .filter(d => d.kind === 'videoinput')
                        .map(d => ({ id: d.deviceId, label: d.label || `Camera ${d.deviceId.slice(0, 4)}` }));
                    this.microphones = devices
                        .filter(d => d.kind === 'audioinput')
                        .map(d => ({ id: d.deviceId, label: d.label || `Mic ${d.deviceId.slice(0, 4)}` }));

                    // Set active device IDs from current tracks
                    if (room) {
                        room.localParticipant.videoTrackPublications.forEach(pub => {
                            if (pub.track) {
                                const settings = pub.track.mediaStreamTrack?.getSettings();
                                if (settings?.deviceId) this.activeCameraId = settings.deviceId;
                            }
                        });
                        room.localParticipant.audioTrackPublications.forEach(pub => {
                            if (pub.track) {
                                const settings = pub.track.mediaStreamTrack?.getSettings();
                                if (settings?.deviceId) this.activeMicId = settings.deviceId;
                            }
                        });
                    }
                } catch (err) {
                    console.warn('Failed to enumerate devices:', err);
                }
            },

            async switchCamera(deviceId) {
                if (!room || deviceId === this.activeCameraId) return;
                try {
                    await room.switchActiveDevice('videoinput', deviceId);
                    this.activeCameraId = deviceId;
                    this.showDeviceMenu = false;
                } catch (err) {
                    console.error('Failed to switch camera:', err);
                }
            },

            async switchMicrophone(deviceId) {
                if (!room || deviceId === this.activeMicId) return;
                try {
                    await room.switchActiveDevice('audioinput', deviceId);
                    this.activeMicId = deviceId;
                    this.showDeviceMenu = false;
                } catch (err) {
                    console.error('Failed to switch microphone:', err);
                }
            },

            updateViewerCount() {
                if (room) {
                    this.viewerCount = room.remoteParticipants.size;
                    this.$wire.set('viewerCount', this.viewerCount);
                }
            },

            async toggleAudio() {
                if (!room) return;
                this.audioEnabled = !this.audioEnabled;
                await room.localParticipant.setMicrophoneEnabled(this.audioEnabled);
            },

            async toggleVideo() {
                if (!room) return;
                this.videoEnabled = !this.videoEnabled;
                await room.localParticipant.setCameraEnabled(this.videoEnabled);
            },

            async disconnect() {
                if (room) {
                    await room.disconnect();
                    room = null;
                    this.connected = false;
                }
            },

            destroy() {
                this.disconnect();
            }
        };
    });
});

// LiveKit: Viewer connection for public event page
// Room stored in closure to prevent structuredClone errors
document.addEventListener('alpine:init', () => {
    Alpine.data('livekitViewer', () => {
        let room = null;

        return {
            connected: false,

            init() {
                this.$wire.on('livekit-viewer-connect', ({ token, url }) => {
                    if (token && url) this.connect(token, url);
                });
            },

            async connect(token, url) {
                try {
                    const { Room, RoomEvent } = await window.loadLiveKit();
                    room = new Room();

                    room.on(RoomEvent.TrackSubscribed, (track, pub, participant) => {
                        // Only display host or coproducer tracks — guests publish
                        // their own camera to the room but viewers should only see
                        // the host's composed output (or raw camera).
                        const id = participant.identity || '';
                        const isHost = id.startsWith('host-') || id.startsWith('coproducer-');

                        if (track.kind === 'video') {
                            if (!isHost) return; // Skip guest video tracks

                            const el = track.attach();
                            el.autoplay = true;
                            el.playsInline = true;
                            el.style.width = '100%';
                            el.style.height = '100%';
                            el.style.objectFit = 'cover';
                            if (this.$refs.videoPlayer) {
                                this.$refs.videoPlayer.innerHTML = '';
                                this.$refs.videoPlayer.appendChild(el);
                            }
                        } else if (track.kind === 'audio') {
                            if (!isHost) return; // Skip guest audio for viewers

                            const el = track.attach();
                            el.style.display = 'none';
                            document.body.appendChild(el);
                        }
                    });

                    room.on(RoomEvent.TrackUnsubscribed, (track) => {
                        track.detach().forEach(el => el.remove());
                    });

                    await room.connect(url, token);
                    this.connected = true;
                } catch (error) {
                    console.error('LiveKit viewer connection error:', error);
                }
            },

            destroy() {
                if (room) {
                    room.disconnect();
                    room = null;
                }
            }
        };
    });
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
