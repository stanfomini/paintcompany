<?php

use App\Models\PhotoAnnotation;
use App\Models\PropertyPhoto;
use App\Services\AnnotationService;
use Livewire\Component;

new class extends Component
{
    public PropertyPhoto $photo;

    public ?array $existingCanvasJson = null;

    public string $photoUrl = '';

    public string $authorType = 'internal';

    public string $authorLabel = 'Team';

    public string $authorColor = '#3b82f6';

    public bool $hasExistingAnnotation = false;

    public function mount(PropertyPhoto $photo): void
    {
        $user = auth()->user() ?? auth('customer')->user() ?? auth('agent')->user() ?? auth('crew')->user();
        $this->photo = $photo->load('annotations');
        $this->photoUrl = $photo->url;

        if ($user) {
            $this->authorType = PhotoAnnotation::resolveAuthorType($user);
            $this->authorLabel = PhotoAnnotation::AUTHOR_TYPES[$this->authorType] ?? 'Team';
            $this->authorColor = PhotoAnnotation::AUTHOR_COLORS[$this->authorType] ?? '#3b82f6';

            $existing = $photo->annotationForType($this->authorType);
            $this->existingCanvasJson = $existing?->canvas_json;
            $this->hasExistingAnnotation = $existing !== null;
        }
    }

    private function canAnnotate(): bool
    {
        $user = auth()->user() ?? auth('customer')->user() ?? auth('agent')->user() ?? auth('crew')->user();

        return $user && ($user->can('photos.annotate') || $user->type === 'crew' || $user->type === 'customer');
    }

    public function saveAnnotation(array $canvasJson): void
    {
        if (! $this->canAnnotate()) {
            return;
        }

        $user = auth()->user() ?? auth('customer')->user() ?? auth('agent')->user() ?? auth('crew')->user();
        $service = app(AnnotationService::class);
        $annotation = $service->save($this->photo, $canvasJson, $user);
        $service->renderLayer($this->photo, $annotation);
        $service->renderComposite($this->photo);

        $this->dispatch('close-annotator');
        $this->dispatch('photos-uploaded');
    }

    public function clearAnnotation(): void
    {
        if (! $this->canAnnotate()) {
            return;
        }

        $user = auth()->user() ?? auth('customer')->user() ?? auth('agent')->user() ?? auth('crew')->user();
        $service = app(AnnotationService::class);
        $authorType = PhotoAnnotation::resolveAuthorType($user);
        $service->clearAnnotation($this->photo, $authorType);

        $this->dispatch('close-annotator');
        $this->dispatch('photos-uploaded');
    }

    public function close(): void
    {
        $this->dispatch('close-annotator');
    }
}; ?>

<div
    x-data="annotatorApp()"
    x-init="initAnnotator()"
    class="fixed inset-0 z-[60] bg-black flex flex-col"
    x-on:keydown.escape.window.prevent="handleClose()"
>
    {{-- Top Bar --}}
    <div class="shrink-0 flex items-center justify-between px-3 py-2 bg-zinc-900 border-b border-zinc-700 gap-2">
        {{-- Close --}}
        <button
            x-on:click="handleClose()"
            class="flex items-center gap-1.5 px-3 py-1.5 bg-zinc-700 hover:bg-zinc-600 text-white rounded-lg text-sm font-medium transition-colors shrink-0"
        >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
            </svg>
            <span class="hidden sm:inline">{{ __('Back') }}</span>
        </button>

        {{-- Tools (Desktop) --}}
        <div class="hidden sm:flex items-center gap-1 bg-zinc-800 rounded-lg p-1">
            <button x-on:click="setTool('freehand')" :class="tool === 'freehand' ? 'bg-blue-600 text-white' : 'text-zinc-400 hover:text-white hover:bg-zinc-700'" class="p-1.5 rounded-md transition-colors" title="{{ __('Freehand Draw') }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
            </button>
            <button x-on:click="setTool('arrow')" :class="tool === 'arrow' ? 'bg-blue-600 text-white' : 'text-zinc-400 hover:text-white hover:bg-zinc-700'" class="p-1.5 rounded-md transition-colors" title="{{ __('Arrow') }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" /></svg>
            </button>
            <button x-on:click="setTool('rect')" :class="tool === 'rect' ? 'bg-blue-600 text-white' : 'text-zinc-400 hover:text-white hover:bg-zinc-700'" class="p-1.5 rounded-md transition-colors" title="{{ __('Rectangle') }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" /></svg>
            </button>
            <button x-on:click="setTool('circle')" :class="tool === 'circle' ? 'bg-blue-600 text-white' : 'text-zinc-400 hover:text-white hover:bg-zinc-700'" class="p-1.5 rounded-md transition-colors" title="{{ __('Circle') }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /></svg>
            </button>
            <button x-on:click="setTool('text')" :class="tool === 'text' ? 'bg-blue-600 text-white' : 'text-zinc-400 hover:text-white hover:bg-zinc-700'" class="p-1.5 rounded-md transition-colors" title="{{ __('Text') }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M8 6v12m0-12h8m-8 12h8" /></svg>
            </button>
            <div class="w-px h-6 bg-zinc-600 mx-1"></div>
            <template x-for="c in ['#ff0000','#0066ff','#00cc44','#ffcc00','#ffffff','#000000']" :key="c">
                <button x-on:click="setColor(c)" :class="color === c ? 'ring-2 ring-white ring-offset-1 ring-offset-zinc-800' : ''" class="w-5 h-5 rounded-full border border-zinc-600" :style="'background:'+c" :title="c"></button>
            </template>
            <div class="w-px h-6 bg-zinc-600 mx-1"></div>
            <template x-for="sw in [2,4,6,8]" :key="sw">
                <button x-on:click="strokeWidth = sw" :class="strokeWidth === sw ? 'bg-blue-600 text-white' : 'text-zinc-400 hover:text-white hover:bg-zinc-700'" class="px-1.5 py-0.5 rounded text-xs font-medium transition-colors" x-text="sw + 'px'"></button>
            </template>
            <div class="w-px h-6 bg-zinc-600 mx-1"></div>
            <button x-on:click="undo()" class="p-1.5 text-zinc-400 hover:text-white hover:bg-zinc-700 rounded-md transition-colors" title="{{ __('Undo') }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" /></svg>
            </button>
            <button x-on:click="redo()" class="p-1.5 text-zinc-400 hover:text-white hover:bg-zinc-700 rounded-md transition-colors" title="{{ __('Redo') }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 15l6-6m0 0l-6-6m6 6H9a6 6 0 000 12h3" /></svg>
            </button>
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-2 shrink-0">
            {{-- Author Type Badge --}}
            <div class="hidden sm:flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-sm font-medium text-white" style="background: {{ $authorColor }}40; border: 1px solid {{ $authorColor }}">
                <span class="w-2 h-2 rounded-full" style="background: {{ $authorColor }}"></span>
                {{ __('Editing as:') }} {{ $authorLabel }}
            </div>

            @if ($hasExistingAnnotation)
                <button
                    wire:click="clearAnnotation"
                    wire:confirm="{{ __('Remove your :type annotations from this photo?', ['type' => strtolower($authorLabel)]) }}"
                    class="px-3 py-1.5 bg-red-600/20 text-red-400 hover:bg-red-600/30 rounded-lg text-sm font-medium transition-colors"
                >
                    {{ __('Clear Mine') }}
                </button>
            @endif
            <button
                x-on:click="save()"
                class="flex items-center gap-1.5 px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors"
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
                {{ __('Save') }}
            </button>
        </div>
    </div>

    {{-- Canvas --}}
    <div class="flex-1 flex items-center justify-center min-h-0 overflow-hidden bg-zinc-950 relative" x-ref="canvasWrap">
        {{-- Loading overlay (sits on top, hidden when done) --}}
        <div x-show="loading" class="absolute inset-0 flex items-center justify-center z-10 bg-zinc-950">
            <div class="flex flex-col items-center gap-3 text-zinc-400">
                <svg class="w-8 h-8 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                <span class="text-sm">{{ __('Loading editor...') }}</span>
            </div>
        </div>
        {{-- Canvas element: NO x-show to avoid Alpine interfering with Fabric.js DOM --}}
        <canvas x-ref="canvas"></canvas>
    </div>

    {{-- Mobile Bottom Toolbar --}}
    <div class="sm:hidden shrink-0 bg-zinc-900 border-t border-zinc-700 px-2 py-2 space-y-2">
        {{-- Tool row --}}
        <div class="flex items-center justify-center gap-1">
            <button x-on:click="setTool('freehand')" :class="tool === 'freehand' ? 'bg-blue-600 text-white' : 'bg-zinc-800 text-zinc-400'" class="p-2 rounded-lg">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
            </button>
            <button x-on:click="setTool('arrow')" :class="tool === 'arrow' ? 'bg-blue-600 text-white' : 'bg-zinc-800 text-zinc-400'" class="p-2 rounded-lg">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" /></svg>
            </button>
            <button x-on:click="setTool('rect')" :class="tool === 'rect' ? 'bg-blue-600 text-white' : 'bg-zinc-800 text-zinc-400'" class="p-2 rounded-lg">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" /></svg>
            </button>
            <button x-on:click="setTool('circle')" :class="tool === 'circle' ? 'bg-blue-600 text-white' : 'bg-zinc-800 text-zinc-400'" class="p-2 rounded-lg">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /></svg>
            </button>
            <button x-on:click="setTool('text')" :class="tool === 'text' ? 'bg-blue-600 text-white' : 'bg-zinc-800 text-zinc-400'" class="p-2 rounded-lg">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M8 6v12m0-12h8m-8 12h8" /></svg>
            </button>
            <div class="w-px h-8 bg-zinc-700 mx-1"></div>
            <button x-on:click="undo()" class="p-2 bg-zinc-800 text-zinc-400 rounded-lg">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" /></svg>
            </button>
            <button x-on:click="redo()" class="p-2 bg-zinc-800 text-zinc-400 rounded-lg">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 15l6-6m0 0l-6-6m6 6H9a6 6 0 000 12h3" /></svg>
            </button>
        </div>
        {{-- Color/stroke row --}}
        <div class="flex items-center justify-center gap-2">
            <template x-for="c in ['#ff0000','#0066ff','#00cc44','#ffcc00','#ffffff','#000000']" :key="'m'+c">
                <button x-on:click="setColor(c)" :class="color === c ? 'ring-2 ring-white ring-offset-1 ring-offset-zinc-900 scale-110' : ''" class="w-6 h-6 rounded-full border border-zinc-600 transition-transform" :style="'background:'+c"></button>
            </template>
            <div class="w-px h-6 bg-zinc-700 mx-1"></div>
            <template x-for="sw in [2,4,6,8]" :key="'msw'+sw">
                <button x-on:click="strokeWidth = sw" :class="strokeWidth === sw ? 'bg-blue-600 text-white' : 'bg-zinc-800 text-zinc-400'" class="px-2 py-0.5 rounded text-xs font-medium" x-text="sw + 'px'"></button>
            </template>
        </div>
    </div>
</div>

@script
<script>
Alpine.data('annotatorApp', () => ({
    canvas: null,
    tool: 'freehand',
    color: '#ff0000',
    strokeWidth: 4,
    undoStack: [],
    redoStack: [],
    isDrawing: false,
    startPt: null,
    tempObj: null,
    loading: true,

    initAnnotator() {
        let imageUrl = @js($photoUrl);
        let existingJson = @js($existingCanvasJson);
        let scriptUrl = @js(Illuminate\Support\Facades\Vite::asset('resources/js/photo-annotator.js'));

        // If fabric is already loaded, set up immediately
        if (window.fabric && window.fabric.Canvas) {
            this.setupCanvas(imageUrl, existingJson);
            return;
        }

        // Dynamically load the Fabric.js module
        let script = document.createElement('script');
        script.type = 'module';
        script.src = scriptUrl;
        script.onload = () => {
            // Module scripts execute after load, but need a tick for window.fabric
            let check = () => {
                if (window.fabric && window.fabric.Canvas) {
                    this.setupCanvas(imageUrl, existingJson);
                } else {
                    requestAnimationFrame(check);
                }
            };
            requestAnimationFrame(check);
        };
        script.onerror = () => {
            console.error('Failed to load Fabric.js script:', scriptUrl);
            this.loading = false;
        };

        // Only add if not already in DOM
        if (!document.querySelector('script[src="' + scriptUrl + '"]')) {
            document.head.appendChild(script);
        } else {
            // Script tag exists but fabric not loaded yet - wait for it
            let check = () => {
                if (window.fabric && window.fabric.Canvas) {
                    this.setupCanvas(imageUrl, existingJson);
                } else {
                    requestAnimationFrame(check);
                }
            };
            requestAnimationFrame(check);
        }
    },

    setupCanvas(imageUrl, existingJson) {
        try {
            let wrap = this.$refs.canvasWrap;
            if (!wrap) { console.error('Canvas wrapper not found'); this.loading = false; return; }

            let maxW = wrap.clientWidth - 16;
            let maxH = wrap.clientHeight - 16;

            if (maxW <= 0 || maxH <= 0) {
                maxW = Math.max(maxW, 600);
                maxH = Math.max(maxH, 400);
            }

            let img = new Image();
            img.onload = () => {
                try {
                    // Scale to fit the available space (allow scaling UP for small images)
                    let scale = Math.min(maxW / img.naturalWidth, maxH / img.naturalHeight);
                    let w = Math.floor(img.naturalWidth * scale);
                    let h = Math.floor(img.naturalHeight * scale);

                    this.$refs.canvas.width = w;
                    this.$refs.canvas.height = h;

                    this.canvas = new window.fabric.Canvas(this.$refs.canvas, {
                        width: w,
                        height: h,
                        isDrawingMode: true,
                        selection: false,
                    });

                    // Background image
                    let fabricImg = new window.fabric.FabricImage(img, {
                        scaleX: w / img.naturalWidth,
                        scaleY: h / img.naturalHeight,
                    });
                    this.canvas.backgroundImage = fabricImg;
                    this.canvas.renderAll();

                    // Configure freehand brush
                    this.canvas.freeDrawingBrush = new window.fabric.PencilBrush(this.canvas);
                    this.canvas.freeDrawingBrush.color = this.color;
                    this.canvas.freeDrawingBrush.width = this.strokeWidth;

                    // Load existing annotations
                    if (existingJson && existingJson.objects && existingJson.objects.length > 0) {
                        window.fabric.util.enlivenObjects(existingJson.objects).then((objs) => {
                            objs.forEach(o => this.canvas.add(o));
                            this.canvas.renderAll();
                            this.saveState();
                        });
                    } else {
                        this.saveState();
                    }

                    // Event handlers
                    this.canvas.on('path:created', () => this.saveState());

                    this.canvas.on('mouse:down', (opt) => {
                        if (this.tool === 'freehand') return;
                        if (this.tool === 'text') {
                            let pt = this.canvas.getViewportPoint(opt.e);
                            this.addText(pt);
                            return;
                        }
                        this.isDrawing = true;
                        this.startPt = this.canvas.getViewportPoint(opt.e);
                    });

                    this.canvas.on('mouse:move', (opt) => {
                        if (!this.isDrawing || !this.startPt || this.tool === 'freehand') return;
                        let pt = this.canvas.getViewportPoint(opt.e);
                        if (this.tempObj) this.canvas.remove(this.tempObj);
                        this.tempObj = this.makeShape(this.startPt, pt);
                        if (this.tempObj) {
                            this.canvas.add(this.tempObj);
                            this.canvas.renderAll();
                        }
                    });

                    this.canvas.on('mouse:up', () => {
                        if (!this.isDrawing) return;
                        this.isDrawing = false;
                        if (this.tempObj) this.saveState();
                        this.tempObj = null;
                        this.startPt = null;
                    });

                    // Hide loading overlay now that canvas is ready
                    this.loading = false;
                } catch (e) {
                    console.error('Fabric canvas setup error:', e);
                    this.loading = false;
                }
            };
            img.onerror = (e) => {
                console.error('Failed to load photo:', imageUrl, e);
                this.loading = false;
            };
            img.src = imageUrl;
        } catch (e) {
            console.error('setupCanvas error:', e);
            this.loading = false;
        }
    },

    makeShape(s, e) {
        let f = window.fabric;
        let opts = { stroke: this.color, strokeWidth: this.strokeWidth, fill: 'transparent', selectable: false, evented: false };
        if (this.tool === 'arrow') return this.makeArrow(s, e, opts);
        if (this.tool === 'rect') return new f.Rect({ ...opts, left: Math.min(s.x, e.x), top: Math.min(s.y, e.y), width: Math.abs(e.x - s.x), height: Math.abs(e.y - s.y) });
        if (this.tool === 'circle') return new f.Ellipse({ ...opts, left: Math.min(s.x, e.x), top: Math.min(s.y, e.y), rx: Math.abs(e.x - s.x) / 2, ry: Math.abs(e.y - s.y) / 2 });
        return null;
    },

    makeArrow(s, e, opts) {
        let f = window.fabric;
        let a = Math.atan2(e.y - s.y, e.x - s.x);
        let len = 15;
        let ha = Math.PI / 6;
        return new f.Group([
            new f.Line([s.x, s.y, e.x, e.y], { ...opts }),
            new f.Line([e.x, e.y, e.x - len * Math.cos(a - ha), e.y - len * Math.sin(a - ha)], { ...opts }),
            new f.Line([e.x, e.y, e.x - len * Math.cos(a + ha), e.y - len * Math.sin(a + ha)], { ...opts }),
        ], { selectable: false, evented: false });
    },

    addText(pt) {
        // Don't add a new text box if we're already editing one
        let active = this.canvas.getActiveObject();
        if (active && active.isEditing) return;

        let t = new window.fabric.IText('', { left: pt.x, top: pt.y, fontSize: 24, fill: this.color, fontFamily: 'Arial', selectable: true, evented: true });
        this.canvas.add(t);
        this.canvas.setActiveObject(t);
        t.enterEditing();
        this.canvas.renderAll();

        // Save state when user exits editing (not immediately, since text is empty)
        t.on('editing:exited', () => {
            if (t.text.trim() === '') {
                this.canvas.remove(t);
            }
            this.saveState();
        });
    },

    setTool(t) {
        this.tool = t;
        if (!this.canvas) return;
        this.canvas.isDrawingMode = t === 'freehand';
        if (t === 'freehand') {
            this.canvas.freeDrawingBrush = new window.fabric.PencilBrush(this.canvas);
            this.canvas.freeDrawingBrush.color = this.color;
            this.canvas.freeDrawingBrush.width = this.strokeWidth;
        }
    },

    setColor(c) {
        this.color = c;
        if (this.canvas && this.canvas.freeDrawingBrush) {
            this.canvas.freeDrawingBrush.color = c;
        }
    },

    saveState() {
        if (!this.canvas) return;
        this.undoStack.push(JSON.stringify(this.canvas.toJSON()));
        this.redoStack = [];
    },

    undo() {
        if (!this.canvas || this.undoStack.length <= 1) return;
        this.redoStack.push(this.undoStack.pop());
        this.canvas.loadFromJSON(JSON.parse(this.undoStack[this.undoStack.length - 1])).then(() => this.canvas.renderAll());
    },

    redo() {
        if (!this.canvas || !this.redoStack.length) return;
        let next = this.redoStack.pop();
        this.undoStack.push(next);
        this.canvas.loadFromJSON(JSON.parse(next)).then(() => this.canvas.renderAll());
    },

    save() {
        if (!this.canvas) return;
        let json = this.canvas.toJSON();
        let data = {
            version: json.version || '6.0',
            width: this.canvas.width,
            height: this.canvas.height,
            objects: (json.objects || []).filter(o => o.type !== 'image'),
        };
        this.$wire.saveAnnotation(data);
    },

    handleClose() {
        this.$wire.close();
    },

    destroy() {
        if (this.canvas) { this.canvas.dispose(); this.canvas = null; }
    },
}));
</script>
@endscript
