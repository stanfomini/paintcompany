/**
 * LivePlayer — Lightweight LiveKit subscriber for viewer pages.
 *
 * Connects to a LiveKit room as a subscribe-only participant,
 * attaches the host's canvas track to a <video> element.
 */
export default class LivePlayer {
    constructor(videoElement, options = {}) {
        this._videoEl = videoElement;
        this._room = null;
        this._connected = false;
        this._onStatusChange = options.onStatusChange ?? (() => {});
    }

    async connect(url, token) {
        const { Room, RoomEvent } = await window.loadLiveKit();

        this._room = new Room({
            adaptiveStream: true,
            dynacast: true,
        });

        this._room.on(RoomEvent.TrackSubscribed, (track, pub, participant) => {
            if (track.kind === 'video' && participant.identity.startsWith('host')) {
                track.attach(this._videoEl);
                this._connected = true;
                this._onStatusChange('playing');
            }
            if (track.kind === 'audio') {
                const audioEl = track.attach();
                audioEl.id = 'live-player-audio';
                document.body.appendChild(audioEl);
            }
        });

        this._room.on(RoomEvent.TrackUnsubscribed, (track) => {
            track.detach().forEach(el => el.remove());
        });

        this._room.on(RoomEvent.Disconnected, () => {
            this._connected = false;
            this._onStatusChange('disconnected');
        });

        this._room.on(RoomEvent.ParticipantConnected, () => this._onStatusChange('connected'));

        await this._room.connect(url, token);
        this._onStatusChange('connected');

        // Check for existing tracks (host already publishing)
        this._room.remoteParticipants.forEach((p) => {
            if (p.identity.startsWith('host')) {
                p.trackPublications.forEach((pub) => {
                    if (pub.track && pub.track.kind === 'video') {
                        pub.track.attach(this._videoEl);
                        this._connected = true;
                        this._onStatusChange('playing');
                    }
                    if (pub.track && pub.track.kind === 'audio') {
                        const audioEl = pub.track.attach();
                        audioEl.id = 'live-player-audio';
                        document.body.appendChild(audioEl);
                    }
                });
            }
        });
    }

    setVolume(volume) {
        const audioEl = document.getElementById('live-player-audio');
        if (audioEl) audioEl.volume = Math.max(0, Math.min(1, volume));
    }

    disconnect() {
        if (this._room) {
            this._room.disconnect();
            this._room = null;
        }
        this._connected = false;
    }

    get isConnected() {
        return this._connected;
    }
}
