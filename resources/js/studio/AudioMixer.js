/**
 * AudioMixer — Web Audio API manager for the video studio editor.
 *
 * Handles unified audio mixing for video clips and voiceovers with
 * per-source volume control and a master gain bus.
 *
 * Audio Graph:
 *   VideoElement   -> MediaElementSource -> GainNode (clip volume) --\
 *   VoiceoverAudio -> MediaElementSource -> GainNode (VO volume)  ---+-> GainNode (master) -> destination
 *
 * @module AudioMixer
 */

/**
 * @typedef {Object} SourceEntry
 * @property {MediaElementAudioSourceNode} source
 * @property {GainNode} gain
 */

/**
 * @typedef {Object} VoiceoverSourceEntry
 * @property {HTMLAudioElement} audio
 * @property {MediaElementAudioSourceNode} source
 * @property {GainNode} gain
 */

/**
 * @typedef {Object} VoiceoverData
 * @property {number|string} id
 * @property {string} url
 * @property {number} start_ms       — timeline position where this voiceover begins
 * @property {number} in_point_ms    — source-level trim start
 * @property {number|null} out_point_ms — source-level trim end (null = full duration)
 * @property {number} duration_seconds — total source duration in seconds
 * @property {number} volume          — percentage 0-200
 */

export default class AudioMixer {
    constructor() {
        /** @type {AudioContext|null} */
        this._audioContext = null;

        /** @type {GainNode|null} */
        this._masterGain = null;

        /** @type {Map<string|number, SourceEntry>} */
        this._clipSources = new Map();

        /** @type {Map<string|number, VoiceoverSourceEntry>} */
        this._voSources = new Map();

        /** @type {Set<string|number>} — tracks which clipIds have been connected */
        this._connectedClips = new Set();

        /** @type {Set<string|number>} — tracks which voIds have been connected */
        this._connectedVoiceovers = new Set();
    }

    // =========================================================================
    // Context lifecycle
    // =========================================================================

    /**
     * Create the AudioContext lazily and resume it if suspended.
     * Should be called from a user-interaction handler (click, keydown, etc.)
     * to satisfy autoplay policies.
     *
     * @returns {Promise<AudioContext>}
     */
    async ensureContext() {
        if (!this._audioContext) {
            this._audioContext = new (window.AudioContext || window.webkitAudioContext)();
            this._masterGain = this._audioContext.createGain();
            this._masterGain.connect(this._audioContext.destination);
        }

        if (this._audioContext.state === 'suspended') {
            await this._audioContext.resume();
        }

        return this._audioContext;
    }

    // =========================================================================
    // Video clip connections
    // =========================================================================

    /**
     * Connect a <video> element into the audio graph.
     *
     * @param {HTMLVideoElement} videoElement — the DOM video element
     * @param {string|number} clipId — unique clip identifier
     * @param {number} volume — percentage (0-200), converted to gain fraction (0-2)
     * @returns {void}
     */
    connectVideo(videoElement, clipId, volume = 100) {
        if (this._connectedClips.has(clipId)) {
            return;
        }

        if (!this._audioContext || !this._masterGain) {
            return;
        }

        try {
            const source = this._audioContext.createMediaElementSource(videoElement);
            const gain = this._audioContext.createGain();
            gain.gain.value = this._clamp(volume / 100, 0, 2);

            source.connect(gain);
            gain.connect(this._masterGain);

            this._clipSources.set(clipId, { source, gain });
            this._connectedClips.add(clipId);
        } catch (err) {
            // MediaElementSource may already be created for this element
            console.warn(`[AudioMixer] Could not connect video clip ${clipId}:`, err.message);
        }
    }

    /**
     * Disconnect and clean up a video clip from the audio graph.
     *
     * @param {string|number} clipId
     * @returns {void}
     */
    disconnectVideo(clipId) {
        const entry = this._clipSources.get(clipId);
        if (!entry) {
            return;
        }

        try {
            entry.source.disconnect();
            entry.gain.disconnect();
        } catch {
            // already disconnected — ignore
        }

        this._clipSources.delete(clipId);
        this._connectedClips.delete(clipId);
    }

    /**
     * Update the gain value for a connected video clip.
     *
     * @param {string|number} clipId
     * @param {number} volume — gain fraction in range 0-2
     * @returns {void}
     */
    setClipVolume(clipId, volume) {
        const entry = this._clipSources.get(clipId);
        if (entry) {
            entry.gain.gain.value = this._clamp(volume, 0, 2);
        }
    }

    // =========================================================================
    // Voiceover connections
    // =========================================================================

    /**
     * Connect a voiceover <audio> element into the audio graph.
     *
     * @param {HTMLAudioElement} audioElement — the DOM audio element
     * @param {string|number} voId — unique voiceover identifier
     * @param {number} volume — percentage (0-200), converted to gain fraction (0-2)
     * @returns {void}
     */
    connectVoiceover(audioElement, voId, volume = 100) {
        if (this._connectedVoiceovers.has(voId)) {
            return;
        }

        if (!this._audioContext || !this._masterGain) {
            return;
        }

        try {
            const source = this._audioContext.createMediaElementSource(audioElement);
            const gain = this._audioContext.createGain();
            gain.gain.value = this._clamp(volume / 100, 0, 2);

            source.connect(gain);
            gain.connect(this._masterGain);

            this._voSources.set(voId, { audio: audioElement, source, gain });
            this._connectedVoiceovers.add(voId);
        } catch (err) {
            console.warn(`[AudioMixer] Could not connect voiceover ${voId}:`, err.message);
        }
    }

    /**
     * Disconnect and clean up a voiceover from the audio graph.
     *
     * @param {string|number} voId
     * @returns {void}
     */
    disconnectVoiceover(voId) {
        const entry = this._voSources.get(voId);
        if (!entry) {
            return;
        }

        try {
            entry.audio.pause();
            entry.source.disconnect();
            entry.gain.disconnect();
        } catch {
            // already disconnected — ignore
        }

        this._voSources.delete(voId);
        this._connectedVoiceovers.delete(voId);
    }

    /**
     * Update the gain value for a connected voiceover.
     *
     * @param {string|number} voId
     * @param {number} volume — gain fraction in range 0-2
     * @returns {void}
     */
    setVoiceoverVolume(voId, volume) {
        const entry = this._voSources.get(voId);
        if (entry) {
            entry.gain.gain.value = this._clamp(volume, 0, 2);
        }
    }

    // =========================================================================
    // Master volume
    // =========================================================================

    /**
     * Set the master output gain.
     *
     * @param {number} volume — gain fraction in range 0-1
     * @returns {void}
     */
    setMasterVolume(volume) {
        if (this._masterGain) {
            this._masterGain.gain.value = this._clamp(volume, 0, 1);
        }
    }

    // =========================================================================
    // Auto-leveling
    // =========================================================================

    /**
     * Analyze the RMS level of every connected source and normalize each
     * gain so that the average loudness is consistent across all tracks.
     *
     * This reads a short sample of frequency data from each source,
     * computes RMS, then adjusts individual gains inversely proportional
     * to their relative loudness.
     *
     * @returns {void}
     */
    autoLevel() {
        if (!this._audioContext) {
            return;
        }

        const entries = [
            ...Array.from(this._clipSources.values()),
            ...Array.from(this._voSources.values()),
        ];

        if (entries.length === 0) {
            return;
        }

        // Create a temporary analyser for each source
        const rmsValues = entries.map((entry) => {
            const analyser = this._audioContext.createAnalyser();
            analyser.fftSize = 2048;

            entry.source.connect(analyser);

            const dataArray = new Float32Array(analyser.fftSize);
            analyser.getFloatTimeDomainData(dataArray);

            // Compute RMS
            let sumSquares = 0;
            for (let i = 0; i < dataArray.length; i++) {
                sumSquares += dataArray[i] * dataArray[i];
            }
            const rms = Math.sqrt(sumSquares / dataArray.length);

            // Disconnect temporary analyser
            try {
                entry.source.disconnect(analyser);
            } catch {
                // ignore
            }

            return { entry, rms };
        });

        // Compute average RMS across all sources
        const avgRms = rmsValues.reduce((sum, v) => sum + v.rms, 0) / rmsValues.length;

        if (avgRms === 0) {
            return;
        }

        // Normalize each source gain relative to the average
        for (const { entry, rms } of rmsValues) {
            if (rms > 0) {
                const adjustment = avgRms / rms;
                const newGain = this._clamp(entry.gain.gain.value * adjustment, 0, 2);
                entry.gain.gain.value = newGain;
            }
        }
    }

    // =========================================================================
    // Voiceover playback synchronization
    // =========================================================================

    /**
     * Synchronize voiceover playback to the current timeline position.
     *
     * For each voiceover, determines whether it should be playing at
     * `currentTimeMs` and either seeks + plays or pauses accordingly.
     *
     * @param {VoiceoverData[]} voiceovers
     * @param {number} currentTimeMs — current playhead position on the timeline in ms
     * @returns {void}
     */
    syncVoiceovers(voiceovers, currentTimeMs) {
        for (const vo of voiceovers) {
            const entry = this._voSources.get(vo.id);
            if (!entry) {
                continue;
            }

            const inPointMs = vo.in_point_ms ?? 0;
            const outPointMs = vo.out_point_ms ?? (vo.duration_seconds * 1000);
            const voStartMs = vo.start_ms;
            const voEndMs = voStartMs + (outPointMs - inPointMs);

            if (currentTimeMs >= voStartMs && currentTimeMs < voEndMs) {
                // Should be playing — compute correct seek position within the source
                const offsetIntoClipMs = currentTimeMs - voStartMs;
                const seekPositionSec = (inPointMs + offsetIntoClipMs) / 1000;

                if (entry.audio.paused) {
                    entry.audio.currentTime = seekPositionSec;
                    entry.audio.play().catch(() => {
                        // Autoplay may be blocked — ignore
                    });
                }
            } else {
                // Outside range — pause if playing
                if (!entry.audio.paused) {
                    entry.audio.pause();
                }
            }
        }
    }

    /**
     * Start playback for all voiceovers from the given timeline position.
     *
     * Each voiceover is seeked to its correct offset and played if it
     * falls within the current time window.
     *
     * @param {VoiceoverData[]} voiceovers
     * @param {number} currentTimeMs
     * @returns {void}
     */
    playVoiceovers(voiceovers, currentTimeMs) {
        for (const vo of voiceovers) {
            const entry = this._voSources.get(vo.id);
            if (!entry) {
                continue;
            }

            const inPointMs = vo.in_point_ms ?? 0;
            const outPointMs = vo.out_point_ms ?? (vo.duration_seconds * 1000);
            const voStartMs = vo.start_ms;
            const voEndMs = voStartMs + (outPointMs - inPointMs);

            if (currentTimeMs >= voStartMs && currentTimeMs < voEndMs) {
                const offsetIntoClipMs = currentTimeMs - voStartMs;
                const seekPositionSec = (inPointMs + offsetIntoClipMs) / 1000;

                entry.audio.currentTime = seekPositionSec;
                entry.audio.play().catch(() => {
                    // Autoplay may be blocked — ignore
                });
            } else {
                // Not in range yet — make sure it is paused and ready
                entry.audio.pause();
            }
        }
    }

    /**
     * Pause all voiceover Audio elements.
     *
     * @returns {void}
     */
    pauseVoiceovers() {
        for (const [, entry] of this._voSources) {
            if (!entry.audio.paused) {
                entry.audio.pause();
            }
        }
    }

    // =========================================================================
    // Cleanup
    // =========================================================================

    /**
     * Close the AudioContext and release all references.
     *
     * @returns {Promise<void>}
     */
    async destroy() {
        // Pause all voiceovers first
        this.pauseVoiceovers();

        // Disconnect all clip sources
        for (const [clipId] of this._clipSources) {
            this.disconnectVideo(clipId);
        }

        // Disconnect all voiceover sources
        for (const [voId] of this._voSources) {
            this.disconnectVoiceover(voId);
        }

        // Close the context
        if (this._audioContext && this._audioContext.state !== 'closed') {
            await this._audioContext.close();
        }

        this._audioContext = null;
        this._masterGain = null;
        this._clipSources.clear();
        this._voSources.clear();
        this._connectedClips.clear();
        this._connectedVoiceovers.clear();
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Clamp a numeric value between min and max.
     *
     * @param {number} value
     * @param {number} min
     * @param {number} max
     * @returns {number}
     * @private
     */
    _clamp(value, min, max) {
        return Math.min(Math.max(value, min), max);
    }
}
