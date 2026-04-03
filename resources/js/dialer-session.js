/**
 * DialerSession — Auto-dial state machine and session orchestration.
 *
 * Works alongside SoftphoneManager to manage the persistent conference
 * auto-dial loop. Coordinates between the Twilio Voice SDK (via SoftphoneManager)
 * and the Livewire dialer component.
 *
 * States: idle → starting → ready → dialing → on_call → disposition → dialing (loop)
 *
 * Usage:
 *   const session = new DialerSession(softphone, $wire);
 *   await session.start(campaignId);  // Connects to conference, starts auto-dial
 *   session.pause();                   // Pause auto-dial, stay in conference
 *   session.resume();                  // Resume auto-dial
 *   session.stop();                    // End session, disconnect
 *   session.on('state-change', (state) => { ... });
 */
export default class DialerSession {
    constructor(softphone, getWire) {
        this._softphone = softphone;
        // Store a getter function instead of the $wire proxy directly
        // Livewire's $wire proxy can't be stored as a plain property
        this._getWire = typeof getWire === 'function' ? getWire : () => getWire;
        this._state = 'idle'; // idle, starting, ready, dialing, on_call, disposition
        this._autoDial = false;
        this._listeners = {};
        this._dialTimer = null;
        this._currentContactId = null;
        this._sessionId = null;
        this._conferenceName = null;
    }

    get _wire() {
        return this._getWire();
    }

    // ── Event Emitter ──

    on(event, fn) {
        if (!this._listeners[event]) this._listeners[event] = [];
        this._listeners[event].push(fn);
        return this;
    }

    _emit(event, ...args) {
        (this._listeners[event] || []).forEach(fn => {
            try { fn(...args); } catch (e) { console.error(`DialerSession [${event}]:`, e); }
        });
    }

    // ── Getters ──

    get state() { return this._state; }
    get isActive() { return this._state !== 'idle'; }
    get isAutoDialing() { return this._autoDial && this._state === 'ready'; }
    get sessionId() { return this._sessionId; }
    get conferenceName() { return this._conferenceName; }

    // ── Session Lifecycle ──

    /**
     * Start a dialer session: create session on server, connect to conference.
     */
    async start(campaignId) {
        if (this._state !== 'idle') return;

        this._setState('starting');

        try {
            // Create session on server
            const result = await this._wire.startDialerSession(campaignId);
            if (result.error) {
                this._emit('error', result.error);
                this._setState('idle');
                return;
            }

            this._sessionId = result.sessionId;
            this._conferenceName = result.conferenceName;

            // Connect agent to the persistent conference via SoftphoneManager
            await this._softphone.dial({ conference: this._conferenceName });

            this._setState('ready');
            this._emit('session-started', { sessionId: this._sessionId, conferenceName: this._conferenceName });

        } catch (err) {
            this._emit('error', 'Failed to start session: ' + (err.message || err));
            this._setState('idle');
        }
    }

    /**
     * Start auto-dialing through the queue.
     */
    startAutoDial() {
        if (this._state !== 'ready') return;
        this._autoDial = true;
        this._emit('auto-dial-started');
        this._dialNext();
    }

    /**
     * Pause auto-dial but keep conference alive.
     */
    pause() {
        this._autoDial = false;
        if (this._dialTimer) {
            clearTimeout(this._dialTimer);
            this._dialTimer = null;
        }
        this._wire.pauseDialerSession();
        this._emit('auto-dial-paused');
    }

    /**
     * Resume auto-dialing.
     */
    resume() {
        if (this._state !== 'ready' && this._state !== 'disposition') return;
        this._autoDial = true;
        this._wire.resumeDialerSession();
        this._emit('auto-dial-resumed');
        if (this._state === 'ready') {
            this._dialNext();
        }
    }

    /**
     * Stop session completely, disconnect from conference.
     */
    async stop() {
        this._autoDial = false;
        if (this._dialTimer) {
            clearTimeout(this._dialTimer);
            this._dialTimer = null;
        }

        if (this._sessionId) {
            await this._wire.endDialerSession();
        }

        this._softphone.hangup();
        this._sessionId = null;
        this._conferenceName = null;
        this._currentContactId = null;
        this._setState('idle');
        this._emit('session-ended');
    }

    // ── Call Flow (called by Alpine event handlers) ──

    /**
     * Called when the contact answers (from Echo event).
     */
    onCallConnected(contactId) {
        this._currentContactId = contactId;
        this._setState('on_call');
    }

    /**
     * Called when the call ends (from Echo event).
     */
    onCallEnded() {
        this._currentContactId = null;
        this._setState('disposition');
    }

    /**
     * Called after agent selects a disposition.
     */
    onDispositioned() {
        this._setState('ready');
        if (this._autoDial) {
            // Brief pause before next dial (1.5 seconds for UI transition)
            this._dialTimer = setTimeout(() => this._dialNext(), 1500);
        }
    }

    /**
     * Called when no answer / machine detected (auto-advance).
     */
    onNoAnswer() {
        this._currentContactId = null;
        this._setState('ready');
        if (this._autoDial) {
            this._dialTimer = setTimeout(() => this._dialNext(), 500);
        }
    }

    // ── Internal ──

    async _dialNext() {
        if (this._state !== 'ready' || !this._autoDial) return;

        this._setState('dialing');

        try {
            const result = await this._wire.dialNextFromSession();

            if (result.error) {
                if (result.error === 'queue_empty') {
                    this._emit('queue-empty');
                    this._autoDial = false;
                    this._setState('ready');
                } else {
                    this._emit('error', result.error);
                    this._setState('ready');
                }
                return;
            }

            // Server dialed the contact into the conference.
            // We'll get a DialerCallConnected or DialerCallEnded event via Echo.
            this._currentContactId = result.contactId;

        } catch (err) {
            this._emit('error', 'Dial failed: ' + (err.message || err));
            this._setState('ready');
        }
    }

    _setState(state) {
        const prev = this._state;
        this._state = state;
        if (prev !== state) {
            this._emit('state-change', state, prev);
        }
    }

    destroy() {
        if (this._dialTimer) clearTimeout(this._dialTimer);
        this._listeners = {};
    }
}
