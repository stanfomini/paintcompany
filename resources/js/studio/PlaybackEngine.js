/**
 * @typedef {Object} Clip
 * @property {number} id
 * @property {number} in_point_ms
 * @property {number} out_point_ms
 * @property {number} start_ms
 * @property {number} duration_seconds
 * @property {string} url
 * @property {string} stream_url
 */

/**
 * @typedef {Object} Overlay
 * @property {number} id
 * @property {number} start_ms
 * @property {number} end_ms
 */

/**
 * @typedef {Object} Scene
 * @property {number} id
 * @property {number} duration_ms
 * @property {Clip[]} clips
 * @property {Overlay[]} overlays
 */

/**
 * @typedef {Object} FrameData
 * @property {number} globalTimeMs - Current time in the entire project timeline
 * @property {number} sceneIndex - Index of the currently active scene
 * @property {number} sceneLocalTimeMs - Current time within the active scene
 * @property {number} sceneDurationMs - Total duration of the active scene
 */

/**
 * @typedef {Object} SceneLocation
 * @property {number} sceneIndex
 * @property {number} localTimeMs
 * @property {Scene} scene
 */

/**
 * @typedef {Object} PlaybackCallbacks
 * @property {function(FrameData): void} onFrame - Called every animation frame during playback
 * @property {function(number, number): void} onSceneChange - Called when the active scene changes (newIndex, prevIndex)
 * @property {function(): void} onEnd - Called when playback reaches the end of all scenes
 */

const DEFAULT_SCENE_DURATION_MS = 5000;

/**
 * A requestAnimationFrame-based playback engine for the video studio editor.
 *
 * This is a pure timing engine with no DOM access. It tracks global playback
 * position across multiple scenes and dispatches frame callbacks that callers
 * use to synchronize video elements, overlays, and UI.
 */
export default class PlaybackEngine {
    /** @type {number} */
    currentTimeMs = 0;

    /** @type {boolean} */
    isPlaying = false;

    /** @type {number} */
    activeSceneIndex = 0;

    constructor({ onFrame, onSceneChange, onEnd }) {
        /** @type {Scene[]} */
        this._scenes = [];

        /** @type {number} */
        this._totalDurationMs = 0;

        /** @type {number[]} Cumulative start time of each scene in the global timeline */
        this._sceneStartTimes = [];

        /** @type {function(FrameData): void} */
        this._onFrame = onFrame ?? (() => {});

        /** @type {function(number, number): void} */
        this._onSceneChange = onSceneChange ?? (() => {});

        /** @type {function(): void} */
        this._onEnd = onEnd ?? (() => {});

        /** @type {number|null} rAF handle for cancellation */
        this._rafId = null;

        /** @type {number} performance.now() timestamp when play() was called */
        this._playStartTimestamp = 0;

        /** @type {number} The currentTimeMs value when play() was called */
        this._playStartTimeMs = 0;
    }

    /**
     * Load scenes into the engine and compute timing data.
     *
     * @param {Scene[]} scenes
     */
    load(scenes) {
        this._scenes = scenes ?? [];
        this._computeTiming();
        this.currentTimeMs = 0;
        this.activeSceneIndex = 0;
    }

    /**
     * Start or resume playback.
     *
     * @param {number|null} [fromTimeMs=null] - If provided, start from this global time.
     *   Otherwise resume from the current position.
     */
    play(fromTimeMs = null) {
        if (this._scenes.length === 0) {
            return;
        }

        if (fromTimeMs !== null) {
            this.currentTimeMs = this._clampTime(fromTimeMs);
        }

        this.isPlaying = true;
        this._playStartTimestamp = performance.now();
        this._playStartTimeMs = this.currentTimeMs;

        this._tick(this._playStartTimestamp);
    }

    /**
     * Pause playback, preserving the current position.
     */
    pause() {
        this.isPlaying = false;
        this._cancelRaf();
    }

    /**
     * Seek to an absolute global time. Fires a single onFrame callback
     * so the caller can update the UI without starting playback.
     *
     * @param {number} timeMs
     */
    seek(timeMs) {
        this.currentTimeMs = this._clampTime(timeMs);
        this._updateActiveScene();
        this._emitFrame();
    }

    /**
     * Seek to a specific local time within a scene.
     *
     * @param {number} sceneIndex
     * @param {number} localTimeMs - Time relative to the start of the scene
     */
    seekScene(sceneIndex, localTimeMs) {
        if (sceneIndex < 0 || sceneIndex >= this._scenes.length) {
            return;
        }

        const globalTimeMs = this._sceneStartTimes[sceneIndex] + Math.max(0, localTimeMs);
        this.seek(globalTimeMs);
    }

    /**
     * Determine which scene contains a given global time.
     *
     * @param {number} globalTimeMs
     * @returns {SceneLocation}
     */
    getSceneAtTime(globalTimeMs) {
        const clamped = this._clampTime(globalTimeMs);

        for (let i = this._scenes.length - 1; i >= 0; i--) {
            if (clamped >= this._sceneStartTimes[i]) {
                const localTimeMs = clamped - this._sceneStartTimes[i];
                return {
                    sceneIndex: i,
                    localTimeMs,
                    scene: this._scenes[i],
                };
            }
        }

        // Fallback to first scene
        return {
            sceneIndex: 0,
            localTimeMs: 0,
            scene: this._scenes[0],
        };
    }

    /**
     * Get the current local time within the active scene.
     *
     * @returns {number}
     */
    getSceneLocalTimeMs() {
        if (this._scenes.length === 0) {
            return 0;
        }

        return this.currentTimeMs - this._sceneStartTimes[this.activeSceneIndex];
    }

    /**
     * Get the duration of a specific scene.
     *
     * @param {number} sceneIndex
     * @returns {number}
     */
    getSceneDurationMs(sceneIndex) {
        if (sceneIndex < 0 || sceneIndex >= this._scenes.length) {
            return 0;
        }

        return this._getSceneDuration(this._scenes[sceneIndex]);
    }

    /**
     * Get the total duration across all loaded scenes.
     *
     * @returns {number}
     */
    getTotalDurationMs() {
        return this._totalDurationMs;
    }

    /**
     * Clean up the engine. Stops playback and releases references.
     */
    destroy() {
        this.pause();
        this._scenes = [];
        this._sceneStartTimes = [];
        this._totalDurationMs = 0;
        this.currentTimeMs = 0;
        this.activeSceneIndex = 0;
    }

    // -------------------------------------------------------------------------
    // Private methods
    // -------------------------------------------------------------------------

    /**
     * Compute cumulative scene start times and total duration.
     */
    _computeTiming() {
        this._sceneStartTimes = [];
        let accumulated = 0;

        for (const scene of this._scenes) {
            this._sceneStartTimes.push(accumulated);
            accumulated += this._getSceneDuration(scene);
        }

        this._totalDurationMs = accumulated;
    }

    /**
     * Get the duration of a single scene, falling back to the default.
     *
     * @param {Scene} scene
     * @returns {number}
     */
    _getSceneDuration(scene) {
        return scene.duration_ms > 0 ? scene.duration_ms : DEFAULT_SCENE_DURATION_MS;
    }

    /**
     * Clamp a time value to the valid range [0, totalDuration].
     *
     * @param {number} timeMs
     * @returns {number}
     */
    _clampTime(timeMs) {
        return Math.max(0, Math.min(timeMs, this._totalDurationMs));
    }

    /**
     * The core animation loop. Uses performance.now() for accurate elapsed time.
     *
     * @param {number} timestamp - The DOMHighResTimeStamp from requestAnimationFrame
     */
    _tick(timestamp) {
        if (!this.isPlaying) {
            return;
        }

        const elapsed = timestamp - this._playStartTimestamp;
        this.currentTimeMs = this._playStartTimeMs + elapsed;

        // Check if we've reached the end
        if (this.currentTimeMs >= this._totalDurationMs) {
            this.currentTimeMs = this._totalDurationMs;
            this.isPlaying = false;
            this._updateActiveScene();
            this._emitFrame();
            this._onEnd();
            return;
        }

        this._updateActiveScene();
        this._emitFrame();

        this._rafId = requestAnimationFrame((ts) => this._tick(ts));
    }

    /**
     * Determine and update the active scene index based on currentTimeMs.
     * Fires onSceneChange if the scene has changed.
     */
    _updateActiveScene() {
        const prevIndex = this.activeSceneIndex;
        const location = this.getSceneAtTime(this.currentTimeMs);
        this.activeSceneIndex = location.sceneIndex;

        if (this.activeSceneIndex !== prevIndex) {
            this._onSceneChange(this.activeSceneIndex, prevIndex);
        }
    }

    /**
     * Emit the current frame data to the onFrame callback.
     */
    _emitFrame() {
        const sceneDurationMs = this.getSceneDurationMs(this.activeSceneIndex);
        const sceneLocalTimeMs = this.currentTimeMs - this._sceneStartTimes[this.activeSceneIndex];

        this._onFrame({
            globalTimeMs: this.currentTimeMs,
            sceneIndex: this.activeSceneIndex,
            sceneLocalTimeMs,
            sceneDurationMs,
        });
    }

    /**
     * Cancel the pending requestAnimationFrame if one exists.
     */
    _cancelRaf() {
        if (this._rafId !== null) {
            cancelAnimationFrame(this._rafId);
            this._rafId = null;
        }
    }
}
