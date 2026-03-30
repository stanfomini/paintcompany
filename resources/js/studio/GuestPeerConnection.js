/**
 * GuestPeerConnection — Guest-side WebRTC connection to the host.
 *
 * Sends the guest's camera/mic to the host via a direct peer connection.
 * Uses a Reverb Echo channel for signaling.
 *
 * Usage:
 *   const gpc = new GuestPeerConnection(echoChannel, guestId);
 *   await gpc.connect(localStream);
 *   // ... later
 *   gpc.disconnect();
 */

const ICE_SERVERS = [
    { urls: 'stun:stun.l.google.com:19302' },
    { urls: 'stun:stun1.l.google.com:19302' },
];

export default class GuestPeerConnection {
    /**
     * @param {Object} echoChannel - Echo presence channel instance
     * @param {string} guestId - Unique guest identifier for signaling
     * @param {Array} [iceServers]
     */
    constructor(echoChannel, guestId, iceServers = ICE_SERVERS) {
        this._channel = echoChannel;
        this._guestId = guestId;
        this._iceServers = iceServers;
        this._pc = null;
        this._connected = false;
        this._onConnectedCallback = null;
        this._onDisconnectedCallback = null;
    }

    onConnected(callback) {
        this._onConnectedCallback = callback;
    }

    onDisconnected(callback) {
        this._onDisconnectedCallback = callback;
    }

    async connect(localStream) {
        this._pc = new RTCPeerConnection({ iceServers: this._iceServers });

        for (const track of localStream.getTracks()) {
            this._pc.addTrack(track, localStream);
        }

        this._pc.onicecandidate = (event) => {
            if (event.candidate && this._channel) {
                this._channel.whisper('ice-candidate', {
                    guestId: this._guestId,
                    targetId: 'host',
                    candidate: event.candidate.toJSON(),
                });
            }
        };

        this._pc.oniceconnectionstatechange = () => {
            const state = this._pc.iceConnectionState;
            console.log(`[GuestPeer] ICE state: ${state}`);

            if (state === 'connected' || state === 'completed') {
                if (!this._connected) {
                    this._connected = true;
                    if (this._onConnectedCallback) this._onConnectedCallback();
                }
            }

            if (state === 'disconnected' || state === 'failed' || state === 'closed') {
                this._connected = false;
                if (this._onDisconnectedCallback) this._onDisconnectedCallback();
            }
        };

        this._channel.listenForWhisper('webrtc-answer', async (data) => {
            if (data.guestId !== this._guestId) return;

            console.log('[GuestPeer] Received answer from host');
            try {
                await this._pc.setRemoteDescription({ type: 'answer', sdp: data.sdp });
            } catch (err) {
                console.error('[GuestPeer] Failed to set remote description:', err);
            }
        });

        this._channel.listenForWhisper('ice-candidate', (data) => {
            if (data.targetId !== this._guestId) return;

            if (this._pc && data.candidate) {
                this._pc.addIceCandidate(new RTCIceCandidate(data.candidate))
                    .catch(err => console.warn('[GuestPeer] ICE candidate error:', err));
            }
        });

        try {
            const offer = await this._pc.createOffer();
            await this._pc.setLocalDescription(offer);

            this._channel.whisper('webrtc-offer', {
                guestId: this._guestId,
                sdp: offer.sdp,
            });

            console.log('[GuestPeer] Offer sent to host');
        } catch (err) {
            console.error('[GuestPeer] Failed to create offer:', err);
            throw err;
        }
    }

    disconnect() {
        if (this._channel) {
            this._channel.whisper('guest-disconnect', {
                guestId: this._guestId,
            });
        }

        if (this._pc) {
            this._pc.close();
            this._pc = null;
        }

        this._connected = false;
        console.log('[GuestPeer] Disconnected');
    }

    get isConnected() {
        return this._connected;
    }
}
