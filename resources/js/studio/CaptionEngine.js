/**
 * CaptionEngine — Real-time speech-to-text with active speaker detection.
 *
 * Uses Web Speech API (SpeechRecognition) for transcription and
 * AudioContext + AnalyserNode for active speaker detection.
 */

export default class CaptionEngine {
    constructor() {
        this._audioContext = null;
        this._analysers = new Map();     // sourceKey → {analyser, track, name}
        this._recognition = null;
        this._activeSpeaker = null;
        this._levelInterval = null;
        this._onCaptionCallback = null;
        this._running = false;
    }

    /**
     * @param {Object} audioSources - {host: {track: MediaStreamTrack, name: string}, guests: Map<id, {track, name}>}
     */
    start(audioSources) {
        // Check SpeechRecognition support
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
            console.warn('[CaptionEngine] SpeechRecognition not supported in this browser');
            return;
        }

        this._audioContext = new AudioContext();
        this._running = true;

        // Set up analysers for each audio source
        if (audioSources.host?.track) {
            this._addAnalyser('host', audioSources.host.track, audioSources.host.name || 'Host');
        }
        if (audioSources.guests) {
            for (const [id, guest] of audioSources.guests) {
                if (guest.track) {
                    this._addAnalyser(id, guest.track, guest.name || id);
                }
            }
        }

        // Start level monitoring (every 100ms)
        this._levelInterval = setInterval(() => this._detectActiveSpeaker(), 100);

        // Start speech recognition
        this._recognition = new SpeechRecognition();
        this._recognition.continuous = true;
        this._recognition.interimResults = true;
        this._recognition.lang = 'en-US';

        this._recognition.onresult = (event) => {
            if (!this._onCaptionCallback) return;

            for (let i = event.resultIndex; i < event.results.length; i++) {
                const result = event.results[i];
                const speaker = this._activeSpeaker
                    ? (this._analysers.get(this._activeSpeaker)?.name ?? this._activeSpeaker)
                    : 'Speaker';

                this._onCaptionCallback({
                    speaker,
                    text: result[0].transcript.trim(),
                    isFinal: result.isFinal,
                });
            }
        };

        this._recognition.onerror = (event) => {
            if (event.error === 'no-speech') return; // Normal, just silence
            console.warn('[CaptionEngine] Recognition error:', event.error);
        };

        this._recognition.onend = () => {
            // Restart if still running (recognition stops after silence)
            if (this._running) {
                try { this._recognition.start(); } catch (e) { /* already started */ }
            }
        };

        try {
            this._recognition.start();
            console.log('[CaptionEngine] Started');
        } catch (err) {
            console.error('[CaptionEngine] Failed to start:', err);
        }
    }

    stop() {
        this._running = false;

        if (this._levelInterval) {
            clearInterval(this._levelInterval);
            this._levelInterval = null;
        }

        if (this._recognition) {
            try { this._recognition.stop(); } catch (e) { /* ok */ }
            this._recognition = null;
        }

        for (const [key, entry] of this._analysers) {
            // Analyser nodes are garbage collected when AudioContext closes
        }
        this._analysers.clear();

        if (this._audioContext) {
            this._audioContext.close();
            this._audioContext = null;
        }

        console.log('[CaptionEngine] Stopped');
    }

    /**
     * @param {Function} callback - ({speaker: string, text: string, isFinal: boolean}) => void
     */
    onCaption(callback) {
        this._onCaptionCallback = callback;
    }

    getActiveSpeaker() {
        return this._activeSpeaker;
    }

    _addAnalyser(key, track, name) {
        const source = this._audioContext.createMediaStreamSource(new MediaStream([track]));
        const analyser = this._audioContext.createAnalyser();
        analyser.fftSize = 256;
        source.connect(analyser);
        this._analysers.set(key, { analyser, track, name });
    }

    _detectActiveSpeaker() {
        let maxLevel = 0;
        let loudest = null;
        const threshold = 30; // Minimum level to count as "speaking"

        for (const [key, { analyser }] of this._analysers) {
            const data = new Uint8Array(analyser.frequencyBinCount);
            analyser.getByteFrequencyData(data);
            const level = Math.max(...data);

            if (level > threshold && level > maxLevel) {
                maxLevel = level;
                loudest = key;
            }
        }

        if (loudest && loudest !== this._activeSpeaker) {
            this._activeSpeaker = loudest;
        }
    }
}
