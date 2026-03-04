/**
 * Studio Editor — Alpine.data('studioEditor') registration
 *
 * Wires together: PlaybackEngine, TimelineStore, AudioMixer, TimelineUI, OverlayCanvas
 * Imported via @vite('resources/js/studio/index.js') in the editor blade template.
 */
import PlaybackEngine from './PlaybackEngine.js';
import TimelineStore from './TimelineStore.js';
import AudioMixer from './AudioMixer.js';
import OverlayCanvas from './OverlayCanvas.js';

/**
 * Create the studioEditor Alpine data factory.
 * Exported as a global so the Blade component can register it.
 */
function createStudioEditorFactory() {
    return (config) => ({
        // --- State ---
        isPlaying: false,
        isRecording: false,
        recordingProgress: 0,
        recordingError: null,
        activeRecorder: null,
        activeCheckInterval: null,

        // Scene-level display state
        sceneTimeMs: 0,
        sceneDurationMs: 0,
        scenePlaybackPercent: 0,
        currentTimeMs: 0,
        totalDurationMs: 0,
        playbackPercent: 0,

        // Timeline
        timelineZoom: 1,
        selectedItem: null,
        editInMs: 0,
        editOutMs: 0,
        editStartMs: 0,
        editSplitMs: 0,
        saveTimerActive: false,

        // Audio state (used by template)
        gainNodes: {},
        audioLeveled: false,

        // Reactive tick counter — incremented to force Alpine re-evaluation
        // after store mutations that don't go through Alpine's proxy system
        _storeTick: 0,

        // Internal modules (not reactive)
        _engine: null,
        _store: null,
        _mixer: null,
        _timelineUI: null, // deprecated — drag/trim handled inline via Alpine
        _overlayCanvas: null,
        _playAllActive: false,
        _playAllSceneElements: {},

        get projectData() {
            return this._store?.data ?? config.projectData;
        },

        get activeSceneData() {
            return this._store?.getActiveScene() ?? null;
        },

        get undoStack() {
            return this._store?._undoStack ?? [];
        },

        get redoStack() {
            return this._store?._redoStack ?? [];
        },

        // ==================== INIT ====================

        init() {
            // --- TimelineStore ---
            this._store = new TimelineStore({
                onSave: ({ clips, voiceovers }) => {
                    this.saveTimerActive = true;
                    this.$wire.saveTimelineState(clips, voiceovers).then(() => {
                        this.saveTimerActive = false;
                        this.$nextTick(() => this._refreshFromServer());
                    });
                },
            });
            this._store.load(config.projectData);
            this._store.activeSceneId = this.$wire.activeSceneId;

            // --- PlaybackEngine ---
            this._engine = new PlaybackEngine({
                onFrame: (frame) => {
                    this.currentTimeMs = frame.globalTimeMs;
                    this.sceneTimeMs = frame.sceneLocalTimeMs;
                    this.sceneDurationMs = frame.sceneDurationMs;
                    this.scenePlaybackPercent = frame.sceneDurationMs > 0
                        ? (frame.sceneLocalTimeMs / frame.sceneDurationMs) * 100
                        : 0;
                    this.playbackPercent = this._engine.getTotalDurationMs() > 0
                        ? (frame.globalTimeMs / this._engine.getTotalDurationMs()) * 100
                        : 0;

                    // Sync video element positions
                    this._syncVideoPositions(frame.sceneLocalTimeMs);

                    // Sync voiceover playback
                    this._mixer?.syncVoiceovers(
                        this._store.data?.voiceovers ?? [],
                        frame.globalTimeMs
                    );

                    // Update overlay visibility
                    this._overlayCanvas?.updateVisibility(frame.sceneLocalTimeMs);

                    // Playhead is driven reactively via getScenePlayheadPercent()
                },
                onSceneChange: (newIndex, prevIndex) => {
                    const scenes = this._store.data?.scenes ?? [];
                    if (scenes[newIndex]) {
                        this.$wire.activeSceneId = scenes[newIndex].id;
                        this._store.activeSceneId = scenes[newIndex].id;

                        if (this._playAllActive) {
                            this._showPlayAllScene(scenes[newIndex].id);
                            this._startPlayAllSceneVideos(scenes[newIndex].id);
                        }
                    }
                },
                onEnd: () => {
                    this.pause();
                    this.sceneTimeMs = 0;
                    this.scenePlaybackPercent = 0;
                    this.currentTimeMs = 0;
                    this.playbackPercent = 0;
                },
            });
            this._engine.load(this._store.data?.scenes ?? []);
            this.totalDurationMs = this._engine.getTotalDurationMs();

            // --- AudioMixer ---
            this._mixer = new AudioMixer();

            // --- Watch active scene changes ---
            this.$wire.$watch('activeSceneId', () => {
                this._store.activeSceneId = this.$wire.activeSceneId;
                this._store.deselect();
                this.selectedItem = null;

                if (!this._playAllActive) {
                    this.sceneTimeMs = 0;
                    this.scenePlaybackPercent = 0;
                }

                this.$nextTick(() => {
                    this._preloadVideos();
                    this._initOverlayCanvas();
                    this._computeSceneDuration();
                });
            });

            // --- Livewire commit hook ---
            Livewire.hook('commit', ({ succeed }) => {
                succeed(() => {
                    this.$nextTick(() => {
                        this._refreshFromServer();
                        this._preloadVideos();
                    });
                });
            });

            // --- Keyboard shortcuts ---
            this._keyHandler = (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
                    e.preventDefault();
                    this.undo();
                } else if ((e.ctrlKey || e.metaKey) && e.key === 'z' && e.shiftKey) {
                    e.preventDefault();
                    this.redo();
                } else if ((e.ctrlKey || e.metaKey) && e.key === 'y') {
                    e.preventDefault();
                    this.redo();
                } else if (e.key === 's' && !e.ctrlKey && !e.metaKey && this.selectedItem) {
                    e.preventDefault();
                    this.splitSelectedItemLocal();
                } else if ((e.key === 'Delete' || e.key === 'Backspace') && this.selectedItem) {
                    e.preventDefault();
                    this.deleteItemLocal();
                }
            };
            document.addEventListener('keydown', this._keyHandler);

            // --- Initial setup ---
            this.$nextTick(() => {
                this._preloadVideos();
                this._initOverlayCanvas();
                this._computeSceneDuration();
            });
        },

        destroy() {
            this._engine?.destroy();
            this._store = null;
            this._mixer?.destroy();
            this._overlayCanvas?.destroy();
            if (this._keyHandler) {
                document.removeEventListener('keydown', this._keyHandler);
            }
        },

        // ==================== PLAYBACK ====================

        playScene(fromStart = false) {
            if (this._playAllActive) {
                this._teardownPlayAll();
            }

            this.isPlaying = true;
            this._playAllActive = false;
            this._computeSceneDuration();
            this._overlayCanvas?.setInteractive(false);

            // Find active scene index
            const scenes = this._store.data?.scenes ?? [];
            const activeId = this._store.activeSceneId;
            const sceneIndex = scenes.findIndex(s => s.id === activeId);
            if (sceneIndex === -1) return;

            const fromMs = fromStart ? 0 : this.sceneTimeMs;
            const globalMs = this._sceneLocalToGlobal(sceneIndex, fromMs);

            this._engine.load(scenes);
            this._engine.play(globalMs);

            // Connect and play videos
            this._ensureAudioConnections();
            const container = this.$refs.compositionContainer || this.$el;
            const videos = container.querySelectorAll('video[data-clip-id]');
            const seekSec = fromMs / 1000;
            videos.forEach(v => {
                if (v.duration && isFinite(v.duration)) {
                    v.currentTime = fromStart ? 0 : Math.min(seekSec, v.duration);
                }
                v.muted = false;
                const p = v.play();
                if (p) p.catch(() => {
                    v.muted = true;
                    v.play().then(() => { v.muted = false; }).catch(() => {});
                });
            });

            // Start voiceovers
            this._mixer.playVoiceovers(this._store.data?.voiceovers ?? [], globalMs);
        },

        playAll() {
            const scenes = this._store.data?.scenes ?? [];
            if (scenes.length === 0) return;

            this.isPlaying = true;
            this._playAllActive = true;

            // Pause single-scene videos
            const singleSceneVideos = this.$refs.singleSceneContainer?.querySelectorAll('video') || [];
            singleSceneVideos.forEach(v => v.pause());

            // Build client-side scene elements
            this._buildPlayAllScenes();

            // Show playAll container, hide single scene
            if (this.$refs.singleSceneContainer) this.$refs.singleSceneContainer.style.display = 'none';
            if (this.$refs.playAllContainer) this.$refs.playAllContainer.style.display = '';

            const firstScene = scenes[0];
            this._showPlayAllScene(firstScene.id);

            this._engine.load(scenes);
            this.totalDurationMs = this._engine.getTotalDurationMs();
            this._overlayCanvas?.setInteractive(false);

            // Start first scene videos immediately (user gesture context)
            const firstEl = this._playAllSceneElements[firstScene.id];
            let started = false;
            const startPlayback = () => {
                if (started) return;
                started = true;
                this._engine.play(0);
                this._mixer.playVoiceovers(this._store.data?.voiceovers ?? [], 0);
            };

            if (firstEl && firstEl.videos.length > 0) {
                firstEl.videos[0].addEventListener('playing', startPlayback, { once: true });
                firstEl.videos.forEach(v => {
                    v.currentTime = 0;
                    v.muted = false;
                    v.play().catch(() => {
                        v.muted = true;
                        v.play().then(() => { v.muted = false; }).catch(() => {});
                    });
                });
                setTimeout(startPlayback, 500); // Safety fallback
            } else {
                startPlayback();
            }
        },

        pause() {
            this.isPlaying = false;
            this._engine?.pause();

            if (this._playAllActive) {
                this._teardownPlayAll();
            }

            this.$el.querySelectorAll('video[data-clip-id]').forEach(v => v.pause());
            this._mixer?.pauseVoiceovers();
            this._overlayCanvas?.setInteractive(true);
        },

        play() {
            this.playScene();
        },

        seekToPercent(event) {
            const rect = event.currentTarget.getBoundingClientRect();
            const percent = Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width));

            this.sceneTimeMs = Math.floor(percent * this.sceneDurationMs);
            this.scenePlaybackPercent = percent * 100;

            const scenes = this._store.data?.scenes ?? [];
            const activeId = this._store.activeSceneId;
            const sceneIndex = scenes.findIndex(s => s.id === activeId);
            const globalMs = sceneIndex >= 0 ? this._sceneLocalToGlobal(sceneIndex, this.sceneTimeMs) : this.sceneTimeMs;

            this.currentTimeMs = globalMs;
            this.playbackPercent = this.totalDurationMs > 0 ? (globalMs / this.totalDurationMs) * 100 : 0;

            this._engine?.seek(globalMs);
            this._syncVideoPositions(this.sceneTimeMs);
        },

        seekOnTimeline(event) {
            const track = event.currentTarget;
            const rect = track.getBoundingClientRect();
            const percent = Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width));
            const timeMs = Math.floor(percent * this.getTimelineDurationMs());

            this.sceneTimeMs = timeMs;
            this.scenePlaybackPercent = this.sceneDurationMs > 0 ? (timeMs / this.sceneDurationMs) * 100 : 0;

            this._syncVideoPositions(timeMs);
        },

        // ==================== TIMELINE GETTERS (delegated to store) ====================

        getActiveSceneClips() {
            void this._storeTick; // Alpine reactivity dependency
            return this._store?.getActiveSceneClips() ?? [];
        },

        getActiveSceneOverlays(type) {
            void this._storeTick;
            return this._store?.getActiveSceneOverlays(type) ?? [];
        },

        getTimelineDurationMs() {
            void this._storeTick;
            return this._store?.getTimelineDurationMs() ?? 5000;
        },

        getClipDurationMs(clip) {
            void this._storeTick;
            return this._store?.getClipDurationMs(clip) ?? 5000;
        },

        getClipOutMs(clip) {
            void this._storeTick;
            if (clip.out_point_ms) return clip.out_point_ms;
            return (clip.duration_seconds || 5) * 1000;
        },

        getClipLeft(clip) {
            void this._storeTick;
            return this._store?.getClipLeft(clip) ?? 0;
        },

        getClipWidth(clip) {
            void this._storeTick;
            return this._store?.getClipWidth(clip) ?? 100;
        },

        getClipLabel(clip) {
            return clip.source_title || 'Clip';
        },

        getOverlayLeft(overlay) {
            void this._storeTick;
            return this._store?.getOverlayLeft(overlay) ?? 0;
        },

        getOverlayWidth(overlay) {
            void this._storeTick;
            return this._store?.getOverlayWidth(overlay) ?? 100;
        },

        getVoiceoverDurationMs(vo) {
            void this._storeTick;
            return this._store?.getVoiceoverDurationMs(vo) ?? 5000;
        },

        getVoiceoverOutMs(vo) {
            void this._storeTick;
            if (vo.out_point_ms) return vo.out_point_ms;
            return (vo.duration_seconds || 5) * 1000;
        },

        getVoiceoverLeft(vo) {
            void this._storeTick;
            return this._store?.getVoiceoverLeft(vo) ?? 0;
        },

        getVoiceoverWidth(vo) {
            void this._storeTick;
            return this._store?.getVoiceoverWidth(vo) ?? 50;
        },

        getScenePlayheadPercent() {
            return this.sceneDurationMs > 0
                ? Math.min(100, (this.sceneTimeMs / this.sceneDurationMs) * 100)
                : 0;
        },

        getSceneLocalTimeMs() {
            return this.sceneTimeMs;
        },

        formatTime(ms) {
            const totalSec = Math.floor(ms / 1000);
            const min = Math.floor(totalSec / 60);
            const sec = totalSec % 60;
            return `${min}:${sec.toString().padStart(2, '0')}`;
        },

        // ==================== SELECTION ====================

        selectTimelineItem(type, id, inMs, outMs, startMs = 0) {
            if (this.selectedItem && this.selectedItem.type === type && this.selectedItem.id === id) {
                this.selectedItem = null;
                this._store?.deselect();
                return;
            }
            this.selectedItem = { type, id };
            this._store?.select(type, id);
            this.editInMs = inMs || 0;
            this.editOutMs = outMs || 0;
            this.editStartMs = startMs || 0;
            this.editSplitMs = Math.round(((inMs || 0) + (outMs || 0)) / 2);
        },

        deselectTimelineItem() {
            this.selectedItem = null;
            this._store?.deselect();
        },

        isSelected(type, id) {
            return this.selectedItem && this.selectedItem.type === type && this.selectedItem.id === id;
        },

        // ==================== CLIENT-SIDE EDITS ====================

        applyTrimLocal() {
            if (!this.selectedItem) return;
            const { type, id } = this.selectedItem;
            const inMs = Math.max(0, Math.round(this.editInMs));
            const outMs = Math.max(inMs + 100, Math.round(this.editOutMs));

            if (type === 'clip') {
                this._store.trimClip(id, inMs, outMs);
            } else if (type === 'voiceover') {
                this._store.trimVoiceover(id, inMs, outMs);
                const vo = (this._store.data?.voiceovers ?? []).find(v => v.id === id);
                if (vo) vo.start_ms = Math.max(0, Math.round(this.editStartMs));
                this._store.scheduleSave();
            } else if (type === 'overlay') {
                this.$wire.updateOverlay(id, { start_ms: inMs, end_ms: outMs });
            }

            this.selectedItem = null;
        },

        splitSelectedItemLocal() {
            if (!this.selectedItem || !this._store) return;
            const { type, id } = this.selectedItem;
            const splitAt = Math.round(this.editSplitMs);

            if (splitAt <= this.editInMs || splitAt >= this.editOutMs) return;

            if (type === 'clip') {
                this._store.splitClip(id, splitAt);
            } else if (type === 'voiceover') {
                this._store.splitVoiceover(id, splitAt);
            }

            this.selectedItem = null;
        },

        canMergeSelected() {
            if (!this.selectedItem || !this._store) return false;
            const { type, id } = this.selectedItem;
            if (type !== 'clip' && type !== 'voiceover') return false;

            if (type === 'clip') {
                const scene = this._store.getActiveScene();
                if (!scene) return false;
                const clips = scene.clips || [];
                const idx = clips.findIndex(c => c.id === id);
                if (idx === -1) return false;
                if (idx + 1 < clips.length && this._store._canMerge(clips[idx], clips[idx + 1])) return true;
                if (idx - 1 >= 0 && this._store._canMerge(clips[idx - 1], clips[idx])) return true;
            } else if (type === 'voiceover') {
                const voiceovers = this._store.data?.voiceovers ?? [];
                const idx = voiceovers.findIndex(v => v.id === id);
                if (idx === -1) return false;
                if (idx + 1 < voiceovers.length && this._store._canMerge(voiceovers[idx], voiceovers[idx + 1])) return true;
                if (idx - 1 >= 0 && this._store._canMerge(voiceovers[idx - 1], voiceovers[idx])) return true;
            }

            return false;
        },

        mergeSelectedLocal() {
            if (!this.selectedItem || !this._store) return;
            const { type, id } = this.selectedItem;

            if (type === 'clip') {
                this._store.mergeClips(id);
            } else if (type === 'voiceover') {
                this._store.mergeVoiceovers(id);
            }

            this.selectedItem = null;
        },

        deleteItemLocal() {
            if (!this.selectedItem) return;
            const { type, id } = this.selectedItem;

            if (type === 'clip') {
                this._store?.deleteClip(id);
            } else if (type === 'voiceover') {
                this._store?.deleteVoiceover(id);
            } else if (type === 'overlay') {
                this.$wire.removeOverlay(id);
            }

            this.selectedItem = null;
        },

        // ==================== UNDO / REDO ====================

        undo() {
            this._store?.undo();
            this.selectedItem = null;
        },

        redo() {
            this._store?.redo();
            this.selectedItem = null;
        },

        // ==================== OVERLAY CANVAS ====================

        startOverlayDrag(event, overlayId, currentX, currentY) {
            // Legacy handler — in new version, Fabric.js handles drag directly
            // Kept for backward compatibility with server-rendered overlays
            event.preventDefault();
            const dragState = { id: overlayId, el: event.currentTarget };
            const startX = event.clientX;
            const startY = event.clientY;

            const onMove = (e) => {
                const container = this.$refs.compositionContainer;
                if (!container) return;
                const rect = container.getBoundingClientRect();
                const deltaX = (e.clientX - startX) / rect.width;
                const deltaY = (e.clientY - startY) / rect.height;
                const newX = Math.max(0, Math.min(1, currentX + deltaX));
                const newY = Math.max(0, Math.min(1, currentY + deltaY));
                dragState.el.style.left = (newX * 100) + '%';
                dragState.el.style.top = (newY * 100) + '%';
                dragState.newX = newX;
                dragState.newY = newY;
            };

            const onUp = () => {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                if (dragState.newX !== undefined) {
                    this.$wire.updateOverlay(overlayId, {
                        position_x: Math.round(dragState.newX * 10000) / 10000,
                        position_y: Math.round(dragState.newY * 10000) / 10000,
                    });
                }
            };

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        },

        // ==================== PLAYALL SCENE MANAGEMENT ====================

        _buildPlayAllScenes() {
            const container = this.$refs.playAllContainer;
            if (!container) return;
            container.innerHTML = '';
            this._playAllSceneElements = {};

            const scenes = this._store.data?.scenes ?? [];
            scenes.forEach((scene, idx) => {
                const sceneDiv = document.createElement('div');
                sceneDiv.className = 'absolute inset-0';
                sceneDiv.style.display = idx === 0 ? '' : 'none';
                sceneDiv.dataset.sceneId = scene.id;
                sceneDiv.style.backgroundColor = scene.background_color || '#000000';

                const videos = [];

                (scene.clips || []).forEach(clip => {
                    const url = clip.stream_url || clip.url;
                    if (!url) return;

                    const slotDiv = document.createElement('div');
                    slotDiv.className = this._getSlotClasses(scene.layout, clip.slot);

                    const video = document.createElement('video');
                    video.src = url;
                    if (clip.thumbnail) video.poster = clip.thumbnail;
                    video.dataset.clipId = clip.id;
                    video.muted = true;
                    video.playsInline = true;
                    video.preload = 'auto';
                    const fitClass = clip.fit_mode === 'contain' ? 'object-contain' : (clip.fit_mode === 'fill' ? 'object-fill' : 'object-cover');
                    video.className = `w-full h-full ${fitClass}`;

                    slotDiv.appendChild(video);
                    sceneDiv.appendChild(slotDiv);
                    videos.push(video);
                });

                // Overlay elements for playAll
                const overlayEls = [];
                (scene.overlays || []).forEach(overlay => {
                    if (!['text', 'caption'].includes(overlay.type)) return;
                    const overlayDiv = document.createElement('div');
                    overlayDiv.className = 'absolute z-20 select-none pointer-events-none';
                    overlayDiv.style.left = (overlay.position_x * 100) + '%';
                    overlayDiv.style.top = (overlay.position_y * 100) + '%';
                    if (overlay.width) overlayDiv.style.width = (overlay.width * 100) + '%';
                    overlayDiv.style.display = 'none';

                    const style = overlay.style || {};
                    const span = document.createElement('span');
                    span.className = 'inline-block' + (overlay.width ? ' w-full text-center' : '');
                    span.style.fontSize = (style.font_size || 24) + 'px';
                    span.style.color = style.color || '#FFFFFF';
                    span.style.fontWeight = style.font_weight || 'normal';
                    if (style.bg_color) {
                        span.style.backgroundColor = style.bg_color;
                        span.style.padding = '4px 8px';
                        span.style.borderRadius = '4px';
                    }
                    span.textContent = overlay.content || '';

                    overlayDiv.appendChild(span);
                    sceneDiv.appendChild(overlayDiv);
                    overlayEls.push({ el: overlayDiv, start_ms: overlay.start_ms, end_ms: overlay.end_ms });
                });

                container.appendChild(sceneDiv);
                this._playAllSceneElements[scene.id] = { container: sceneDiv, videos, overlayEls };
            });

            // Preload all
            Object.values(this._playAllSceneElements).forEach(({ videos }) => {
                videos.forEach(v => v.load());
            });
        },

        _showPlayAllScene(sceneId) {
            Object.entries(this._playAllSceneElements).forEach(([id, { container, videos }]) => {
                const isActive = parseInt(id) === sceneId;
                container.style.display = isActive ? '' : 'none';
                if (!isActive) videos.forEach(v => v.pause());
            });
        },

        _startPlayAllSceneVideos(sceneId) {
            const sceneEl = this._playAllSceneElements[sceneId];
            if (!sceneEl) return;
            sceneEl.videos.forEach(v => {
                v.currentTime = 0;
                v.muted = false;
                v.play().catch(() => {
                    v.muted = true;
                    v.play().then(() => { v.muted = false; }).catch(() => {});
                });
            });
        },

        _teardownPlayAll() {
            Object.values(this._playAllSceneElements).forEach(({ videos }) => {
                videos.forEach(v => { v.pause(); v.src = ''; });
            });
            const container = this.$refs.playAllContainer;
            if (container) {
                container.innerHTML = '';
                container.style.display = 'none';
            }
            this._playAllSceneElements = {};
            this._playAllActive = false;

            const singleScene = this.$refs.singleSceneContainer;
            if (singleScene) singleScene.style.display = '';
        },

        // ==================== RECORDING ====================

        async startFinalRecording() {
            if (this.isRecording) return;
            this.isRecording = true;
            this.recordingProgress = 0;
            this.recordingError = null;

            try {
                const canvas = document.createElement('canvas');
                canvas.width = this._store.data?.resolution_width || 1920;
                canvas.height = this._store.data?.resolution_height || 1080;
                const ctx = canvas.getContext('2d');

                const videoStream = canvas.captureStream(30);
                const mimeType = (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus'))
                    ? 'video/webm;codecs=vp9,opus'
                    : 'video/webm';

                const recorder = new MediaRecorder(videoStream, {
                    mimeType,
                    videoBitsPerSecond: 5000000,
                });

                const startResponse = await fetch('/api/video-recordings/start', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': config.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        title: (this._store.data?.title || 'Project') + ' - Rendered',
                        mime_type: mimeType,
                    }),
                });

                if (!startResponse.ok) throw new Error('Failed to start upload session');
                const { id: recordingId } = await startResponse.json();

                let chunkIndex = 0;
                recorder.ondataavailable = async (e) => {
                    if (e.data.size > 0) {
                        const formData = new FormData();
                        formData.append('chunk', e.data, `chunk_${chunkIndex}.webm`);
                        formData.append('chunk_index', chunkIndex);
                        chunkIndex++;
                        try {
                            await fetch(`/api/video-recordings/${recordingId}/chunk`, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': config.csrfToken, 'Accept': 'application/json' },
                                body: formData,
                            });
                        } catch (err) {
                            console.error('Chunk upload failed:', err);
                        }
                    }
                };

                let animationId;
                const renderFrame = () => {
                    if (!this.isRecording) return;
                    this._compositeToCanvas(ctx, canvas);
                    animationId = requestAnimationFrame(renderFrame);
                };

                recorder.onstop = async () => {
                    cancelAnimationFrame(animationId);
                    await fetch(`/api/video-recordings/${recordingId}/finalize`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrfToken, 'Accept': 'application/json' },
                    });
                    this.$wire.linkRenderedVideo(recordingId);
                    this.isRecording = false;
                    this.recordingProgress = 100;
                };

                const totalDuration = this._engine.getTotalDurationMs();
                const startTime = Date.now();
                this.activeCheckInterval = setInterval(() => {
                    const elapsed = Date.now() - startTime;
                    this.recordingProgress = Math.min(99, Math.round((elapsed / totalDuration) * 100));
                    if (elapsed >= totalDuration) {
                        clearInterval(this.activeCheckInterval);
                        this.activeCheckInterval = null;
                        recorder.stop();
                    }
                }, 100);

                recorder.start(1000);
                this.playAll();
                renderFrame();
                this.activeRecorder = recorder;
            } catch (error) {
                console.error('Recording failed:', error);
                this.isRecording = false;
                this.recordingError = error.message;
            }
        },

        stopFinalRecording() {
            if (this.activeRecorder && this.activeRecorder.state !== 'inactive') {
                this.activeRecorder.stop();
            }
            if (this.activeCheckInterval) {
                clearInterval(this.activeCheckInterval);
                this.activeCheckInterval = null;
            }
            this.pause();
        },

        // ==================== AUDIO ====================

        autoLevelAudio() {
            this._mixer?.autoLevel();
            this.audioLeveled = true;
        },

        // ==================== TIMELINE ZOOM / SCROLL ====================

        handleTimelineScroll(event) {
            if (event.ctrlKey || event.metaKey) {
                const delta = event.deltaY > 0 ? -0.5 : 0.5;
                this.timelineZoom = Math.max(1, Math.min(10, this.timelineZoom + delta));
                // Zoom is reactive via timelineZoom property
            } else {
                const wrapper = this.$refs.tracksWrapper;
                if (wrapper) wrapper.scrollLeft += event.deltaY;
            }
        },

        // ==================== PRIVATE HELPERS ====================

        _refreshFromServer() {
            if (!this._store) return;
            const source = this.$refs.projectDataSource;
            if (source && source.dataset.projectJson) {
                try {
                    const data = JSON.parse(source.dataset.projectJson);
                    if (data && Array.isArray(data.scenes)) {
                        this._store.refresh(data);
                        this._engine?.load(data.scenes);
                        this.totalDurationMs = this._engine?.getTotalDurationMs() ?? 0;
                        this._computeSceneDuration();
                    }
                } catch (e) {
                    console.warn('Studio: Failed to parse project data', e);
                }
            }
        },

        _preloadVideos() {
            const videos = this.$el.querySelectorAll('video[data-clip-id]');
            videos.forEach(v => {
                if (v.readyState === 0 && v.src) v.load();
            });
        },

        handleVideoError(event) {
            const video = event.target;
            const fallbackUrl = video.dataset.fallbackUrl;
            if (fallbackUrl && video.src !== fallbackUrl) {
                video.src = fallbackUrl;
                video.load();
            }
        },

        _computeSceneDuration() {
            const scene = this._store?.getActiveScene();
            let dur = scene ? (scene.duration_ms || 5000) : 5000;

            // Check actual video element durations
            const container = this.$refs.compositionContainer || this.$el;
            if (container) {
                const videos = container.querySelectorAll('video[data-clip-id]');
                videos.forEach(v => {
                    if (v.duration && isFinite(v.duration)) {
                        dur = Math.max(dur, Math.ceil(v.duration * 1000));
                    }
                });
            }

            this.sceneDurationMs = dur;
        },

        _syncVideoPositions(sceneLocalTimeMs) {
            if (this._playAllActive) {
                // During playAll, sync the active scene's videos
                const scenes = this._store.data?.scenes ?? [];
                const activeScene = scenes[this._engine?.activeSceneIndex ?? 0];
                if (activeScene) {
                    const sceneEl = this._playAllSceneElements[activeScene.id];
                    if (sceneEl) {
                        const seekSec = sceneLocalTimeMs / 1000;
                        sceneEl.videos.forEach(v => {
                            if (v.duration && isFinite(v.duration)) {
                                v.currentTime = Math.min(seekSec, v.duration);
                            }
                        });
                    }

                    // Update playAll overlay visibility
                    if (sceneEl?.overlayEls) {
                        sceneEl.overlayEls.forEach(({ el, start_ms, end_ms }) => {
                            if (start_ms == null && end_ms == null) {
                                el.style.display = '';
                                return;
                            }
                            const visible = (start_ms == null || sceneLocalTimeMs >= start_ms) && (end_ms == null || sceneLocalTimeMs <= end_ms);
                            el.style.display = visible ? '' : 'none';
                        });
                    }
                }
                return;
            }

            // Single scene mode
            const container = this.$refs.compositionContainer || this.$el;
            const videos = container.querySelectorAll('video[data-clip-id]');
            const seekSec = sceneLocalTimeMs / 1000;
            videos.forEach(v => {
                if (v.duration && isFinite(v.duration) && !this.isPlaying) {
                    v.currentTime = Math.min(seekSec, v.duration);
                }
            });
        },

        _ensureAudioConnections() {
            this._mixer?.ensureContext();
            const container = this.$refs.compositionContainer || this.$el;
            const videos = container.querySelectorAll('video[data-clip-id]');
            const scene = this._store?.getActiveScene();

            videos.forEach(v => {
                const clipId = v.dataset.clipId;
                if (!clipId) return;
                const clip = (scene?.clips ?? []).find(c => String(c.id) === String(clipId));
                const volume = (clip?.volume ?? 100) / 100;
                this._mixer.connectVideo(v, clipId, volume);
                this.gainNodes[clipId] = true;
            });
        },

        _sceneLocalToGlobal(sceneIndex, localTimeMs) {
            const scenes = this._store.data?.scenes ?? [];
            let accumulated = 0;
            for (let i = 0; i < sceneIndex && i < scenes.length; i++) {
                accumulated += scenes[i].duration_ms || 5000;
            }
            return accumulated + localTimeMs;
        },

        _getSlotClasses(layout, slot) {
            const base = 'absolute overflow-hidden';
            if (layout === 'split') {
                if (slot === 'main') return `${base} left-0 top-0 w-1/2 h-full`;
                if (slot === 'secondary') return `${base} right-0 top-0 w-1/2 h-full`;
            }
            if (layout === 'pip') {
                if (slot === 'main') return `${base} inset-0`;
                if (slot === 'pip') return `${base} right-[4%] bottom-[4%] w-1/4 h-1/4 rounded-lg shadow-lg z-10`;
            }
            return `${base} inset-0`;
        },

        // ==================== ALPINE-NATIVE TIMELINE DRAG & TRIM ====================
        // No interact.js — uses simple mousedown/mousemove/mouseup on Alpine data.
        // All positioning is reactive via getClipLeft()/getClipWidth() from the store.

        _dragState: null,

        /**
         * Start dragging a clip or voiceover horizontally.
         * Called from @mousedown on timeline blocks.
         */
        startDrag(event, type, id) {
            event.preventDefault();
            event.stopPropagation();
            const track = event.target.closest('.flex-1.relative');
            if (!track) return;

            const trackRect = track.getBoundingClientRect();
            const totalMs = this.getTimelineDurationMs();

            this._dragState = { type, id, startX: event.clientX, trackWidth: trackRect.width, totalMs, mode: 'move' };

            // Get current start_ms
            if (type === 'clip') {
                const clip = this.getActiveSceneClips().find(c => c.id === id);
                this._dragState.originalMs = clip?.start_ms || 0;
            } else if (type === 'voiceover') {
                const vo = (this.projectData?.voiceovers || []).find(v => v.id === id);
                this._dragState.originalMs = vo?.start_ms || 0;
            }

            const onMove = (e) => {
                if (!this._dragState) return;
                const dx = e.clientX - this._dragState.startX;
                const deltaMs = (dx / this._dragState.trackWidth) * this._dragState.totalMs;
                const newMs = Math.max(0, Math.round(this._dragState.originalMs + deltaMs));

                if (this._dragState.type === 'clip') {
                    this._store?.moveClip(this._dragState.id, newMs, true);
                } else if (this._dragState.type === 'voiceover') {
                    this._store?.moveVoiceover(this._dragState.id, newMs, true);
                }
                this._storeTick++; // trigger Alpine re-render
            };

            const onUp = () => {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                if (this._dragState) {
                    this._store?.pushUndo();
                    this._store?.scheduleSave();
                    this._dragState = null;
                    this._storeTick++;
                }
            };

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        },

        /**
         * Start trimming a clip or voiceover from left or right edge.
         * Called from @mousedown on .trim-left / .trim-right handles.
         */
        startTrim(event, type, id, edge) {
            event.preventDefault();
            event.stopPropagation();
            const track = event.target.closest('.flex-1.relative');
            if (!track) return;

            const trackRect = track.getBoundingClientRect();
            const totalMs = this.getTimelineDurationMs();

            let originalInMs = 0;
            let originalOutMs = 0;

            if (type === 'clip') {
                const clip = this.getActiveSceneClips().find(c => c.id === id);
                if (!clip) return;
                originalInMs = clip.in_point_ms || 0;
                originalOutMs = this.getClipOutMs(clip);
            } else if (type === 'voiceover') {
                const vo = (this.projectData?.voiceovers || []).find(v => v.id === id);
                if (!vo) return;
                originalInMs = vo.in_point_ms || 0;
                originalOutMs = this.getVoiceoverOutMs(vo);
            }

            const startX = event.clientX;

            const onMove = (e) => {
                const dx = e.clientX - startX;
                const deltaMs = (dx / trackRect.width) * totalMs;

                if (edge === 'left') {
                    const newIn = Math.max(0, Math.round(originalInMs + deltaMs));
                    if (type === 'clip') {
                        this._store?.trimClip(id, newIn, originalOutMs, true);
                    } else {
                        this._store?.trimVoiceover(id, newIn, originalOutMs, true);
                    }
                } else {
                    const newOut = Math.max(originalInMs + 100, Math.round(originalOutMs + deltaMs));
                    if (type === 'clip') {
                        this._store?.trimClip(id, originalInMs, newOut, true);
                    } else {
                        this._store?.trimVoiceover(id, originalInMs, newOut, true);
                    }
                }
                this._storeTick++; // trigger Alpine re-render
            };

            const onUp = () => {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                this._store?.pushUndo();
                this._store?.scheduleSave();
                this._storeTick++;
            };

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        },

        _initOverlayCanvas() {
            // Overlay canvas initialization is optional — only set up if the canvas element exists
            const canvasEl = this.$refs.overlayCanvas;
            if (!canvasEl) return;

            const container = this.$refs.compositionContainer;
            if (!container) return;

            if (!this._overlayCanvas) {
                this._overlayCanvas = new OverlayCanvas({
                    onOverlayUpdate: (overlayId, data) => {
                        this.$wire.updateOverlay(overlayId, data);
                    },
                    onOverlaySelect: (overlayId) => {
                        this.selectTimelineItem('overlay', overlayId, 0, 0);
                    },
                });
            }

            const rect = container.getBoundingClientRect();
            this._overlayCanvas.init(canvasEl, rect.width, rect.height);

            // Load overlays for active scene
            const scene = this._store?.getActiveScene();
            if (scene) {
                this._overlayCanvas.loadOverlays(scene.overlays || []);
            }
        },

        _compositeToCanvas(ctx, canvas) {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            const scene = this._store?.getActiveScene();
            if (!scene) return;

            ctx.fillStyle = scene.background_color || '#000000';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            (scene.clips || []).forEach(clip => {
                const video = this.$el.querySelector(`video[data-clip-id="${clip.id}"]`);
                if (!video || video.readyState < 2) return;

                const slot = this._getSlotPosition(scene.layout, clip.slot);
                const dx = slot.x * canvas.width;
                const dy = slot.y * canvas.height;
                const dw = slot.w * canvas.width;
                const dh = slot.h * canvas.height;

                if (clip.fit_mode === 'contain') {
                    const vRatio = video.videoWidth / video.videoHeight;
                    const sRatio = dw / dh;
                    let sw, sh, sx, sy;
                    if (vRatio > sRatio) {
                        sw = dw; sh = dw / vRatio; sx = dx; sy = dy + (dh - sh) / 2;
                    } else {
                        sh = dh; sw = dh * vRatio; sx = dx + (dw - sw) / 2; sy = dy;
                    }
                    ctx.drawImage(video, sx, sy, sw, sh);
                } else {
                    ctx.drawImage(video, dx, dy, dw, dh);
                }
            });

            (scene.overlays || []).forEach(overlay => {
                const time = this.sceneTimeMs;
                if (overlay.start_ms != null && time < overlay.start_ms) return;
                if (overlay.end_ms != null && time > overlay.end_ms) return;

                const style = overlay.style || {};
                const fontSize = (style.font_size || 24) * (canvas.height / 1080);
                const x = overlay.position_x * canvas.width;
                const y = overlay.position_y * canvas.height;

                ctx.font = `${style.font_weight || 'normal'} ${fontSize}px sans-serif`;

                if (style.bg_color) {
                    const metrics = ctx.measureText(overlay.content || '');
                    const padding = 8 * (canvas.height / 1080);
                    ctx.fillStyle = style.bg_color;
                    ctx.fillRect(x - padding, y - fontSize, metrics.width + padding * 2, fontSize + padding);
                }

                ctx.fillStyle = style.color || '#FFFFFF';
                ctx.textAlign = style.alignment || 'left';
                ctx.fillText(overlay.content || '', x, y);
            });
        },

        _getSlotPosition(layout, slot) {
            if (layout === 'split') {
                if (slot === 'main') return { x: 0, y: 0, w: 0.5, h: 1 };
                if (slot === 'secondary') return { x: 0.5, y: 0, w: 0.5, h: 1 };
            }
            if (layout === 'pip') {
                if (slot === 'main') return { x: 0, y: 0, w: 1, h: 1 };
                if (slot === 'pip') return { x: 0.72, y: 0.72, w: 0.25, h: 0.25 };
            }
            return { x: 0, y: 0, w: 1, h: 1 };
        },
    });
}

/**
 * Voiceover Recorder — Alpine.data('voiceoverRecorder')
 * Handles mic access, MediaRecorder, chunked upload, finalization.
 * Moved here from @script block so it registers before Alpine walks the DOM.
 */
function createVoiceoverRecorderFactory() {
    return (config) => ({
        stage: 'idle',
        audioStream: null,
        mediaRecorder: null,
        analyserNode: null,
        audioContext: null,
        audioLevel: 0,
        recordingSeconds: 0,
        recordingTimer: null,
        levelTimer: null,
        errorMessage: '',
        maxDuration: 300,
        uploadProgressText: '',

        recordingId: null,
        chunkIndex: 0,
        uploadQueue: [],
        isUploading: false,
        failedChunks: 0,
        maxRetries: 3,

        allChunks: [],
        _rawStream: null,

        get recordingTime() {
            const m = Math.floor(this.recordingSeconds / 60);
            const s = this.recordingSeconds % 60;
            return `${m}:${s.toString().padStart(2, '0')}`;
        },

        async requestMicAccess() {
            this.stage = 'requesting';
            this.errorMessage = '';

            try {
                const stream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true,
                        sampleRate: 48000,
                        channelCount: 1,
                    },
                    video: false,
                });

                this._rawStream = stream;
                this.audioStream = stream;

                this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const source = this.audioContext.createMediaStreamSource(this._rawStream);
                this.analyserNode = this.audioContext.createAnalyser();
                this.analyserNode.fftSize = 256;
                source.connect(this.analyserNode);

                this.startLevelMeter();
                this.stage = 'ready';
            } catch (e) {
                console.error('Microphone access failed:', e);
                this.errorMessage = 'Could not access microphone. Please check permissions.';
                this.stage = 'idle';
            }
        },

        startLevelMeter() {
            if (!this.analyserNode) return;
            const dataArray = new Uint8Array(this.analyserNode.frequencyBinCount);

            const update = () => {
                if (!this.analyserNode) return;
                this.analyserNode.getByteFrequencyData(dataArray);
                let sum = 0;
                for (let i = 0; i < dataArray.length; i++) {
                    sum += dataArray[i];
                }
                this.audioLevel = (sum / dataArray.length) / 2.55;
                this.levelTimer = requestAnimationFrame(update);
            };

            update();
        },

        stopLevelMeter() {
            if (this.levelTimer) {
                cancelAnimationFrame(this.levelTimer);
                this.levelTimer = null;
            }
            this.audioLevel = 0;
        },

        getSupportedAudioMimeType() {
            const types = [
                'audio/webm;codecs=opus',
                'audio/webm',
                'audio/ogg;codecs=opus',
                'audio/mp4',
            ];
            for (const type of types) {
                if (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported(type)) {
                    return type;
                }
            }
            return '';
        },

        async startRecording() {
            if (!this._rawStream) return;

            this.recordingSeconds = 0;
            this.chunkIndex = 0;
            this.uploadQueue = [];
            this.isUploading = false;
            this.failedChunks = 0;
            this.recordingId = null;
            this.allChunks = [];

            const mimeType = this.getSupportedAudioMimeType();
            if (!mimeType) {
                this.errorMessage = 'Your browser does not support audio recording.';
                return;
            }

            try {
                const response = await fetch('/api/video-recordings/start', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': config.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        mime_type: mimeType,
                        recordable_type: 'App\\Models\\VideoProject',
                        recordable_id: config.projectId,
                        title: 'Voiceover',
                    }),
                });

                if (!response.ok) throw new Error('Failed to start upload session');

                const data = await response.json();
                this.recordingId = data.id;
            } catch (e) {
                console.error('Failed to start recording session:', e);
                this.errorMessage = 'Failed to start recording. Please try again.';
                return;
            }

            this.mediaRecorder = new MediaRecorder(this._rawStream, {
                mimeType,
                audioBitsPerSecond: 128000,
            });

            this.mediaRecorder.ondataavailable = (e) => {
                if (e.data.size > 0) {
                    this.allChunks.push(e.data);
                    if (this.recordingId) {
                        this.uploadQueue.push(e.data);
                        this.processUploadQueue();
                    }
                }
            };

            this.mediaRecorder.onstop = () => {
                this.finalizeRecording();
            };

            this.mediaRecorder.start(2000);
            this.stage = 'recording';

            this.recordingTimer = setInterval(() => {
                this.recordingSeconds++;
                if (this.recordingSeconds >= this.maxDuration) {
                    this.stopRecording();
                }
            }, 1000);
        },

        async processUploadQueue() {
            if (this.isUploading || this.uploadQueue.length === 0) return;
            this.isUploading = true;

            while (this.uploadQueue.length > 0) {
                const chunk = this.uploadQueue.shift();
                const currentIndex = this.chunkIndex;
                let retries = 0;
                let success = false;

                while (retries < this.maxRetries && !success) {
                    try {
                        const formData = new FormData();
                        formData.append('chunk', chunk, `chunk_${currentIndex}.webm`);
                        formData.append('chunk_index', currentIndex);

                        const response = await fetch(`/api/video-recordings/${this.recordingId}/chunk`, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': config.csrfToken,
                                'Accept': 'application/json',
                            },
                            body: formData,
                        });

                        if (!response.ok) throw new Error('Chunk upload failed');
                        success = true;
                        this.chunkIndex++;
                    } catch (e) {
                        retries++;
                        if (retries < this.maxRetries) {
                            await new Promise(r => setTimeout(r, 500 * retries));
                        }
                    }
                }

                if (!success) {
                    this.failedChunks++;
                    this.chunkIndex++;
                }
            }

            this.isUploading = false;
        },

        stopRecording() {
            if (this.recordingTimer) {
                clearInterval(this.recordingTimer);
                this.recordingTimer = null;
            }

            if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
                if (this.mediaRecorder.state === 'recording') {
                    this.mediaRecorder.requestData();
                }
                this.mediaRecorder.stop();
            }
        },

        async finalizeRecording() {
            this.stage = 'finalizing';
            this.stopLevelMeter();
            this.uploadProgressText = 'Processing audio...';

            let waitMs = 0;
            while ((this.uploadQueue.length > 0 || this.isUploading) && waitMs < 15000) {
                await new Promise(r => setTimeout(r, 200));
                waitMs += 200;
            }

            if (this.failedChunks > 0 || this.allChunks.length === 0) {
                this.uploadProgressText = 'Uploading complete recording...';
                await this.uploadCompleteBlob();
            }

            try {
                this.uploadProgressText = 'Finalizing...';
                const response = await fetch(`/api/video-recordings/${this.recordingId}/finalize`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': config.csrfToken,
                        'Accept': 'application/json',
                    },
                });

                if (!response.ok) throw new Error('Finalize failed');

                Livewire.dispatch('voiceover-finalized', { recordingId: this.recordingId });

                this.cleanupStream();
                this.recordingId = null;
                this.chunkIndex = 0;
                this.recordingSeconds = 0;
                this.allChunks = [];
                this.uploadProgressText = '';
                this.stage = 'idle';
            } catch (e) {
                console.error('Finalize failed:', e);
                this.errorMessage = 'Failed to save voiceover. Please try again.';
                this.cleanupStream();
                this.allChunks = [];
                this.uploadProgressText = '';
                this.stage = 'idle';
            }
        },

        async uploadCompleteBlob() {
            if (this.allChunks.length === 0) return;

            const mimeType = this.getSupportedAudioMimeType() || 'audio/webm';
            const completeBlob = new Blob(this.allChunks, { type: mimeType });

            try {
                const formData = new FormData();
                formData.append('chunk', completeBlob, 'complete_recording.webm');
                formData.append('chunk_index', 0);
                formData.append('replace_all', '1');

                await fetch(`/api/video-recordings/${this.recordingId}/chunk`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': config.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: formData,
                });
            } catch (e) {
                console.error('Complete blob upload error:', e);
            }
        },

        cancelRecorder() {
            this.stopLevelMeter();
            this.cleanupStream();

            if (this.recordingTimer) {
                clearInterval(this.recordingTimer);
                this.recordingTimer = null;
            }

            this.mediaRecorder = null;
            this.recordingId = null;
            this.chunkIndex = 0;
            this.uploadQueue = [];
            this.allChunks = [];
            this.recordingSeconds = 0;
            this.errorMessage = '';
            this.uploadProgressText = '';
            this.stage = 'idle';
        },

        cleanupStream() {
            if (this._rawStream) {
                this._rawStream.getTracks().forEach(t => t.stop());
                this._rawStream = null;
                this.audioStream = null;
            }
            if (this.audioContext) {
                this.audioContext.close().catch(() => {});
                this.audioContext = null;
                this.analyserNode = null;
            }
        },

        destroy() {
            this.stopLevelMeter();
            this.cleanupStream();
            if (this.recordingTimer) {
                clearInterval(this.recordingTimer);
            }
        },
    });
}

/**
 * Quick Creator — Alpine.data('quickCreator') factory.
 * Handles camera recording, PIP mode, media upload, and clip management.
 */
function createQuickCreatorFactory() {
    return () => ({
        activeClipIndex: 0,
        previewUrl: null,
        previewType: null,

        // Recording state
        recordingState: 'idle',
        stream: null,
        mediaRecorder: null,
        recordedChunks: [],
        recordedBlob: null,
        uploadProgress: 0,
        recordingSeconds: 0,
        recordingTimer: null,
        useFrontCamera: true,
        segmentCount: 0,

        // PIP state
        pipMode: false,
        pipBackgroundUrl: null,
        pipBackgroundType: null,

        enterPipMode(url, type) {
            this.pipMode = true;
            this.pipBackgroundUrl = url;
            this.pipBackgroundType = type;
        },

        exitPipMode() {
            this.pipMode = false;
            this.pipBackgroundUrl = null;
            this.pipBackgroundType = null;
        },

        setActiveClip(index, url, type) {
            this.activeClipIndex = index;
            this.previewUrl = url;
            this.previewType = type;
        },

        async startCamera() {
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: this.useFrontCamera ? 'user' : 'environment', width: { ideal: 1280 }, height: { ideal: 720 } },
                    audio: true
                });
                this.recordingState = 'previewing';
                await this.$nextTick();
                const videoEl = this.pipMode ? this.$refs.pipCameraPreview : this.$refs.cameraPreview;
                if (videoEl) videoEl.srcObject = this.stream;
            } catch (e) {
                console.error('Camera access denied:', e);
            }
        },

        flipCamera() {
            this.useFrontCamera = !this.useFrontCamera;
            this.stopCamera();
            this.startCamera();
        },

        startRecording() {
            this.recordedChunks = [];
            const mimeType = this.getSupportedMimeType();
            this.mediaRecorder = new MediaRecorder(this.stream, {
                mimeType: mimeType,
                videoBitsPerSecond: 2500000
            });
            this.mediaRecorder.ondataavailable = (e) => {
                if (e.data.size > 0) this.recordedChunks.push(e.data);
            };
            this.mediaRecorder.onstop = () => {
                this.recordedBlob = new Blob(this.recordedChunks, { type: mimeType });
                this.uploadSegment();
            };
            this.mediaRecorder.start(1000);
            this.recordingState = 'recording';
            this.recordingSeconds = 0;
            this.recordingTimer = setInterval(() => this.recordingSeconds++, 1000);
        },

        stopRecording() {
            if (this.mediaRecorder && this.mediaRecorder.state === 'recording') {
                this.mediaRecorder.stop();
                clearInterval(this.recordingTimer);
            }
        },

        async uploadSegment() {
            this.recordingState = 'uploading';
            this.uploadProgress = 0;
            const formData = new FormData();
            const ext = this.recordedBlob.type.includes('webm') ? 'webm' : 'mp4';
            formData.append('file', this.recordedBlob, `segment-${++this.segmentCount}.${ext}`);
            formData.append('title', `Recording Segment ${this.segmentCount}`);

            const xhr = new XMLHttpRequest();
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) this.uploadProgress = Math.round((e.loaded / e.total) * 100);
            };
            xhr.onload = () => {
                if (xhr.status === 200 || xhr.status === 201) {
                    const response = JSON.parse(xhr.responseText);
                    this.$wire.addRecordedClip(response.id);
                    this.recordingState = 'previewing';
                    this.uploadProgress = 0;
                }
            };
            xhr.onerror = () => {
                console.error('Upload failed');
                this.recordingState = 'previewing';
            };
            xhr.open('POST', '/api/video-recordings');
            xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]').content);
            xhr.send(formData);
        },

        stopCamera() {
            if (this.stream) {
                this.stream.getTracks().forEach(t => t.stop());
                this.stream = null;
            }
            this.recordingState = 'idle';
            clearInterval(this.recordingTimer);
        },

        getSupportedMimeType() {
            const types = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4'];
            return types.find(t => MediaRecorder.isTypeSupported(t)) || 'video/webm';
        },

        formatTime(seconds) {
            const m = Math.floor(seconds / 60);
            const s = seconds % 60;
            return `${m}:${s.toString().padStart(2, '0')}`;
        }
    });
}

// Register with Alpine before it processes the DOM.
// This module is loaded via synchronous `import './studio/index.js'` in app.js,
// so the alpine:init listener is guaranteed to fire before Alpine walks the DOM.
document.addEventListener('alpine:init', () => {
    Alpine.data('studioEditor', createStudioEditorFactory());
    Alpine.data('voiceoverRecorder', createVoiceoverRecorderFactory());
    Alpine.data('quickCreator', createQuickCreatorFactory());
});

export { createStudioEditorFactory, createVoiceoverRecorderFactory, createQuickCreatorFactory };
