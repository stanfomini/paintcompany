// Workflow Builder - Visual node-based workflow editor
function registerWorkflowBuilder() {
    Alpine.data('workflowBuilder', (initialNodes, initialEdges, registry) => ({
        nodes: initialNodes,
        edges: initialEdges,
        registry: registry,

        // Drag state
        dragging: null,
        dragOffset: { x: 0, y: 0 },
        _rafId: null,
        _saveTimer: null,
        _dirtyPositions: new Map(),
        _cachedRect: null,
        _pointerId: null,

        // Connection state
        connecting: false,
        connectFromId: null,
        connectBranch: null,
        connectFrom: { x: 0, y: 0 },
        connectTo: { x: 0, y: 0 },

        // Sidebar drag-and-drop state
        dragOverCanvas: false,
        _dragType: null,
        _dragClass: null,

        // Edge element cache
        _edgeCache: new Map(),

        // Node lookup map for O(1) access during edge rendering
        _nodeMap: new Map(),

        // Keyboard listener ref
        _onKeyDown: null,

        // Node dimensions (keep in sync with template)
        NODE_W: 280,
        NODE_H: 120,

        // Dynamic canvas bounds based on actual node positions
        get canvasWidth() {
            let max = 800;
            for (const node of this.nodes) {
                max = Math.max(max, node.position_x + this.NODE_W + 200);
            }
            return max;
        },

        get canvasHeight() {
            let max = 600;
            for (const node of this.nodes) {
                max = Math.max(max, node.position_y + 200);
            }
            return max;
        },

        init() {
            // Lock page scroll while builder is open
            this._prevBodyOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';

            this._buildNodeMap();

            this.$wire.$on('workflow-data-updated', ({ nodes, edges }) => {
                this.nodes = nodes;
                this.edges = edges;
                this._buildNodeMap();
                this._edgeCache.clear();
                const group = this.$refs.edgeGroup;
                if (group) { while (group.firstChild) group.removeChild(group.firstChild); }
                this.$nextTick(() => this.renderEdges());
            });

            this._onKeyDown = (e) => {
                if (e.key === 'Escape') {
                    if (this.connecting) {
                        this.connecting = false;
                        this.connectFromId = null;
                        this.connectBranch = null;
                    }
                    if (this.dragging) {
                        this.dragging = null;
                        this._cachedRect = null;
                        this._releaseCapture();
                        this._unlockScroll();
                    }
                }
            };
            document.addEventListener('keydown', this._onKeyDown);

            this.$nextTick(() => {
                this.renderEdges();
                // Auto-layout if all nodes are stacked on top of each other
                if (this.nodes.length > 1) {
                    const allStacked = this.nodes.every(n =>
                        n.position_x === this.nodes[0].position_x &&
                        n.position_y === this.nodes[0].position_y
                    );
                    if (allStacked) this.autoLayout();
                }
            });
        },

        destroy() {
            // Restore page scroll
            document.body.style.overflow = this._prevBodyOverflow || '';
            document.documentElement.style.overflow = '';

            if (this._rafId) cancelAnimationFrame(this._rafId);
            if (this._saveTimer) clearTimeout(this._saveTimer);
            if (this._onKeyDown) document.removeEventListener('keydown', this._onKeyDown);
            this._edgeCache.clear();
            this._nodeMap.clear();
        },

        _buildNodeMap() {
            this._nodeMap.clear();
            for (const node of this.nodes) {
                this._nodeMap.set(node.id, node);
            }
        },

        _releaseCapture() {
            const canvas = this.$refs.canvas;
            if (canvas && this._pointerId != null) {
                try { canvas.releasePointerCapture(this._pointerId); } catch (ex) {}
                this._pointerId = null;
            }
        },

        _lockScroll() {
            const canvas = this.$refs.canvas;
            if (canvas) canvas.style.overflow = 'hidden';
        },

        _unlockScroll() {
            const canvas = this.$refs.canvas;
            if (canvas) canvas.style.overflow = '';
        },

        // === Debounced position persistence ===
        // Collects dirty positions and batch-saves after 500ms idle.
        // Server uses #[Renderless] so no morph/re-render happens.

        markPositionDirty(node) {
            this._dirtyPositions.set(node.id, { id: node.id, x: node.position_x, y: node.position_y });
            if (this._saveTimer) clearTimeout(this._saveTimer);
            this._saveTimer = setTimeout(() => this.flushPositions(), 500);
        },

        flushPositions() {
            if (this._dirtyPositions.size === 0) return;
            const positions = Array.from(this._dirtyPositions.values());
            this._dirtyPositions.clear();
            this.$wire.saveNodePositions(positions);
        },

        // === Sidebar drag-and-drop ===

        handleDragStart(e, type, nodeClass) {
            this._dragType = type;
            this._dragClass = nodeClass;
            e.dataTransfer.effectAllowed = 'copy';
            e.dataTransfer.setData('text/plain', type);
        },

        handleCanvasDrop(e) {
            this.dragOverCanvas = false;
            if (!this._dragType) return;

            const canvas = this.$refs.canvas;
            const rect = canvas.getBoundingClientRect();
            const x = e.clientX - rect.left + canvas.scrollLeft;
            const y = e.clientY - rect.top + canvas.scrollTop;

            this.$wire.addNode(this._dragType, this._dragClass, x, y);
            this._dragType = null;
            this._dragClass = null;
        },

        // === Bezier path generation ===

        bezierPath(sx, sy, tx, ty) {
            const dy = Math.abs(ty - sy);
            const offset = Math.max(dy * 0.4, 40);
            return `M ${sx} ${sy} C ${sx} ${sy + offset}, ${tx} ${ty - offset}, ${tx} ${ty}`;
        },

        connectPath() {
            return this.bezierPath(
                this.connectFrom.x, this.connectFrom.y,
                this.connectTo.x, this.connectTo.y
            );
        },

        // === Optimized edge rendering with bezier curves ===
        // Uses element cache: updates path d-attribute during drag, creates SVG on structural changes.

        renderEdges() {
            const group = this.$refs.edgeGroup;
            if (!group) return;

            const currentIds = new Set(this.edges.map(e => e.id));
            const isDark = document.documentElement.classList.contains('dark');
            const strokeColor = isDark ? '#52525b' : '#a1a1aa';

            // Remove deleted edges from cache and DOM
            for (const [id, els] of this._edgeCache) {
                if (!currentIds.has(id)) {
                    els.g.remove();
                    this._edgeCache.delete(id);
                }
            }

            const svgNS = 'http://www.w3.org/2000/svg';

            for (const edge of this.edges) {
                const fromNode = this._nodeMap.get(edge.from_node_id);
                const toNode = this._nodeMap.get(edge.to_node_id);
                if (!fromNode || !toNode) continue;

                // Source: branch-aware position for if/else, bottom center otherwise
                let sx, sy;
                if (fromNode.type === 'if_else' && edge.branch === 'true') {
                    sx = fromNode.position_x + this.NODE_W * 0.25;
                    sy = fromNode.position_y + this.NODE_H + 8;
                } else if (fromNode.type === 'if_else' && edge.branch === 'false') {
                    sx = fromNode.position_x + this.NODE_W * 0.75;
                    sy = fromNode.position_y + this.NODE_H + 8;
                } else {
                    sx = fromNode.position_x + this.NODE_W / 2;
                    sy = fromNode.position_y + this.NODE_H;
                }
                // Target: top center of to node
                const tx = toNode.position_x + this.NODE_W / 2;
                const ty = toNode.position_y;

                const d = this.bezierPath(sx, sy, tx, ty);
                const midX = (sx + tx) / 2;
                const midY = (sy + ty) / 2;

                if (this._edgeCache.has(edge.id)) {
                    // Fast path: update bezier d-attribute only
                    const els = this._edgeCache.get(edge.id);
                    els.path.setAttribute('d', d);
                    els.hitPath.setAttribute('d', d);
                    if (els.branchText) {
                        els.branchText.setAttribute('x', midX + 8);
                        els.branchText.setAttribute('y', midY);
                    }
                    els.circle.setAttribute('cx', midX);
                    els.circle.setAttribute('cy', midY);
                    els.xMark.setAttribute('x', midX);
                    els.xMark.setAttribute('y', midY + 4);
                } else {
                    // Create new SVG edge elements
                    const g = document.createElementNS(svgNS, 'g');
                    g.style.pointerEvents = 'auto';

                    // Branch-colored edges for if/else
                    const edgeColor = edge.branch === 'true' ? '#22c55e'
                        : edge.branch === 'false' ? '#ef4444'
                        : strokeColor;

                    const path = document.createElementNS(svgNS, 'path');
                    path.setAttribute('d', d);
                    path.setAttribute('fill', 'none');
                    path.setAttribute('stroke', edgeColor);
                    path.setAttribute('stroke-width', '2');
                    path.setAttribute('marker-end', 'url(#arrowhead)');
                    g.appendChild(path);

                    // Invisible wider hit area for easier hover/click
                    const hitPath = document.createElementNS(svgNS, 'path');
                    hitPath.setAttribute('d', d);
                    hitPath.setAttribute('fill', 'none');
                    hitPath.setAttribute('stroke', 'transparent');
                    hitPath.setAttribute('stroke-width', '20');
                    hitPath.setAttribute('cursor', 'pointer');
                    g.appendChild(hitPath);

                    let branchText = null;
                    if (edge.branch) {
                        branchText = document.createElementNS(svgNS, 'text');
                        branchText.setAttribute('x', midX + 8);
                        branchText.setAttribute('y', midY);
                        branchText.setAttribute('fill', isDark ? '#71717a' : '#a1a1aa');
                        branchText.setAttribute('font-size', '10');
                        branchText.textContent = edge.branch;
                        g.appendChild(branchText);
                    }

                    const circle = document.createElementNS(svgNS, 'circle');
                    circle.setAttribute('cx', midX);
                    circle.setAttribute('cy', midY);
                    circle.setAttribute('r', '10');
                    circle.setAttribute('fill', isDark ? 'rgba(127, 29, 29, 0.5)' : '#fee2e2');
                    circle.setAttribute('opacity', '0');
                    circle.setAttribute('cursor', 'pointer');
                    g.appendChild(circle);

                    const xMark = document.createElementNS(svgNS, 'text');
                    xMark.setAttribute('x', midX);
                    xMark.setAttribute('y', midY + 4);
                    xMark.setAttribute('text-anchor', 'middle');
                    xMark.setAttribute('fill', '#ef4444');
                    xMark.setAttribute('font-size', '12');
                    xMark.setAttribute('font-weight', 'bold');
                    xMark.setAttribute('pointer-events', 'none');
                    xMark.setAttribute('opacity', '0');
                    xMark.textContent = '\u00d7';
                    g.appendChild(xMark);

                    const showDelete = () => { circle.setAttribute('opacity', '1'); xMark.setAttribute('opacity', '1'); path.setAttribute('stroke', '#ef4444'); path.setAttribute('stroke-width', '3'); };
                    const hideDelete = () => { circle.setAttribute('opacity', '0'); xMark.setAttribute('opacity', '0'); path.setAttribute('stroke', edgeColor); path.setAttribute('stroke-width', '2'); };
                    hitPath.addEventListener('pointerenter', showDelete);
                    hitPath.addEventListener('pointerleave', hideDelete);
                    circle.addEventListener('pointerenter', showDelete);
                    circle.addEventListener('pointerleave', hideDelete);

                    const edgeId = edge.id;
                    hitPath.addEventListener('click', () => this.$wire.deleteEdge(edgeId));
                    circle.addEventListener('click', () => this.$wire.deleteEdge(edgeId));

                    group.appendChild(g);
                    this._edgeCache.set(edge.id, { g, path, hitPath, branchText, circle, xMark });
                }
            }
        },

        // === Node dragging with pointer capture ===
        // setPointerCapture routes all pointer events to canvas, even outside bounds.
        // Eliminates stuck-drag bugs from missed mouseup events.

        startDrag(e, node) {
            if (this.connecting) return;

            const canvas = this.$refs.canvas;
            this._cachedRect = canvas.getBoundingClientRect();
            this._pointerId = e.pointerId;
            this.dragging = node;
            this.dragOffset = {
                x: e.clientX - this._cachedRect.left + canvas.scrollLeft - node.position_x,
                y: e.clientY - this._cachedRect.top + canvas.scrollTop - node.position_y,
            };

            // Capture pointer to canvas: all future pointermove/up route here
            canvas.setPointerCapture(e.pointerId);
            this._lockScroll();
        },

        onPointerMove(e) {
            if (this.dragging) {
                const canvas = this.$refs.canvas;
                const rect = this._cachedRect;
                if (!rect) return;

                // Mutate proxy directly, then reassign array to trigger Alpine reactivity
                this.dragging.position_x = Math.max(0, e.clientX - rect.left + canvas.scrollLeft - this.dragOffset.x);
                this.dragging.position_y = Math.max(0, e.clientY - rect.top + canvas.scrollTop - this.dragOffset.y);
                this.nodes = this.nodes;

                // Throttle edge redraws to animation frames
                if (!this._rafId) {
                    this._rafId = requestAnimationFrame(() => {
                        this.renderEdges();
                        this._rafId = null;
                    });
                }
                return;
            }

            if (this.connecting) {
                const canvas = this.$refs.canvas;
                const rect = canvas.getBoundingClientRect();
                this.connectTo = {
                    x: e.clientX - rect.left + canvas.scrollLeft,
                    y: e.clientY - rect.top + canvas.scrollTop,
                };
            }
        },

        onPointerUp(e) {
            if (this.dragging) {
                // Snap to 20px grid
                this.dragging.position_x = Math.round(this.dragging.position_x / 20) * 20;
                this.dragging.position_y = Math.round(this.dragging.position_y / 20) * 20;
                this.nodes = this.nodes;

                this.markPositionDirty(this.dragging);
                this.dragging = null;
                this._cachedRect = null;
                this._releaseCapture();
                this._unlockScroll();
                this.renderEdges();
                return;
            }

            if (this.connecting) {
                // Snap to nearest node within 80px for forgiving connection UX
                const canvas = this.$refs.canvas;
                const rect = canvas.getBoundingClientRect();
                const px = e.clientX - rect.left + canvas.scrollLeft;
                const py = e.clientY - rect.top + canvas.scrollTop;

                const nearest = this.findNearestNode(px, py, 80);
                if (nearest && nearest.id !== this.connectFromId) {
                    this.$wire.connectNodes(this.connectFromId, nearest.id, this.connectBranch);
                }

                this.connecting = false;
                this.connectFromId = null;
                this.connectBranch = null;
                this._releaseCapture();
                this._unlockScroll();
            }
        },

        findNearestNode(px, py, maxDist) {
            let best = null;
            let bestDist = maxDist;
            for (const node of this.nodes) {
                const cx = node.position_x + this.NODE_W / 2;
                const cy = node.position_y + this.NODE_H / 2;
                const d = Math.hypot(px - cx, py - cy);
                if (d < bestDist) {
                    bestDist = d;
                    best = node;
                }
            }
            return best;
        },

        // === Node connections ===

        startConnect(e, node, branch = null) {
            const canvas = this.$refs.canvas;
            const rect = canvas.getBoundingClientRect();
            this.connecting = true;
            this.connectFromId = node.id;
            this.connectBranch = branch;

            // Branch-aware source position
            let sx, sy;
            if (branch === 'true') {
                sx = node.position_x + this.NODE_W * 0.25;
                sy = node.position_y + this.NODE_H + 8;
            } else if (branch === 'false') {
                sx = node.position_x + this.NODE_W * 0.75;
                sy = node.position_y + this.NODE_H + 8;
            } else {
                sx = node.position_x + this.NODE_W / 2;
                sy = node.position_y + this.NODE_H;
            }

            this.connectFrom = { x: sx, y: sy };
            this.connectTo = {
                x: e.clientX - rect.left + canvas.scrollLeft,
                y: e.clientY - rect.top + canvas.scrollTop,
            };

            // Capture pointer so canvas receives all move/up events
            this._pointerId = e.pointerId;
            canvas.setPointerCapture(e.pointerId);
            this._lockScroll();
        },

        // === Auto Layout (BFS tree from triggers) ===

        autoLayout() {
            if (this.nodes.length === 0) return;

            // Build adjacency from edges
            const children = new Map();
            const hasParent = new Set();
            for (const edge of this.edges) {
                if (!children.has(edge.from_node_id)) children.set(edge.from_node_id, []);
                children.get(edge.from_node_id).push(edge.to_node_id);
                hasParent.add(edge.to_node_id);
            }

            // Find roots (triggers or nodes with no incoming edges)
            const roots = this.nodes.filter(n => n.type === 'trigger' || !hasParent.has(n.id));
            if (roots.length === 0) roots.push(this.nodes[0]);

            const placed = new Set();
            const colSpacing = this.NODE_W + 60;
            const rowSpacing = this.NODE_H + 60;
            let col = 0;

            for (const root of roots) {
                // BFS per root
                const queue = [{ id: root.id, depth: 0 }];
                const depthNodes = new Map();

                while (queue.length > 0) {
                    const { id, depth } = queue.shift();
                    if (placed.has(id)) continue;
                    placed.add(id);

                    if (!depthNodes.has(depth)) depthNodes.set(depth, []);
                    depthNodes.get(depth).push(id);

                    const kids = children.get(id) || [];
                    for (const kid of kids) {
                        if (!placed.has(kid)) queue.push({ id: kid, depth: depth + 1 });
                    }
                }

                // Position nodes in tree: each depth is a row, centered horizontally per column
                const maxWidth = Math.max(...Array.from(depthNodes.values()).map(arr => arr.length));
                for (const [depth, ids] of depthNodes) {
                    for (let i = 0; i < ids.length; i++) {
                        const node = this.nodes.find(n => n.id === ids[i]);
                        if (!node) continue;
                        const totalW = ids.length * colSpacing;
                        const startX = col * (maxWidth * colSpacing) + 40;
                        node.position_x = startX + i * colSpacing + (maxWidth * colSpacing - totalW) / 2;
                        node.position_y = depth * rowSpacing + 40;
                    }
                }
                col++;
            }

            // Place any orphan nodes not reached by BFS
            let orphanY = 40;
            for (const node of this.nodes) {
                if (!placed.has(node.id)) {
                    node.position_x = col * (this.NODE_W + 60) + 40;
                    node.position_y = orphanY;
                    orphanY += rowSpacing;
                }
            }

            this.nodes = this.nodes;
            this._buildNodeMap();
            this.renderEdges();

            // Batch save all positions
            const positions = this.nodes.map(n => ({ id: n.id, x: Math.round(n.position_x), y: Math.round(n.position_y) }));
            this.$wire.saveNodePositions(positions);
        },

        // === Fit View (scroll canvas to show all nodes centered) ===

        fitView() {
            if (this.nodes.length === 0) return;
            const canvas = this.$refs.canvas;
            if (!canvas) return;

            let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
            for (const node of this.nodes) {
                minX = Math.min(minX, node.position_x);
                minY = Math.min(minY, node.position_y);
                maxX = Math.max(maxX, node.position_x + this.NODE_W);
                maxY = Math.max(maxY, node.position_y + this.NODE_H);
            }

            const contentW = maxX - minX;
            const contentH = maxY - minY;
            const viewW = canvas.clientWidth;
            const viewH = canvas.clientHeight;

            // Scroll so content is centered in view
            const targetScrollX = Math.max(0, minX - Math.max(40, (viewW - contentW) / 2));
            const targetScrollY = Math.max(0, minY - Math.max(40, (viewH - contentH) / 2));

            canvas.scrollTo({ left: targetScrollX, top: targetScrollY, behavior: 'smooth' });
        },

        // === Node config helpers ===

        findRegistryItem(node) {
            const typeMap = { trigger: 'triggers', action: 'actions', if_else: 'conditions', wait: 'waits' };
            const section = typeMap[node.type];
            if (!section || !this.registry[section]) return null;
            return this.registry[section].find(item => item.class === node.node_class) || null;
        },

        needsConfig(node) {
            const item = this.findRegistryItem(node);
            if (!item || !item.config_schema) return false;
            const schema = item.config_schema;
            const config = node.config || {};
            for (const key of Object.keys(schema)) {
                const val = config[key];
                if (val === undefined || val === null || val === '') return true;
            }
            return false;
        },

        getConfigSummary(node) {
            const config = node.config || {};
            const parts = [];
            for (const [key, val] of Object.entries(config)) {
                if (val && typeof val === 'string' && val.length < 30) {
                    parts.push(val);
                }
            }
            return parts.slice(0, 2).join(' | ') || '';
        },
    }));
}

// Export for external use and self-register via alpine:init.
// Static import from app.js ensures this runs before DOMContentLoaded,
// so the alpine:init listener is always registered in time.
export { registerWorkflowBuilder };
document.addEventListener('alpine:init', registerWorkflowBuilder);
