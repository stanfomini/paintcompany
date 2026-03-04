/**
 * TimelineStore — client-side state store for the video studio editor timeline.
 *
 * Mirrors Livewire `projectData` and provides mutations with undo/redo and
 * debounced save.  All mutations push an undo snapshot before modifying state.
 *
 * @module TimelineStore
 */

const MAX_UNDO = 50;
const MERGE_WINDOW_MS = 500;
const SAVE_DEBOUNCE_MS = 800;
const MERGE_TOLERANCE_MS = 10;

/**
 * Deep clone that works with Alpine reactive proxies.
 * JSON round-trip strips proxy wrappers and non-serializable properties.
 */
function deepClone(obj) {
    if (obj == null) return obj;
    return JSON.parse(JSON.stringify(obj));
}

/**
 * @typedef {Object} Clip
 * @property {number|string} id
 * @property {string} slot
 * @property {string} source_type
 * @property {number|string} source_id
 * @property {string} url
 * @property {string} stream_url
 * @property {string} thumbnail
 * @property {number} in_point_ms
 * @property {number} out_point_ms
 * @property {number} start_ms
 * @property {number} volume
 * @property {string} fit_mode
 * @property {number} sort_order
 * @property {string} source_title
 * @property {number} duration_seconds
 */

/**
 * @typedef {Object} Overlay
 * @property {number|string} id
 * @property {string} type
 * @property {string} content
 * @property {Object} style
 * @property {number} position_x
 * @property {number} position_y
 * @property {number} width
 * @property {number} height
 * @property {number} start_ms
 * @property {number} end_ms
 */

/**
 * @typedef {Object} Scene
 * @property {number} id
 * @property {string} name
 * @property {string} layout
 * @property {number} duration_ms
 * @property {number} trim_start_ms
 * @property {number} trim_end_ms
 * @property {string} transition_type
 * @property {number} transition_duration_ms
 * @property {string} background_color
 * @property {Clip[]} clips
 * @property {Overlay[]} overlays
 */

/**
 * @typedef {Object} Voiceover
 * @property {number|string} id
 * @property {number} recording_id
 * @property {string} url
 * @property {number} duration_seconds
 * @property {number} start_ms
 * @property {number} in_point_ms
 * @property {number} out_point_ms
 * @property {number} volume
 * @property {number} sort_order
 */

/**
 * @typedef {Object} ProjectData
 * @property {number} id
 * @property {number} resolution_width
 * @property {number} resolution_height
 * @property {number} fps
 * @property {Scene[]} scenes
 * @property {Voiceover[]} voiceovers
 */

/**
 * @typedef {Object} SelectedItem
 * @property {string} type - 'clip' | 'voiceover' | 'overlay'
 * @property {number|string} id
 */

/**
 * @typedef {Object} UndoSnapshot
 * @property {Scene[]} scenes
 * @property {Voiceover[]} voiceovers
 * @property {number} timestamp
 */

export default class TimelineStore {
    /** @type {ProjectData|null} */
    data = null;

    /** @type {number|null} */
    activeSceneId = null;

    /** @type {SelectedItem|null} */
    selectedItem = null;

    /** @type {UndoSnapshot[]} */
    _undoStack = [];

    /** @type {UndoSnapshot[]} */
    _redoStack = [];

    /** @type {number} */
    _tempIdCounter = 0;

    /** @type {number|null} */
    _saveTimer = null;

    /** @type {Function|null} */
    _onSave = null;

    /**
     * @param {Object} options
     * @param {Function} options.onSave - Callback receiving { clips, voiceovers }
     */
    constructor({ onSave } = {}) {
        this._onSave = onSave || null;
    }

    // -------------------------------------------------------------------------
    // Load / Refresh
    // -------------------------------------------------------------------------

    /**
     * Initial load of project data. Sets the first scene as active.
     *
     * @param {ProjectData} projectData
     */
    load(projectData) {
        this.data = deepClone(projectData);
        this._undoStack = [];
        this._redoStack = [];
        this.selectedItem = null;

        if (this.data.scenes && this.data.scenes.length > 0) {
            this.activeSceneId = this.data.scenes[0].id;
        }
    }

    /**
     * Merge fresh data from the server. Replaces internal data but preserves
     * the current selection if the referenced item still exists.
     *
     * @param {ProjectData} projectData
     */
    refresh(projectData) {
        const previousSelection = this.selectedItem
            ? { ...this.selectedItem }
            : null;
        const previousActiveSceneId = this.activeSceneId;

        this.data = deepClone(projectData);

        // Preserve active scene if it still exists.
        if (previousActiveSceneId !== null) {
            const sceneExists = this.data.scenes.some(
                (s) => s.id === previousActiveSceneId,
            );
            this.activeSceneId = sceneExists
                ? previousActiveSceneId
                : this.data.scenes.length > 0
                  ? this.data.scenes[0].id
                  : null;
        }

        // Preserve selection if the item still exists.
        if (previousSelection) {
            if (this._findItem(previousSelection.type, previousSelection.id)) {
                this.selectedItem = previousSelection;
            } else {
                this.selectedItem = null;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    /**
     * Returns the scene matching `activeSceneId`.
     *
     * @returns {Scene|null}
     */
    getActiveScene() {
        if (!this.data || this.activeSceneId === null) {
            return null;
        }
        return (
            this.data.scenes.find((s) => s.id === this.activeSceneId) || null
        );
    }

    /**
     * Returns clips of the active scene.
     *
     * @returns {Clip[]}
     */
    getActiveSceneClips() {
        const scene = this.getActiveScene();
        return scene ? scene.clips || [] : [];
    }

    /**
     * Returns overlays of the active scene, optionally filtered by type.
     *
     * @param {string} [type] - 'text', 'caption', etc.
     * @returns {Overlay[]}
     */
    getActiveSceneOverlays(type) {
        const scene = this.getActiveScene();
        if (!scene) {
            return [];
        }
        const overlays = scene.overlays || [];
        if (type) {
            return overlays.filter((o) => o.type === type);
        }
        return overlays;
    }

    /**
     * Computes the effective timeline duration in milliseconds.
     * Takes the maximum of: scene duration, furthest clip end, furthest
     * voiceover end, and furthest overlay end.
     *
     * @returns {number}
     */
    getTimelineDurationMs() {
        if (!this.data) {
            return 0;
        }

        const scene = this.getActiveScene();
        let maxMs = scene ? scene.duration_ms || 0 : 0;

        // Clip ends.
        const clips = this.getActiveSceneClips();
        for (const clip of clips) {
            const clipEnd = (clip.start_ms || 0) + this.getClipDurationMs(clip);
            if (clipEnd > maxMs) {
                maxMs = clipEnd;
            }
        }

        // Voiceover ends.
        const voiceovers = this.data.voiceovers || [];
        for (const vo of voiceovers) {
            const voEnd = (vo.start_ms || 0) + this.getVoiceoverDurationMs(vo);
            if (voEnd > maxMs) {
                maxMs = voEnd;
            }
        }

        // Overlay ends.
        const overlays = scene ? scene.overlays || [] : [];
        for (const overlay of overlays) {
            const oEnd = overlay.end_ms || 0;
            if (oEnd > maxMs) {
                maxMs = oEnd;
            }
        }

        return maxMs;
    }

    /**
     * Returns the playable duration of a clip in milliseconds.
     *
     * @param {Clip} clip
     * @returns {number}
     */
    getClipDurationMs(clip) {
        const inMs = clip.in_point_ms || 0;
        if (clip.out_point_ms != null && clip.out_point_ms > 0) {
            return clip.out_point_ms - inMs;
        }
        return (clip.duration_seconds || 0) * 1000 - inMs;
    }

    /**
     * Returns the playable duration of a voiceover in milliseconds.
     *
     * @param {Voiceover} vo
     * @returns {number}
     */
    getVoiceoverDurationMs(vo) {
        const inMs = vo.in_point_ms || 0;
        if (vo.out_point_ms != null && vo.out_point_ms > 0) {
            return vo.out_point_ms - inMs;
        }
        return (vo.duration_seconds || 0) * 1000 - inMs;
    }

    /**
     * Returns the left offset of a clip as a percentage of timeline duration.
     *
     * @param {Clip} clip
     * @returns {number}
     */
    getClipLeft(clip) {
        const total = this.getTimelineDurationMs();
        if (total <= 0) {
            return 0;
        }
        return ((clip.start_ms || 0) / total) * 100;
    }

    /**
     * Returns the width of a clip as a percentage of timeline duration.
     *
     * @param {Clip} clip
     * @returns {number}
     */
    getClipWidth(clip) {
        const total = this.getTimelineDurationMs();
        if (total <= 0) {
            return 0;
        }
        return (this.getClipDurationMs(clip) / total) * 100;
    }

    /**
     * Returns the left offset of an overlay as a percentage of timeline duration.
     *
     * @param {Overlay} overlay
     * @returns {number}
     */
    getOverlayLeft(overlay) {
        const total = this.getTimelineDurationMs();
        if (total <= 0) {
            return 0;
        }
        return ((overlay.start_ms || 0) / total) * 100;
    }

    /**
     * Returns the width of an overlay as a percentage of timeline duration.
     *
     * @param {Overlay} overlay
     * @returns {number}
     */
    getOverlayWidth(overlay) {
        const total = this.getTimelineDurationMs();
        if (total <= 0) {
            return 0;
        }
        const durationMs = (overlay.end_ms || 0) - (overlay.start_ms || 0);
        return (durationMs / total) * 100;
    }

    /**
     * Returns the left offset of a voiceover as a percentage of timeline duration.
     *
     * @param {Voiceover} vo
     * @returns {number}
     */
    getVoiceoverLeft(vo) {
        const total = this.getTimelineDurationMs();
        if (total <= 0) {
            return 0;
        }
        return ((vo.start_ms || 0) / total) * 100;
    }

    /**
     * Returns the width of a voiceover as a percentage of timeline duration.
     *
     * @param {Voiceover} vo
     * @returns {number}
     */
    getVoiceoverWidth(vo) {
        const total = this.getTimelineDurationMs();
        if (total <= 0) {
            return 0;
        }
        return (this.getVoiceoverDurationMs(vo) / total) * 100;
    }

    // -------------------------------------------------------------------------
    // Mutations — Clips
    // -------------------------------------------------------------------------

    /**
     * Update a clip's in/out trim points.
     *
     * @param {number|string} clipId
     * @param {number} inMs
     * @param {number} outMs
     */
    trimClip(clipId, inMs, outMs, silent = false) {
        if (!silent) this.pushUndo();
        const clip = this._findClip(clipId);
        if (!clip) {
            return;
        }
        clip.in_point_ms = inMs;
        clip.out_point_ms = outMs;
        if (!silent) this.scheduleSave();
    }

    /**
     * Move a clip to a new start position on the timeline.
     *
     * @param {number|string} clipId
     * @param {number} startMs
     * @param {boolean} silent - If true, skip undo push and debounced save
     */
    moveClip(clipId, startMs, silent = false) {
        if (!silent) this.pushUndo();
        const clip = this._findClip(clipId);
        if (!clip) {
            return;
        }
        clip.start_ms = startMs;
        if (!silent) this.scheduleSave();
    }

    /**
     * Split a clip at the given timestamp into two clips.
     * The original clip is trimmed to end at splitAtMs, and a new clip
     * is inserted immediately after with a temporary ID.
     *
     * @param {number|string} clipId
     * @param {number} splitAtMs - Absolute position relative to clip in_point_ms
     */
    splitClip(clipId, splitAtMs) {
        this.pushUndo();

        const scene = this._findSceneContainingClip(clipId);
        if (!scene) {
            return;
        }

        const clipIndex = scene.clips.findIndex((c) => c.id === clipId);
        const clip = scene.clips[clipIndex];
        if (!clip) {
            return;
        }

        const originalOut = clip.out_point_ms || (clip.duration_seconds || 0) * 1000;
        const newClip = deepClone(clip);

        // Original clip: trim to end at split point.
        clip.out_point_ms = splitAtMs;

        // New clip: starts at split point, keeps original out.
        newClip.id = 'temp_' + Date.now() + '_' + ++this._tempIdCounter;
        newClip.in_point_ms = splitAtMs;
        newClip.out_point_ms = originalOut;
        newClip.start_ms = (clip.start_ms || 0) + (splitAtMs - (clip.in_point_ms || 0));

        // Insert after the original clip.
        scene.clips.splice(clipIndex + 1, 0, newClip);

        this.scheduleSave();
    }

    /**
     * Remove a clip from its scene.
     *
     * @param {number|string} clipId
     */
    deleteClip(clipId) {
        this.pushUndo();

        const scene = this._findSceneContainingClip(clipId);
        if (!scene) {
            return;
        }

        scene.clips = scene.clips.filter((c) => c.id !== clipId);

        // Clear selection if the deleted clip was selected.
        if (this.isSelected('clip', clipId)) {
            this.deselect();
        }

        this.scheduleSave();
    }

    /**
     * Merge a clip with the adjacent clip if they share the same source and
     * are contiguous (within tolerance).
     *
     * @param {number|string} clipId
     */
    mergeClips(clipId) {
        const scene = this._findSceneContainingClip(clipId);
        if (!scene) {
            return;
        }

        const clipIndex = scene.clips.findIndex((c) => c.id === clipId);
        const clip = scene.clips[clipIndex];
        if (!clip) {
            return;
        }

        // Try merging with the next clip.
        const next = scene.clips[clipIndex + 1];
        if (next && this._canMerge(clip, next)) {
            this.pushUndo();
            clip.out_point_ms = next.out_point_ms;
            scene.clips.splice(clipIndex + 1, 1);
            this.scheduleSave();
            return;
        }

        // Try merging with the previous clip.
        if (clipIndex > 0) {
            const prev = scene.clips[clipIndex - 1];
            if (prev && this._canMerge(prev, clip)) {
                this.pushUndo();
                prev.out_point_ms = clip.out_point_ms;
                scene.clips.splice(clipIndex, 1);
                this.scheduleSave();
            }
        }
    }

    // -------------------------------------------------------------------------
    // Mutations — Voiceovers
    // -------------------------------------------------------------------------

    /**
     * Update a voiceover's in/out trim points.
     *
     * @param {number|string} voId
     * @param {number} inMs
     * @param {number} outMs
     */
    trimVoiceover(voId, inMs, outMs, silent = false) {
        if (!silent) this.pushUndo();
        const vo = this._findVoiceover(voId);
        if (!vo) {
            return;
        }
        vo.in_point_ms = inMs;
        vo.out_point_ms = outMs;
        if (!silent) this.scheduleSave();
    }

    /**
     * Move a voiceover to a new start position on the timeline.
     *
     * @param {number|string} voId
     * @param {number} startMs
     * @param {boolean} silent - If true, skip undo push and debounced save
     */
    moveVoiceover(voId, startMs, silent = false) {
        if (!silent) this.pushUndo();
        const vo = this._findVoiceover(voId);
        if (!vo) {
            return;
        }
        vo.start_ms = startMs;
        if (!silent) this.scheduleSave();
    }

    /**
     * Split a voiceover at the given timestamp into two voiceovers.
     *
     * @param {number|string} voId
     * @param {number} splitAtMs - Absolute position relative to voiceover in_point_ms
     */
    splitVoiceover(voId, splitAtMs) {
        this.pushUndo();

        if (!this.data || !this.data.voiceovers) {
            return;
        }

        const voIndex = this.data.voiceovers.findIndex((v) => v.id === voId);
        const vo = this.data.voiceovers[voIndex];
        if (!vo) {
            return;
        }

        const originalOut = vo.out_point_ms || (vo.duration_seconds || 0) * 1000;
        const newVo = deepClone(vo);

        // Original: trim to end at split point.
        vo.out_point_ms = splitAtMs;

        // New: starts at split point, keeps original out.
        newVo.id = 'temp_' + Date.now() + '_' + ++this._tempIdCounter;
        newVo.in_point_ms = splitAtMs;
        newVo.out_point_ms = originalOut;
        newVo.start_ms = (vo.start_ms || 0) + (splitAtMs - (vo.in_point_ms || 0));

        // Insert after the original.
        this.data.voiceovers.splice(voIndex + 1, 0, newVo);

        this.scheduleSave();
    }

    /**
     * Remove a voiceover.
     *
     * @param {number|string} voId
     */
    deleteVoiceover(voId) {
        this.pushUndo();

        if (!this.data || !this.data.voiceovers) {
            return;
        }

        this.data.voiceovers = this.data.voiceovers.filter((v) => v.id !== voId);

        if (this.isSelected('voiceover', voId)) {
            this.deselect();
        }

        this.scheduleSave();
    }

    /**
     * Merge a voiceover with an adjacent voiceover if they share the same
     * recording and are contiguous (within tolerance).
     *
     * @param {number|string} voId
     */
    mergeVoiceovers(voId) {
        if (!this.data || !this.data.voiceovers) {
            return;
        }

        const voIndex = this.data.voiceovers.findIndex((v) => v.id === voId);
        const vo = this.data.voiceovers[voIndex];
        if (!vo) {
            return;
        }

        // Try merging with the next voiceover.
        const next = this.data.voiceovers[voIndex + 1];
        if (next && this._canMerge(vo, next)) {
            this.pushUndo();
            vo.out_point_ms = next.out_point_ms;
            this.data.voiceovers.splice(voIndex + 1, 1);
            this.scheduleSave();
            return;
        }

        // Try merging with the previous voiceover.
        if (voIndex > 0) {
            const prev = this.data.voiceovers[voIndex - 1];
            if (prev && this._canMerge(prev, vo)) {
                this.pushUndo();
                prev.out_point_ms = vo.out_point_ms;
                this.data.voiceovers.splice(voIndex, 1);
                this.scheduleSave();
            }
        }
    }

    // -------------------------------------------------------------------------
    // Mutations — Overlays
    // -------------------------------------------------------------------------

    /**
     * Update an overlay's position.
     *
     * @param {number|string} overlayId
     * @param {number} posX
     * @param {number} posY
     */
    updateOverlayPosition(overlayId, posX, posY) {
        this.pushUndo();
        const overlay = this._findOverlay(overlayId);
        if (!overlay) {
            return;
        }
        overlay.position_x = posX;
        overlay.position_y = posY;
        this.scheduleSave();
    }

    // -------------------------------------------------------------------------
    // Undo / Redo
    // -------------------------------------------------------------------------

    /**
     * Pushes the current scenes and voiceovers onto the undo stack.
     * Merges with the last snapshot if the previous push was within the
     * merge window (500ms) to coalesce rapid edits.
     */
    pushUndo() {
        if (!this.data) {
            return;
        }

        const now = Date.now();
        const last = this._undoStack.length > 0
            ? this._undoStack[this._undoStack.length - 1]
            : null;

        // Merge rapid edits: if the last push was within the window, skip
        // storing a new snapshot (the previous state is already captured).
        if (last && now - last.timestamp < MERGE_WINDOW_MS) {
            return;
        }

        /** @type {UndoSnapshot} */
        const snapshot = {
            scenes: deepClone(this.data.scenes),
            voiceovers: deepClone(this.data.voiceovers),
            timestamp: now,
        };

        this._undoStack.push(snapshot);

        // Cap the stack size.
        if (this._undoStack.length > MAX_UNDO) {
            this._undoStack.shift();
        }

        // Any new edit clears the redo stack.
        this._redoStack = [];
    }

    /**
     * Reverts to the most recent undo snapshot.
     *
     * @returns {boolean} Whether the undo succeeded.
     */
    undo() {
        if (!this.data || this._undoStack.length === 0) {
            return false;
        }

        // Push current state onto redo before reverting.
        this._redoStack.push({
            scenes: deepClone(this.data.scenes),
            voiceovers: deepClone(this.data.voiceovers),
            timestamp: Date.now(),
        });

        const snapshot = this._undoStack.pop();
        this.data.scenes = snapshot.scenes;
        this.data.voiceovers = snapshot.voiceovers;

        this.scheduleSave();
        return true;
    }

    /**
     * Re-applies the most recently undone change.
     *
     * @returns {boolean} Whether the redo succeeded.
     */
    redo() {
        if (!this.data || this._redoStack.length === 0) {
            return false;
        }

        // Push current state onto undo before re-applying.
        this._undoStack.push({
            scenes: deepClone(this.data.scenes),
            voiceovers: deepClone(this.data.voiceovers),
            timestamp: Date.now(),
        });

        const snapshot = this._redoStack.pop();
        this.data.scenes = snapshot.scenes;
        this.data.voiceovers = snapshot.voiceovers;

        this.scheduleSave();
        return true;
    }

    // -------------------------------------------------------------------------
    // Selection
    // -------------------------------------------------------------------------

    /**
     * Select an item on the timeline.
     *
     * @param {string} type - 'clip' | 'voiceover' | 'overlay'
     * @param {number|string} id
     */
    select(type, id) {
        this.selectedItem = { type, id };
    }

    /**
     * Clear the current selection.
     */
    deselect() {
        this.selectedItem = null;
    }

    /**
     * Check whether a specific item is currently selected.
     *
     * @param {string} type
     * @param {number|string} id
     * @returns {boolean}
     */
    isSelected(type, id) {
        if (!this.selectedItem) {
            return false;
        }
        return this.selectedItem.type === type && this.selectedItem.id === id;
    }

    // -------------------------------------------------------------------------
    // Debounced Save
    // -------------------------------------------------------------------------

    /**
     * Schedules a debounced save.  Called automatically after every mutation.
     * Collects a flat array of all clips across all scenes plus voiceovers
     * and passes them to the `onSave` callback.
     */
    scheduleSave() {
        if (this._saveTimer) {
            clearTimeout(this._saveTimer);
        }

        this._saveTimer = setTimeout(() => {
            this._saveTimer = null;
            this._executeSave();
        }, SAVE_DEBOUNCE_MS);
    }

    /**
     * Immediately executes the save callback with the current state.
     *
     * @private
     */
    _executeSave() {
        if (!this._onSave || !this.data) {
            return;
        }

        // Flatten clips from all scenes.
        const clips = [];
        for (const scene of this.data.scenes) {
            if (scene.clips) {
                for (const clip of scene.clips) {
                    clips.push({ ...clip, scene_id: scene.id });
                }
            }
        }

        const voiceovers = this.data.voiceovers
            ? this.data.voiceovers.map((vo) => ({ ...vo }))
            : [];

        this._onSave({ clips, voiceovers });
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Finds a clip by ID across all scenes.
     *
     * @private
     * @param {number|string} clipId
     * @returns {Clip|null}
     */
    _findClip(clipId) {
        if (!this.data) {
            return null;
        }
        for (const scene of this.data.scenes) {
            const clip = (scene.clips || []).find((c) => c.id === clipId);
            if (clip) {
                return clip;
            }
        }
        return null;
    }

    /**
     * Finds the scene that contains a given clip.
     *
     * @private
     * @param {number|string} clipId
     * @returns {Scene|null}
     */
    _findSceneContainingClip(clipId) {
        if (!this.data) {
            return null;
        }
        for (const scene of this.data.scenes) {
            if ((scene.clips || []).some((c) => c.id === clipId)) {
                return scene;
            }
        }
        return null;
    }

    /**
     * Finds a voiceover by ID.
     *
     * @private
     * @param {number|string} voId
     * @returns {Voiceover|null}
     */
    _findVoiceover(voId) {
        if (!this.data || !this.data.voiceovers) {
            return null;
        }
        return this.data.voiceovers.find((v) => v.id === voId) || null;
    }

    /**
     * Finds an overlay by ID across all scenes.
     *
     * @private
     * @param {number|string} overlayId
     * @returns {Overlay|null}
     */
    _findOverlay(overlayId) {
        if (!this.data) {
            return null;
        }
        for (const scene of this.data.scenes) {
            const overlay = (scene.overlays || []).find(
                (o) => o.id === overlayId,
            );
            if (overlay) {
                return overlay;
            }
        }
        return null;
    }

    /**
     * Finds an item by type and ID.
     *
     * @private
     * @param {string} type - 'clip' | 'voiceover' | 'overlay'
     * @param {number|string} id
     * @returns {Object|null}
     */
    _findItem(type, id) {
        switch (type) {
            case 'clip':
                return this._findClip(id);
            case 'voiceover':
                return this._findVoiceover(id);
            case 'overlay':
                return this._findOverlay(id);
            default:
                return null;
        }
    }

    /**
     * Checks whether two items (clips or voiceovers) can be merged.
     * They must share the same source and be contiguous within the tolerance.
     *
     * For clips: same source_type + source_id, and a.out_point_ms ~ b.in_point_ms.
     * For voiceovers: same recording_id, and a.out_point_ms ~ b.in_point_ms.
     *
     * @private
     * @param {Object} a - The earlier item.
     * @param {Object} b - The later item.
     * @returns {boolean}
     */
    _canMerge(a, b) {
        // Determine whether the two items refer to the same source.
        let sameSource = false;
        if ('source_type' in a && 'source_id' in a) {
            // Clip comparison.
            sameSource =
                a.source_type === b.source_type && a.source_id === b.source_id;
        } else if ('recording_id' in a) {
            // Voiceover comparison.
            sameSource = a.recording_id === b.recording_id;
        }

        if (!sameSource) {
            return false;
        }

        // Check contiguity: a's out point should be near b's in point.
        const aOut = a.out_point_ms || 0;
        const bIn = b.in_point_ms || 0;

        return Math.abs(aOut - bIn) <= MERGE_TOLERANCE_MS;
    }
}
