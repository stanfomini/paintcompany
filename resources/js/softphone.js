/**
 * SoftphoneManager — Standalone Twilio Voice SDK manager.
 *
 * Owns the Twilio Device lifecycle, connection state, and company switching.
 * Communicates with the outside world via events only.
 *
 * Usage:
 *   const phone = new SoftphoneManager();
 *   await phone.init();                          // Register with default company
 *   await phone.init(companyId);                 // Register with specific company
 *   phone.on('ready', () => { ... });
 *   phone.on('incoming', (call) => { ... });
 *   phone.on('connected', (conn) => { ... });
 *   phone.on('disconnected', () => { ... });
 *   phone.on('error', (err) => { ... });
 *   await phone.dial({ To: '+15551234567', contactId: 42 }); // Outbound
 *   phone.accept();                              // Accept incoming
 *   phone.reject();                              // Reject incoming
 *   phone.hangup();                              // End active call
 *   phone.mute(true/false);
 *   phone.sendDtmf('5');
 *   await phone.switchCompany(companyId);        // Switch credentials
 *   phone.destroy();                             // Cleanup
 */
export default class SoftphoneManager {
    constructor() {
        this._device = null;
        this._connection = null;
        this._pendingIncoming = null;
        this._companyId = null;
        this._listeners = {};
        this._state = 'idle'; // idle, connecting, connected, disconnecting
        this._deviceReady = false;
        this._pendingCompanySwitch = null;
    }

    // ──────────────────────────────────────
    // Event Emitter
    // ──────────────────────────────────────

    on(event, fn) {
        if (!this._listeners[event]) this._listeners[event] = [];
        this._listeners[event].push(fn);
        return this;
    }

    off(event, fn) {
        if (!this._listeners[event]) return;
        this._listeners[event] = this._listeners[event].filter(f => f !== fn);
        return this;
    }

    _emit(event, ...args) {
        (this._listeners[event] || []).forEach(fn => {
            try { fn(...args); } catch (e) { console.error(`SoftphoneManager event error [${event}]:`, e); }
        });
    }

    // ──────────────────────────────────────
    // Getters
    // ──────────────────────────────────────

    get state() { return this._state; }
    get isReady() { return this._deviceReady; }
    get companyId() { return this._companyId; }
    get isMuted() { return this._connection ? this._connection.isMuted() : false; }
    get hasActiveCall() { return this._state === 'connected' || this._state === 'connecting'; }
    get hasPendingIncoming() { return this._pendingIncoming !== null; }

    // ──────────────────────────────────────
    // Lifecycle
    // ──────────────────────────────────────

    /**
     * Initialize (or reinitialize) the Twilio Device for a company.
     * Fetches an access token, creates the Device, and registers it.
     */
    async init(companyId = null) {
        // Don't reinit if already on this company and ready
        if (companyId && companyId === this._companyId && this._deviceReady) {
            return;
        }

        // Fetch token
        const tokenUrl = companyId
            ? `/prospector/voice/token?company_id=${companyId}`
            : '/prospector/voice/token';

        let data;
        try {
            const res = await fetch(tokenUrl);
            data = await res.json();
            if (!res.ok || data.error) {
                const errMsg = typeof data.error === 'string' ? data.error : 'Failed to get phone token';
                this._emit('error', errMsg);
                return;
            }
        } catch (err) {
            this._emit('error', 'Network error fetching phone token');
            return;
        }

        // Destroy old device (only if no active call)
        if (this._device) {
            if (this._connection) {
                // Active call — can't switch now, queue it
                this._pendingCompanySwitch = companyId;
                return;
            }
            this._deviceReady = false;
            this._device.destroy();
            this._device = null;
        }

        this._companyId = data.company_id || null;

        // Create new device
        const Device = await window.loadTwilioVoice();
        this._device = new Device(data.token, {
            codecPreferences: ['opus', 'pcmu'],
            edge: 'roaming',
            sounds: {
                incoming: '/sounds/phone-ring.wav',
            },
        });

        this._device.on('registered', () => {
            this._deviceReady = true;
            this._emit('ready', this._companyId);
        });

        this._device.on('error', (err) => {
            const code = err.code ? `(${err.code}) ` : '';
            const message = typeof err.message === 'string' ? err.message : (typeof err === 'string' ? err : 'Phone error');
            this._emit('error', code + message);
        });

        this._device.on('unregistered', () => {
            this._deviceReady = false;
            this._emit('unregistered');
            // Auto re-register after 3 seconds
            setTimeout(() => {
                if (this._device && !this._deviceReady) {
                    this._device.register().catch((err) => {
                        this._emit('error', 'Re-registration failed: ' + (err.message || err));
                    });
                }
            }, 3000);
        });

        this._device.on('tokenWillExpire', async () => {
            const refreshUrl = this._companyId
                ? `/prospector/voice/token?company_id=${this._companyId}`
                : '/prospector/voice/token';

            const delays = [0, 2000, 5000];
            for (let attempt = 0; attempt < delays.length; attempt++) {
                if (attempt > 0) {
                    await new Promise(r => setTimeout(r, delays[attempt]));
                }
                try {
                    const res = await fetch(refreshUrl);
                    const data = await res.json();
                    if (res.ok && data.token) {
                        this._device.updateToken(data.token);
                        return;
                    }
                } catch (err) {
                    // Retry on next iteration
                }
            }
            // All retries failed — try full re-init
            this._emit('error', 'Token refresh failed after retries — reconnecting...');
            this._deviceReady = false;
            this._emit('unregistered');
            try {
                await this.init(this._companyId);
            } catch (err) {
                this._emit('error', 'Reconnection failed: ' + (err.message || err));
            }
        });

        this._device.on('incoming', (call) => {
            this._pendingIncoming = call;
            this._emit('incoming', {
                from: call.parameters.From,
                to: call.parameters.To,
                callSid: call.parameters.CallSid,
            });

            // If caller hangs up before agent answers
            call.on('cancel', () => {
                this._pendingIncoming = null;
                this._emit('incoming-canceled');
            });
        });

        try {
            await this._device.register();
        } catch (err) {
            this._emit('error', 'Device registration failed: ' + (err.message || err));
        }
    }

    /**
     * Switch to a different company. If idle, reinitializes immediately.
     * If on a call, queues the switch for after the call ends.
     */
    async switchCompany(companyId) {
        if (!companyId || companyId === this._companyId) return;

        if (this.hasActiveCall) {
            this._pendingCompanySwitch = companyId;
            return;
        }

        await this.init(companyId);
    }

    /**
     * Destroy the device and clean up everything.
     */
    destroy() {
        this.hangup();
        if (this._device) {
            this._device.unregister();
            this._device.destroy();
            this._device = null;
        }
        this._deviceReady = false;
        this._companyId = null;
        this._pendingCompanySwitch = null;
        this._listeners = {};
    }

    // ──────────────────────────────────────
    // Outbound Calls
    // ──────────────────────────────────────

    /**
     * Initiate an outbound call. Params are sent to the TwiML App webhook.
     * The webhook creates the conference, returns TwiML, and dials the contact.
     *
     * @param {Object} params - { To: '+15551234567', contactId: 42 }
     */
    async dial(params) {
        if (!this._device || !this._deviceReady) {
            const msg = 'Phone not initialized — cannot dial';
            this._emit('error', msg);
            throw new Error(msg);
        }

        if (this._connection) {
            const msg = 'Already on a call';
            this._emit('error', msg);
            throw new Error(msg);
        }

        this._state = 'connecting';
        this._emit('state-change', 'connecting');

        try {
            this._connection = await this._device.connect({ params });
            this._state = 'connected';
            this._emit('state-change', 'connected');
            this._emit('connected', this._connection);

            this._connection.on('disconnect', () => {
                this._onDisconnected();
            });

            this._connection.on('error', (err) => {
                this._emit('error', 'Call error: ' + (err.message || err));
            });
        } catch (err) {
            this._state = 'idle';
            this._emit('state-change', 'idle');
            this._emit('error', 'Failed to connect: ' + (err.message || err));
            throw err;
        }
    }

    // ──────────────────────────────────────
    // Inbound Calls
    // ──────────────────────────────────────

    /**
     * Accept the pending incoming call.
     */
    accept() {
        if (!this._pendingIncoming) return;

        const call = this._pendingIncoming;
        this._pendingIncoming = null;

        call.accept();
        this._connection = call;
        this._state = 'connected';
        this._emit('state-change', 'connected');
        this._emit('connected', this._connection);

        call.on('disconnect', () => {
            this._onDisconnected();
        });

        call.on('error', (err) => {
            this._emit('error', 'Call error: ' + (err.message || err));
        });
    }

    /**
     * Reject the pending incoming call.
     */
    reject() {
        if (!this._pendingIncoming) return;

        this._pendingIncoming.reject();
        this._pendingIncoming = null;
        this._emit('incoming-rejected');
    }

    // ──────────────────────────────────────
    // Call Controls
    // ──────────────────────────────────────

    hangup() {
        if (this._pendingIncoming) {
            this._pendingIncoming.reject();
            this._pendingIncoming = null;
        }
        if (this._connection) {
            this._connection.disconnect();
            // _onDisconnected will fire via the event
        }
    }

    mute(muted) {
        if (this._connection) {
            this._connection.mute(muted);
        }
    }

    sendDtmf(digit) {
        if (this._connection) {
            this._connection.sendDigits(String(digit));
        }
    }

    // ──────────────────────────────────────
    // Internal
    // ──────────────────────────────────────

    _onDisconnected() {
        this._connection = null;
        this._state = 'idle';
        this._emit('state-change', 'idle');
        this._emit('disconnected');

        // Execute pending company switch
        if (this._pendingCompanySwitch) {
            const companyId = this._pendingCompanySwitch;
            this._pendingCompanySwitch = null;
            this.init(companyId);
        }
    }
}
