import interact from 'interactjs';

/**
 * @typedef {Object} TimelineStore
 * @property {function(number, number, number): void} trimClip
 * @property {function(number, number): void} moveClip
 * @property {function(number, number, number): void} trimVoiceover
 * @property {function(number, number): void} moveVoiceover
 * @property {function(): number} getTimelineDurationMs
 * @property {function(string, number): void} select
 */

/**
 * @typedef {Object} TimelineUIOptions
 * @property {TimelineStore} store
 * @property {function(number): void} onSeek
 */

/**
 * @typedef {Object} SnapTarget
 * @property {number} x - The x pixel position to snap to
 */

/**
 * Manages interact.js-powered drag and trim handles for timeline clip
 * and voiceover blocks in the video studio editor.
 *
 * Expects a DOM structure with specific CSS classes:
 * - `.timeline-clip` — clip blocks in the video track
 * - `.timeline-voiceover` — voiceover blocks in the audio track
 * - `.trim-left`, `.trim-right` — 4px wide handles on left/right edges
 * - `.timeline-playhead` — the red vertical playhead line
 * - `.timeline-ruler` — the time ruler bar
 */
export default class TimelineUI {
    /** @type {TimelineStore} */
    _store;

    /** @type {function(number): void} */
    _onSeek;

    /** @type {HTMLElement|null} */
    _containerEl = null;

    /** @type {number} Pixels per second zoom factor (1 = default, max 10) */
    _zoom = 1;

    /** @type {import('interactjs').Interactable[]} Tracked interactable instances for cleanup */
    _interactables = [];

    /**
     * @param {TimelineUIOptions} options
     */
    constructor({ store, onSeek }) {
        this._store = store;
        this._onSeek = onSeek;
    }

    /**
     * Set up interact.js on the container element. Should be called
     * after the DOM is ready and the timeline elements are rendered.
     *
     * @param {HTMLElement} containerEl - The root timeline container element
     */
    init(containerEl) {
        this._containerEl = containerEl;

        this._setupClipDraggable();
        this._setupClipTrimHandles();
        this._setupVoiceoverDraggable();
        this._setupVoiceoverTrimHandles();
        this._setupRulerClick();
    }

    /**
     * Clean up all interact.js instances and event listeners.
     */
    destroy() {
        for (const interactable of this._interactables) {
            interactable.unset();
        }
        this._interactables = [];

        if (this._containerEl) {
            const ruler = this._containerEl.querySelector('.timeline-ruler');
            if (ruler) {
                ruler.removeEventListener('click', this._handleRulerClick);
            }
        }

        this._containerEl = null;
    }

    /**
     * Update the pixels-per-second zoom factor.
     *
     * @param {number} zoom - Zoom level between 1 and 10
     */
    setZoom(zoom) {
        this._zoom = Math.max(1, Math.min(10, zoom));
    }

    /**
     * Get the current zoom level.
     *
     * @returns {number}
     */
    getZoom() {
        return this._zoom;
    }

    /**
     * Move the playhead element to a given percentage of the timeline width.
     *
     * @param {number} percent - Value between 0 and 100
     */
    updatePlayhead(percent) {
        if (!this._containerEl) {
            return;
        }

        const playhead = this._containerEl.querySelector('.timeline-playhead');
        if (!playhead) {
            return;
        }

        const clamped = Math.max(0, Math.min(100, percent));
        playhead.style.left = `${clamped}%`;
    }

    /**
     * Re-initialize interact.js after DOM changes (e.g., new clips added).
     * Destroys existing instances and sets them up again.
     */
    refresh() {
        if (!this._containerEl) {
            return;
        }

        const container = this._containerEl;
        this.destroy();
        this.init(container);
    }

    // -------------------------------------------------------------------------
    // interact.js setup
    // -------------------------------------------------------------------------

    /**
     * Set up draggable behaviour for clip blocks.
     * Clips are constrained to horizontal movement within their parent track.
     */
    _setupClipDraggable() {
        const interactable = interact('.timeline-clip', { context: this._containerEl })
            .styleCursor(false)
            .draggable({
                axis: 'x',
                inertia: false,
                autoScroll: false,
                modifiers: [
                    interact.modifiers.restrict({
                        restriction: 'parent',
                        elementRect: { left: 0, right: 1, top: 0, bottom: 1 },
                    }),
                    interact.modifiers.snap({
                        targets: this._getSnapTargets.bind(this),
                        range: 5,
                        relativePoints: [
                            { x: 0, y: 0.5 },
                            { x: 1, y: 0.5 },
                        ],
                    }),
                ],
                listeners: {
                    start: (event) => {
                        event.target.classList.add('timeline-clip--dragging');
                    },
                    move: (event) => {
                        const el = event.target;
                        const currentX = parseFloat(el.dataset.dragX || '0');
                        const newX = currentX + event.dx;
                        el.dataset.dragX = String(newX);
                        el.style.transform = `translateX(${newX}px)`;
                    },
                    end: (event) => {
                        const el = event.target;
                        const clipId = parseInt(el.dataset.clipId, 10);
                        const dx = parseFloat(el.dataset.dragX || '0');
                        const deltaMs = this._pxToMs(dx);

                        const currentStartMs = parseInt(el.dataset.startMs || '0', 10);
                        const newStartMs = Math.max(0, currentStartMs + deltaMs);

                        // Reset visual transform
                        el.style.transform = '';
                        el.dataset.dragX = '0';
                        el.classList.remove('timeline-clip--dragging');

                        this._store.moveClip(clipId, Math.round(newStartMs));
                    },
                },
            });

        this._interactables.push(interactable);
    }

    /**
     * Set up resizable trim handles for clip blocks.
     * Left edge adjusts in_point_ms, right edge adjusts out_point_ms.
     */
    _setupClipTrimHandles() {
        const interactable = interact('.timeline-clip', { context: this._containerEl })
            .resizable({
                edges: {
                    left: '.trim-left',
                    right: '.trim-right',
                    top: false,
                    bottom: false,
                },
                modifiers: [
                    interact.modifiers.restrictSize({
                        min: { width: 20, height: 0 },
                    }),
                ],
                listeners: {
                    start: (event) => {
                        event.target.classList.add('timeline-clip--trimming');
                    },
                    move: (event) => {
                        const el = event.target;

                        // Update element width
                        el.style.width = `${event.rect.width}px`;

                        // If trimming from the left, shift the element position
                        if (event.deltaRect.left !== 0) {
                            const currentX = parseFloat(el.dataset.trimX || '0');
                            const newX = currentX + event.deltaRect.left;
                            el.dataset.trimX = String(newX);
                            el.style.transform = `translateX(${newX}px)`;
                        }
                    },
                    end: (event) => {
                        const el = event.target;
                        const clipId = parseInt(el.dataset.clipId, 10);

                        const originalInMs = parseInt(el.dataset.inPointMs || '0', 10);
                        const originalOutMs = parseInt(el.dataset.outPointMs || '0', 10);

                        // Calculate new trim points from pixel deltas
                        const leftDeltaPx = parseFloat(el.dataset.trimX || '0');
                        const widthDeltaPx = event.rect.width - (parseFloat(el.dataset.originalWidth || String(event.rect.width)));

                        const newInMs = Math.max(0, originalInMs + this._pxToMs(leftDeltaPx));
                        const newOutMs = Math.max(newInMs + this._pxToMs(20), originalOutMs + this._pxToMs(widthDeltaPx - leftDeltaPx));

                        // Reset visual state
                        el.style.transform = '';
                        el.style.width = '';
                        el.dataset.trimX = '0';
                        el.classList.remove('timeline-clip--trimming');

                        this._store.trimClip(clipId, Math.round(newInMs), Math.round(newOutMs));
                    },
                },
            });

        this._interactables.push(interactable);
    }

    /**
     * Set up draggable behaviour for voiceover blocks.
     * Same pattern as clip drag but operates on voiceover data.
     */
    _setupVoiceoverDraggable() {
        const interactable = interact('.timeline-voiceover', { context: this._containerEl })
            .styleCursor(false)
            .draggable({
                axis: 'x',
                inertia: false,
                autoScroll: false,
                modifiers: [
                    interact.modifiers.restrict({
                        restriction: 'parent',
                        elementRect: { left: 0, right: 1, top: 0, bottom: 1 },
                    }),
                    interact.modifiers.snap({
                        targets: this._getSnapTargets.bind(this),
                        range: 5,
                        relativePoints: [
                            { x: 0, y: 0.5 },
                            { x: 1, y: 0.5 },
                        ],
                    }),
                ],
                listeners: {
                    start: (event) => {
                        event.target.classList.add('timeline-voiceover--dragging');
                    },
                    move: (event) => {
                        const el = event.target;
                        const currentX = parseFloat(el.dataset.dragX || '0');
                        const newX = currentX + event.dx;
                        el.dataset.dragX = String(newX);
                        el.style.transform = `translateX(${newX}px)`;
                    },
                    end: (event) => {
                        const el = event.target;
                        const voId = parseInt(el.dataset.voId, 10);
                        const dx = parseFloat(el.dataset.dragX || '0');
                        const deltaMs = this._pxToMs(dx);

                        const currentStartMs = parseInt(el.dataset.startMs || '0', 10);
                        const newStartMs = Math.max(0, currentStartMs + deltaMs);

                        // Reset visual transform
                        el.style.transform = '';
                        el.dataset.dragX = '0';
                        el.classList.remove('timeline-voiceover--dragging');

                        this._store.moveVoiceover(voId, Math.round(newStartMs));
                    },
                },
            });

        this._interactables.push(interactable);
    }

    /**
     * Set up resizable trim handles for voiceover blocks.
     * Same pattern as clip trim but operates on voiceover data.
     */
    _setupVoiceoverTrimHandles() {
        const interactable = interact('.timeline-voiceover', { context: this._containerEl })
            .resizable({
                edges: {
                    left: '.trim-left',
                    right: '.trim-right',
                    top: false,
                    bottom: false,
                },
                modifiers: [
                    interact.modifiers.restrictSize({
                        min: { width: 20, height: 0 },
                    }),
                ],
                listeners: {
                    start: (event) => {
                        event.target.classList.add('timeline-voiceover--trimming');
                    },
                    move: (event) => {
                        const el = event.target;

                        el.style.width = `${event.rect.width}px`;

                        if (event.deltaRect.left !== 0) {
                            const currentX = parseFloat(el.dataset.trimX || '0');
                            const newX = currentX + event.deltaRect.left;
                            el.dataset.trimX = String(newX);
                            el.style.transform = `translateX(${newX}px)`;
                        }
                    },
                    end: (event) => {
                        const el = event.target;
                        const voId = parseInt(el.dataset.voId, 10);

                        const originalInMs = parseInt(el.dataset.inPointMs || '0', 10);
                        const originalOutMs = parseInt(el.dataset.outPointMs || '0', 10);

                        const leftDeltaPx = parseFloat(el.dataset.trimX || '0');
                        const widthDeltaPx = event.rect.width - (parseFloat(el.dataset.originalWidth || String(event.rect.width)));

                        const newInMs = Math.max(0, originalInMs + this._pxToMs(leftDeltaPx));
                        const newOutMs = Math.max(newInMs + this._pxToMs(20), originalOutMs + this._pxToMs(widthDeltaPx - leftDeltaPx));

                        // Reset visual state
                        el.style.transform = '';
                        el.style.width = '';
                        el.dataset.trimX = '0';
                        el.classList.remove('timeline-voiceover--trimming');

                        this._store.trimVoiceover(voId, Math.round(newInMs), Math.round(newOutMs));
                    },
                },
            });

        this._interactables.push(interactable);
    }

    /**
     * Attach a click listener to the ruler bar so users can seek
     * by clicking a position on the timeline.
     */
    _setupRulerClick() {
        if (!this._containerEl) {
            return;
        }

        const ruler = this._containerEl.querySelector('.timeline-ruler');
        if (!ruler) {
            return;
        }

        ruler.addEventListener('click', this._handleRulerClick);
    }

    /**
     * Bound handler for ruler click events.
     * Computes the time position from the click's x coordinate.
     *
     * @param {MouseEvent} event
     */
    _handleRulerClick = (event) => {
        const ruler = event.currentTarget;
        const rect = ruler.getBoundingClientRect();
        const clickX = event.clientX - rect.left;
        const timeMs = this._pxToMs(clickX);

        this._onSeek(Math.max(0, Math.round(timeMs)));
    };

    // -------------------------------------------------------------------------
    // Snap targets
    // -------------------------------------------------------------------------

    /**
     * Build snap targets from all clip and voiceover edges in the
     * container, plus the current playhead position.
     *
     * @returns {SnapTarget[]}
     */
    _getSnapTargets() {
        /** @type {SnapTarget[]} */
        const targets = [];

        if (!this._containerEl) {
            return targets;
        }

        // Collect start/end positions of all clips
        const clips = this._containerEl.querySelectorAll('.timeline-clip');
        for (const clip of clips) {
            const startMs = parseInt(clip.dataset.startMs || '0', 10);
            const outMs = parseInt(clip.dataset.outPointMs || '0', 10);
            const inMs = parseInt(clip.dataset.inPointMs || '0', 10);
            const endMs = startMs + (outMs - inMs);

            targets.push({ x: this._msToPx(startMs), range: 5 });
            targets.push({ x: this._msToPx(endMs), range: 5 });
        }

        // Collect start/end positions of all voiceovers
        const voiceovers = this._containerEl.querySelectorAll('.timeline-voiceover');
        for (const vo of voiceovers) {
            const startMs = parseInt(vo.dataset.startMs || '0', 10);
            const outMs = parseInt(vo.dataset.outPointMs || '0', 10);
            const inMs = parseInt(vo.dataset.inPointMs || '0', 10);
            const endMs = startMs + (outMs - inMs);

            targets.push({ x: this._msToPx(startMs), range: 5 });
            targets.push({ x: this._msToPx(endMs), range: 5 });
        }

        // Add playhead position as a snap target
        const playhead = this._containerEl.querySelector('.timeline-playhead');
        if (playhead) {
            const playheadLeft = parseFloat(playhead.style.left) || 0;
            const containerWidth = this._containerEl.getBoundingClientRect().width;

            // playhead.style.left is a percentage, convert to px
            const playheadPx = (playheadLeft / 100) * containerWidth;
            targets.push({ x: playheadPx, range: 5 });
        }

        return targets;
    }

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    /**
     * Convert a pixel distance to milliseconds based on the container width,
     * timeline duration, and current zoom level.
     *
     * @param {number} px - Pixel distance
     * @returns {number} Equivalent time in milliseconds
     */
    _pxToMs(px) {
        if (!this._containerEl) {
            return 0;
        }

        const containerWidth = this._containerEl.getBoundingClientRect().width;
        if (containerWidth === 0) {
            return 0;
        }

        const durationMs = this._getTimelineDurationMs();
        return (px / (containerWidth * this._zoom)) * durationMs;
    }

    /**
     * Convert a time in milliseconds to pixels based on the container width,
     * timeline duration, and current zoom level.
     *
     * @param {number} ms - Time in milliseconds
     * @returns {number} Equivalent pixel distance
     */
    _msToPx(ms) {
        if (!this._containerEl) {
            return 0;
        }

        const containerWidth = this._containerEl.getBoundingClientRect().width;
        const durationMs = this._getTimelineDurationMs();
        if (durationMs === 0) {
            return 0;
        }

        return (ms / durationMs) * containerWidth * this._zoom;
    }

    /**
     * Get the total timeline duration in milliseconds by delegating
     * to the store.
     *
     * @returns {number}
     */
    _getTimelineDurationMs() {
        return this._store.getTimelineDurationMs();
    }
}
