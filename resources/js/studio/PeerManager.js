/**
 * PeerManager — Host-side WebRTC peer connection manager for live studio.
 *
 * Creates one RTCPeerConnection per guest. Uses a Reverb Echo channel
 * for signaling (offer/answer/ICE candidate exchange).
 *
 * When a guest's video arrives, calls the onRemoteStream callback so the
 * host's Alpine component can register it with SourceManager.
 *
 * Signaling protocol (via Echo .whisper()):
 *   Guest → Host: 'webrtc-offer'   {guestId, sdp}
 *   Host → Guest: 'webrtc-answer'  {guestId, sdp}
 *   Both:         'ice-candidate'  {guestId, targetId, candidate}
 *   Guest → Host: 'guest-ready'    {guestId, name}
 *   Guest → Host: 'guest-disconnect' {guestId}
 */

const ICE_SERVERS = [
    { urls: 'stun:stun.l.google.com:19302' },
    { urls: 'stun:stun1.l.google.com:19302' },
];

export default class PeerManager {
    /**
     * @param {Object} echoChannel - Echo presence channel instance
     * @param {string} hostId - Unique host identifier for signaling
     * @param {Array} [iceServers] - Custom ICE servers (optional)
     */
    constructor(echoChannel, hostId, iceServers = ICE_SERVERS) {
        this._channel = echoChannel;
        this._hostId = hostId;
        this._iceServers = iceServers;
        this._connections = new Map();
        this._onRemoteStreamCallback = null;
        this._onGuestReadyCallback = null;
        this._onGuestDisconnectCallback = null;

        this._setupSignaling();
    }

    onRemoteStream(callback) {
        this._onRemoteStreamCallback = callback;
    }

    onGuestReady(callback) {
        this._onGuestReadyCallback = callback;
    }

    onGuestDisconnect(callback) {
        this._onGuestDisconnectCallback = callback;
    }

    closeConnection(guestId) {
        const pc = this._connections.get(guestId);
        if (pc) {
            pc.close();
            this._connections.delete(guestId);
            console.log(`[PeerManager] Connection closed: ${guestId}`);
        }
    }

    closeAll() {
        for (const [guestId, pc] of this._connections) {
            pc.close();
            console.log(`[PeerManager] Connection closed: ${guestId}`);
        }
        this._connections.clear();
    }

    destroy() {
        this.closeAll();
        this._channel = null;
    }

    _setupSignaling() {
        this._channel.listenForWhisper('webrtc-offer', async (data) => {
            console.log(`[PeerManager] Received offer from: ${data.guestId}`);
            await this._handleOffer(data.guestId, data.sdp);
        });

        this._channel.listenForWhisper('ice-candidate', (data) => {
            if (data.targetId !== this._hostId) return;

            const pc = this._connections.get(data.guestId);
            if (pc && data.candidate) {
                pc.addIceCandidate(new RTCIceCandidate(data.candidate))
                    .catch(err => console.warn(`[PeerManager] ICE candidate error:`, err));
            }
        });

        this._channel.listenForWhisper('guest-ready', (data) => {
            console.log(`[PeerManager] Guest ready: ${data.guestId} (${data.name})`);
            if (this._onGuestReadyCallback) {
                this._onGuestReadyCallback({ guestId: data.guestId, name: data.name });
            }
        });

        this._channel.listenForWhisper('guest-disconnect', (data) => {
            console.log(`[PeerManager] Guest disconnecting: ${data.guestId}`);
            this.closeConnection(data.guestId);
            if (this._onGuestDisconnectCallback) {
                this._onGuestDisconnectCallback({ guestId: data.guestId });
            }
        });
    }

    async _handleOffer(guestId, sdp) {
        if (this._connections.has(guestId)) {
            this._connections.get(guestId).close();
        }

        const pc = new RTCPeerConnection({ iceServers: this._iceServers });
        this._connections.set(guestId, pc);

        pc.onicecandidate = (event) => {
            if (event.candidate && this._channel) {
                this._channel.whisper('ice-candidate', {
                    guestId: this._hostId,
                    targetId: guestId,
                    candidate: event.candidate.toJSON(),
                });
            }
        };

        pc.oniceconnectionstatechange = () => {
            console.log(`[PeerManager] ICE state (${guestId}):`, pc.iceConnectionState);
            if (pc.iceConnectionState === 'disconnected' || pc.iceConnectionState === 'failed') {
                this.closeConnection(guestId);
                if (this._onGuestDisconnectCallback) {
                    this._onGuestDisconnectCallback({ guestId });
                }
            }
        };

        pc.ontrack = (event) => {
            console.log(`[PeerManager] Track received from ${guestId}:`, event.track.kind);
            if (event.streams && event.streams[0]) {
                if (this._onRemoteStreamCallback) {
                    this._onRemoteStreamCallback({
                        guestId,
                        stream: event.streams[0],
                    });
                }
            }
        };

        try {
            await pc.setRemoteDescription({ type: 'offer', sdp });

            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);

            this._channel.whisper('webrtc-answer', {
                guestId: guestId,
                sdp: answer.sdp,
            });

            console.log(`[PeerManager] Answer sent to: ${guestId}`);
        } catch (err) {
            console.error(`[PeerManager] Failed to handle offer from ${guestId}:`, err);
            this.closeConnection(guestId);
        }
    }
}
