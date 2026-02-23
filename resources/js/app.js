import Sortable from 'sortablejs';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import html2canvas from 'html2canvas';
import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import './workflow-builder';
import './tiptap-editor';
import './pwa';
import './push';

window.Sortable = Sortable;
window.html2canvas = html2canvas;
window.Cropper = Cropper;

window.Pusher = Pusher;

// Only initialize Echo if user is authenticated and Reverb is configured
if (document.querySelector('meta[name="user-id"]')) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

// Avatar Cropper Alpine.js component
document.addEventListener('alpine:init', () => {
    Alpine.data('avatarCropper', () => ({
        cropper: null,
        showModal: false,
        imageSrc: null,
        uploading: false,

        selectFile(event) {
            const file = event.target.files[0];
            if (!file) return;

            // Validate file type client-side (size doesn't matter - we crop before upload)
            const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
            if (!validTypes.includes(file.type)) {
                alert('Please select a JPG, PNG, or WebP image.');
                event.target.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = (e) => {
                this.imageSrc = e.target.result;
                this.showModal = true;
                this.$nextTick(() => this.initCropper());
            };
            reader.readAsDataURL(file);

            // Reset input so same file can be re-selected
            event.target.value = '';
        },

        initCropper() {
            if (this.cropper) {
                this.cropper.destroy();
            }

            const image = this.$refs.cropImage;
            this.cropper = new window.Cropper(image, {
                aspectRatio: 1,
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 1,
                cropBoxResizable: true,
                cropBoxMovable: true,
                guides: true,
                center: true,
                background: false,
            });
        },

        async cropAndUpload() {
            if (!this.cropper) return;

            this.uploading = true;

            const canvas = this.cropper.getCroppedCanvas({
                width: 512,
                height: 512,
                imageSmoothingEnabled: true,
                imageSmoothingQuality: 'high',
            });

            canvas.toBlob((blob) => {
                const file = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });

                this.$wire.upload('avatar', file, () => {
                    // Success
                    this.uploading = false;
                    this.close();
                }, () => {
                    // Error
                    this.uploading = false;
                    alert('Upload failed. Please try again.');
                }, (event) => {
                    // Progress - could add a progress bar
                });
            }, 'image/jpeg', 0.9);
        },

        close() {
            this.showModal = false;
            if (this.cropper) {
                this.cropper.destroy();
                this.cropper = null;
            }
            this.imageSrc = null;
        },
    }));
});

// FullCalendar Alpine.js component for planner calendar view
document.addEventListener('alpine:init', () => {
    Alpine.data('calendarView', () => ({
        calendar: null,
        init() {
            this.$nextTick(() => {
                this.calendar = new Calendar(this.$refs.calendar, {
                    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
                    initialView: 'timeGridWeek',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,timeGridDay',
                    },
                    events: '/api/activities/calendar',
                    eventClick: (info) => {
                        if (info.event.url) {
                            info.jsEvent.preventDefault();
                            if (window.Livewire) {
                                Livewire.navigate(info.event.url);
                            } else {
                                window.location.href = info.event.url;
                            }
                        }
                    },
                    eventDidMount: (info) => {
                        const label = info.event.extendedProps.subject_label;
                        if (label) {
                            info.el.setAttribute('title', label);
                        }
                    },
                    height: 'auto',
                    nowIndicator: true,
                    slotMinTime: '06:00:00',
                    slotMaxTime: '21:00:00',
                });
                this.calendar.render();
            });
        },
        destroy() {
            if (this.calendar) {
                this.calendar.destroy();
                this.calendar = null;
            }
        },
    }));
});

// Territory System - Leaflet Map Component
document.addEventListener('alpine:init', () => {
    Alpine.data('territoryMap', (config = {}) => ({
        map: null,
        markers: {},
        polygonLayers: [],
        contextLayers: [],
        drawnItems: null,
        drawControl: null,
        pinMarker: null,
        gpsStatus: 'idle', // idle, requesting, granted, denied, unavailable
        userLocationMarker: null,

        // Tracking state (door knock live GPS)
        watchId: null,
        followMode: true,
        userAccuracyCircle: null,
        lastPositions: [],
        stopCheckInterval: null,
        lastGeocodedPosition: null,
        stopDetected: false,
        detectedAddress: '',
        detectedStopPosition: null,
        tooFar: false,

        init() {
            this.$nextTick(() => {
                this.waitForLeaflet();
            });
        },

        waitForLeaflet(attempts = 0) {
            if (window.L) {
                this.initMap();
                return;
            }

            // Leaflet might still be loading via @assets - retry up to 50 times (5 seconds)
            if (attempts < 50) {
                setTimeout(() => this.waitForLeaflet(attempts + 1), 100);
                return;
            }

            // Give up after 5 seconds
            if (this.$refs.map) {
                this.$refs.map.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#888;font-size:14px;">Map library failed to load. Please refresh the page.</div>';
            }
        },

        initMap() {

            const center = config.center || [39.8283, -98.5795];
            const zoom = config.zoom || 5;

            this.map = L.map(this.$refs.map, {
                zoomControl: true,
                attributionControl: true,
            }).setView(center, zoom);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors',
                maxZoom: 19,
            }).addTo(this.map);

            // Fit to user's territory bounds if provided (instead of GPS centering)
            if (config.bounds) {
                this.map.fitBounds(config.bounds, { padding: [30, 30] });
            }

            // Draw tool for territory boundary creation
            if (config.draw) {
                this.drawnItems = new L.FeatureGroup();
                this.map.addLayer(this.drawnItems);

                this.drawControl = new L.Control.Draw({
                    edit: { featureGroup: this.drawnItems },
                    draw: {
                        polygon: { allowIntersection: false, showArea: true },
                        polyline: false,
                        circle: false,
                        rectangle: false,
                        marker: false,
                        circlemarker: false,
                    },
                });
                this.map.addControl(this.drawControl);

                this.map.on(L.Draw.Event.CREATED, (e) => {
                    this.drawnItems.clearLayers();
                    this.drawnItems.addLayer(e.layer);
                    const geoJson = e.layer.toGeoJSON();
                    this.$dispatch('polygon-created', { boundary: geoJson.geometry });
                });
            }

            // Click-to-place pin for door knock recording
            if (config.clickToPlace) {
                this.map.on('click', (e) => {
                    // Enforce 1000-foot (304.8m) radius when tracking with GPS
                    if (config.trackLocation && this.userLocationMarker) {
                        const userPos = this.userLocationMarker.getLatLng();
                        const dist = this.haversineMeters(userPos.lat, userPos.lng, e.latlng.lat, e.latlng.lng);
                        if (dist > 304.8) {
                            this.tooFar = true;
                            setTimeout(() => { this.tooFar = false; }, 3000);
                            return;
                        }
                    }
                    this.placePin(e.latlng.lat, e.latlng.lng);
                    this.$dispatch('map-clicked', { lat: e.latlng.lat, lng: e.latlng.lng });
                });
            }

            // Listen for Livewire events
            this.$wire?.on('markers-updated', (data) => {
                const payload = Array.isArray(data) ? data[0] : data;
                this.updateMarkers(payload);
            });

            this.$wire?.on('territory-polygons', (data) => {
                const payload = Array.isArray(data) ? data[0] : data;
                this.drawPolygons(payload);
            });

            // Listen for pin placement from GPS
            this.$wire?.on('pin-placed', (data) => {
                const payload = Array.isArray(data) ? data[0] : data;
                if (payload?.lat && payload?.lng) {
                    this.placePin(payload.lat, payload.lng);
                    this.map.setView([payload.lat, payload.lng], 17);
                }
            });

            // Listen for address detection result (stop detection flow)
            this.$wire?.on('address-detected', (data) => {
                const payload = Array.isArray(data) ? data[0] : data;
                if (payload?.address) {
                    this.detectedAddress = payload.address;
                }
            });

            // Listen for parent context overlay
            this.$wire?.on('show-parent-context', (data) => {
                const payload = Array.isArray(data) ? data[0] : data;
                if (payload?.boundary) {
                    this.showParentContext(payload);
                } else {
                    this.clearParentContext();
                }
            });

            // Draw initial polygons if passed via config
            if (config.polygons) {
                this.drawPolygons({ polygons: config.polygons });
            }

            // Viewport tracking for marker loading (moveend fires on pan/zoom)
            if (config.clickToPlace) {
                this.map.on('moveend', () => {
                    const bounds = this.map.getBounds();
                    this.$dispatch('viewport-changed', {
                        neLat: bounds.getNorthEast().lat,
                        neLng: bounds.getNorthEast().lng,
                        swLat: bounds.getSouthWest().lat,
                        swLng: bounds.getSouthWest().lng,
                    });
                });
            }

            // Handle map resize
            setTimeout(() => this.map.invalidateSize(), 200);

            // Auto-request geolocation if configured
            // 'geolocate' = center map on user location only
            // 'geolocatePin' = center AND drop a pin (for door knock map)
            if (config.geolocate || config.geolocatePin) {
                setTimeout(() => {
                    this.requestGeolocation(config.geolocate && !config.geolocatePin);
                }, 300);
            }

            // Fire initial viewport update for marker loading
            if (config.clickToPlace) {
                setTimeout(() => {
                    if (this.map) {
                        const bounds = this.map.getBounds();
                        this.$dispatch('viewport-changed', {
                            neLat: bounds.getNorthEast().lat,
                            neLng: bounds.getNorthEast().lng,
                            swLat: bounds.getSouthWest().lat,
                            swLng: bounds.getSouthWest().lng,
                        });
                    }
                }, 500);
            }

            // Drag detection: disable follow mode when user manually pans
            this.map.on('dragstart', () => {
                this.followMode = false;
            });

            // Auto-start continuous tracking if configured (door knock page)
            if (config.trackLocation) {
                setTimeout(() => this.startTracking(), 300);
            }
        },

        // Place or move the pin marker
        placePin(lat, lng) {
            if (!this.map) return;

            if (this.pinMarker) {
                this.pinMarker.setLatLng([lat, lng]);
            } else {
                const pinIcon = L.divIcon({
                    className: 'pin-marker',
                    html: '<div style="background:#EF4444;width:32px;height:32px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:3px solid white;box-shadow:0 3px 8px rgba(0,0,0,0.4);position:relative;"><div style="background:white;width:10px;height:10px;border-radius:50%;position:absolute;top:8px;left:8px;"></div></div>',
                    iconSize: [32, 32],
                    iconAnchor: [16, 32],
                });

                this.pinMarker = L.marker([lat, lng], { icon: pinIcon }).addTo(this.map);
            }

            this.map.setView([lat, lng], Math.max(this.map.getZoom(), 16));
        },

        // Remove pin marker
        clearPin() {
            if (this.pinMarker) {
                this.map.removeLayer(this.pinMarker);
                this.pinMarker = null;
            }
        },

        // Request browser geolocation with feedback
        requestGeolocation(centerOnly = false) {
            if (!navigator.geolocation) {
                this.gpsStatus = 'unavailable';
                return;
            }

            this.gpsStatus = 'requesting';

            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    this.gpsStatus = 'granted';
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;

                    if (centerOnly) {
                        // Just center the map (used for initial load)
                        this.map.setView([lat, lng], 15);
                    } else {
                        // Place pin and notify Livewire
                        this.placePin(lat, lng);
                        this.$dispatch('map-clicked', { lat, lng });
                    }

                    // Show user location blue dot
                    if (this.userLocationMarker) {
                        this.userLocationMarker.setLatLng([lat, lng]);
                    } else {
                        const locIcon = L.divIcon({
                            className: 'user-location',
                            html: '<div style="background:#3B82F6;width:14px;height:14px;border-radius:50%;border:3px solid white;box-shadow:0 0 0 2px rgba(59,130,246,0.3),0 2px 4px rgba(0,0,0,0.2);"></div>',
                            iconSize: [14, 14],
                            iconAnchor: [7, 7],
                        });
                        this.userLocationMarker = L.marker([lat, lng], { icon: locIcon, zIndexOffset: 1000 }).addTo(this.map);
                    }
                },
                (err) => {
                    if (err.code === 1) {
                        this.gpsStatus = 'denied';
                    } else {
                        this.gpsStatus = 'unavailable';
                    }
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 }
            );
        },

        // Start continuous GPS tracking via watchPosition
        startTracking() {
            if (!navigator.geolocation) {
                this.gpsStatus = 'unavailable';
                return;
            }

            this.gpsStatus = 'requesting';
            this.followMode = true;

            this.watchId = navigator.geolocation.watchPosition(
                (pos) => {
                    this.gpsStatus = 'granted';
                    this.updateUserPosition(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy);
                },
                (err) => {
                    if (err.code === 1) {
                        this.gpsStatus = 'denied';
                    } else {
                        this.gpsStatus = 'unavailable';
                    }
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 3000 }
            );

            // Start stop detection check every 2 seconds
            this.stopCheckInterval = setInterval(() => this.checkForStop(), 2000);
        },

        // Stop GPS tracking
        stopTracking() {
            if (this.watchId !== null) {
                navigator.geolocation.clearWatch(this.watchId);
                this.watchId = null;
            }
            if (this.stopCheckInterval) {
                clearInterval(this.stopCheckInterval);
                this.stopCheckInterval = null;
            }
            this.lastPositions = [];
            this.gpsStatus = 'idle';
        },

        // Update user position blue dot and accuracy circle
        updateUserPosition(lat, lng, accuracy) {
            if (!this.map) return;

            // Update or create blue dot
            if (this.userLocationMarker) {
                this.userLocationMarker.setLatLng([lat, lng]);
            } else {
                const locIcon = L.divIcon({
                    className: 'user-location',
                    html: '<div style="background:#3B82F6;width:14px;height:14px;border-radius:50%;border:3px solid white;box-shadow:0 0 0 2px rgba(59,130,246,0.3),0 2px 4px rgba(0,0,0,0.2);"></div>',
                    iconSize: [14, 14],
                    iconAnchor: [7, 7],
                });
                this.userLocationMarker = L.marker([lat, lng], { icon: locIcon, zIndexOffset: 1000 }).addTo(this.map);
            }

            // Update or create accuracy circle
            if (this.userAccuracyCircle) {
                this.userAccuracyCircle.setLatLng([lat, lng]).setRadius(accuracy);
            } else {
                this.userAccuracyCircle = L.circle([lat, lng], {
                    radius: accuracy,
                    color: '#3B82F6',
                    fillColor: '#3B82F6',
                    fillOpacity: 0.1,
                    weight: 1,
                    interactive: false,
                }).addTo(this.map);
            }

            // Auto-center if follow mode is on
            if (this.followMode) {
                this.map.setView([lat, lng], Math.max(this.map.getZoom(), 17));
            }

            // Record position for stop detection
            this.lastPositions.push({ lat, lng, time: Date.now() });
            // Keep only last 30 seconds of positions
            const cutoff = Date.now() - 30000;
            this.lastPositions = this.lastPositions.filter(p => p.time > cutoff);
        },

        // Check if user has stopped moving (~15 seconds within 10m radius)
        checkForStop() {
            if (this.lastPositions.length < 2) return;

            const now = Date.now();
            const recentPositions = this.lastPositions.filter(p => p.time > now - 15000);
            if (recentPositions.length < 2) return;

            // Check if all recent positions are within 10 meters of each other
            const first = recentPositions[0];
            const allNearby = recentPositions.every(p =>
                this.haversineMeters(first.lat, first.lng, p.lat, p.lng) < 10
            );
            if (!allNearby) return;

            // Ensure the oldest recent position is at least 12 seconds old
            if (now - recentPositions[0].time < 12000) return;

            // Calculate center of the stop cluster
            const avgLat = recentPositions.reduce((s, p) => s + p.lat, 0) / recentPositions.length;
            const avgLng = recentPositions.reduce((s, p) => s + p.lng, 0) / recentPositions.length;

            // Skip if we already geocoded this spot (within 15m)
            if (this.lastGeocodedPosition) {
                const dist = this.haversineMeters(avgLat, avgLng, this.lastGeocodedPosition.lat, this.lastGeocodedPosition.lng);
                if (dist < 15) return;
            }

            this.onStopDetected(avgLat, avgLng);
        },

        // Handle stop detection - show address suggestion, wait for user to confirm
        onStopDetected(lat, lng) {
            this.lastGeocodedPosition = { lat, lng };
            this.detectedStopPosition = { lat, lng };
            this.detectedAddress = '';
            this.stopDetected = true;

            // Ask Livewire to reverse-geocode without dropping a pin
            this.$wire.detectAddress(lat, lng);
        },

        // User confirms the stop-detected pin drop
        confirmStopPin() {
            if (!this.detectedStopPosition) return;
            const { lat, lng } = this.detectedStopPosition;
            this.placePin(lat, lng);
            this.$dispatch('map-clicked', { lat, lng });
            this.dismissStopDetection();
        },

        // Dismiss stop detection toast
        dismissStopDetection() {
            this.stopDetected = false;
            this.detectedStopPosition = null;
            this.detectedAddress = '';
        },

        // Haversine distance in meters between two lat/lng points
        haversineMeters(lat1, lng1, lat2, lng2) {
            const R = 6371000;
            const toRad = (deg) => deg * Math.PI / 180;
            const dLat = toRad(lat2 - lat1);
            const dLng = toRad(lng2 - lng1);
            const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        },

        // Re-center map on current position and re-enable follow mode
        recenter() {
            this.followMode = true;
            if (this.userLocationMarker) {
                const pos = this.userLocationMarker.getLatLng();
                this.map.setView(pos, Math.max(this.map.getZoom(), 17));
            }
        },

        updateMarkers(markerData) {
            // Clear existing knock markers (not pin or location markers)
            Object.values(this.markers).forEach(m => this.map.removeLayer(m));
            this.markers = {};

            const items = markerData?.markers || markerData || [];
            if (!Array.isArray(items)) return;

            items.forEach(m => {
                const color = m.color || '#3B82F6';
                const icon = L.divIcon({
                    className: 'knock-marker',
                    html: `<div style="background:${color};width:20px;height:20px;border-radius:50%;border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,0.3);"></div>`,
                    iconSize: [20, 20],
                    iconAnchor: [10, 10],
                });

                const marker = L.marker([m.lat, m.lng], { icon }).addTo(this.map);

                if (m.popup) {
                    marker.bindPopup(m.popup);
                }

                if (m.id) {
                    this.markers[m.id] = marker;
                }
            });
        },

        drawPolygons(polygonData) {
            // Clear existing polygons
            this.polygonLayers.forEach(l => this.map.removeLayer(l));
            this.polygonLayers = [];

            // Also clear drawn items (user's in-progress drawing) since saved polygons come from DB now
            if (this.drawnItems) {
                this.drawnItems.clearLayers();
            }

            const items = polygonData?.polygons || [];
            if (!Array.isArray(items) || items.length === 0) return;

            // Sort: regions first (bottom), zones middle, blocks last (top) for proper z-layering
            const levelOrder = { region: 0, zone: 1, block: 2 };
            const sorted = [...items].sort((a, b) => (levelOrder[a.level] ?? 1) - (levelOrder[b.level] ?? 1));

            const bounds = [];
            const levelLabels = { region: 'Region', zone: 'Zone', block: 'Block' };

            sorted.forEach(p => {
                if (p.boundary?.coordinates?.[0]) {
                    const coords = p.boundary.coordinates[0].map(c => [c[1], c[0]]);

                    // Lightweight styling so all 3 levels are visible simultaneously without blocking each other
                    // Regions: bold border, barely-there fill (large area, just shows the outline)
                    // Zones: medium border, subtle fill
                    // Blocks: thin dashed border, visible fill (small area, needs to stand out)
                    const levelStyles = {
                        region: { weight: 4, dashArray: null, opacity: 0.7, fillOpacity: 0.03 },
                        zone: { weight: 2.5, dashArray: null, opacity: 0.6, fillOpacity: 0.06 },
                        block: { weight: 1.5, dashArray: '5, 5', opacity: 0.6, fillOpacity: 0.15 },
                    };
                    const style = levelStyles[p.level] || levelStyles.block;

                    const polygon = L.polygon(coords, {
                        color: p.color || '#3B82F6',
                        fillColor: p.color || '#3B82F6',
                        weight: style.weight,
                        dashArray: style.dashArray,
                        opacity: style.opacity,
                        fillOpacity: style.fillOpacity,
                    }).addTo(this.map);

                    const label = levelLabels[p.level] || '';
                    if (p.name) {
                        polygon.bindTooltip(`${p.name}${label ? ' (' + label + ')' : ''}`, {
                            permanent: false,
                            direction: 'center',
                            className: 'territory-label',
                        });

                        // Popup with assignment info
                        let popupContent = `<div class="font-semibold">${p.name}</div>`;
                        if (p.level_label) {
                            popupContent += `<div class="text-xs text-gray-500">${p.level_label}</div>`;
                        }
                        if (p.assigned_user) {
                            const assignLabel = p.level === 'block' ? 'Assigned' : 'Manager';
                            popupContent += `<div class="text-xs mt-1">${assignLabel}: ${p.assigned_user}</div>`;
                        }
                        polygon.bindPopup(popupContent);
                    }

                    this.polygonLayers.push(polygon);
                    bounds.push(...coords);
                }
            });

            // Fit map to show all polygons
            if (bounds.length > 0 && !config.noAutoFit) {
                this.map.fitBounds(L.latLngBounds(bounds).pad(0.1));
            }
        },

        showParentContext(parentData) {
            this.clearParentContext();
            if (!this.map || !parentData?.boundary?.coordinates?.[0]) return;

            const coords = parentData.boundary.coordinates[0].map(c => [c[1], c[0]]);
            const color = parentData.color || '#6B7280';

            const polygon = L.polygon(coords, {
                color: color,
                fillColor: color,
                weight: 3,
                dashArray: '8, 6',
                opacity: 0.7,
                fillOpacity: 0.05,
                interactive: false,
            }).addTo(this.map);

            if (parentData.name) {
                polygon.bindTooltip(parentData.name + ' (parent)', {
                    permanent: true,
                    direction: 'center',
                    className: 'territory-parent-label',
                });
            }

            this.contextLayers.push(polygon);
            this.map.fitBounds(L.latLngBounds(coords).pad(0.1));
        },

        clearParentContext() {
            this.contextLayers.forEach(l => this.map?.removeLayer(l));
            this.contextLayers = [];
        },

        destroy() {
            this.stopTracking();
            this.clearParentContext();
            if (this.userAccuracyCircle) {
                this.map?.removeLayer(this.userAccuracyCircle);
                this.userAccuracyCircle = null;
            }
            if (this.map) {
                this.map.remove();
                this.map = null;
            }
        }
    }));

    Alpine.data('offerCountdown', (expiresAt) => ({
        remaining: 0,
        interval: null,
        expired: false,

        init() {
            this.tick();
            this.interval = setInterval(() => this.tick(), 1000);
        },

        tick() {
            this.remaining = Math.max(0, Math.floor((new Date(expiresAt) - Date.now()) / 1000));
            if (this.remaining <= 0) {
                clearInterval(this.interval);
                this.expired = true;
            }
        },

        get display() {
            const m = Math.floor(this.remaining / 60);
            const s = this.remaining % 60;
            return `${m}:${s.toString().padStart(2, '0')}`;
        },

        get isUrgent() {
            return this.remaining > 0 && this.remaining <= 60;
        },

        destroy() {
            if (this.interval) clearInterval(this.interval);
        }
    }));
});
