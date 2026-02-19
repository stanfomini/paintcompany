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
        drawnItems: null,
        drawControl: null,
        pinMarker: null,
        gpsStatus: 'idle', // idle, requesting, granted, denied, unavailable
        userLocationMarker: null,

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
                        this.userLocationMarker = L.marker([lat, lng], { icon: locIcon, zIndexOffset: -1000 }).addTo(this.map);
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

            const items = polygonData?.polygons || [];
            if (!Array.isArray(items) || items.length === 0) return;

            const bounds = [];

            items.forEach(p => {
                if (p.boundary?.coordinates?.[0]) {
                    const coords = p.boundary.coordinates[0].map(c => [c[1], c[0]]);
                    const polygon = L.polygon(coords, {
                        color: p.color || '#3B82F6',
                        weight: 2,
                        fillOpacity: 0.15,
                    }).addTo(this.map);

                    if (p.name) {
                        polygon.bindPopup(`<strong>${p.name}</strong>`);
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

        destroy() {
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
