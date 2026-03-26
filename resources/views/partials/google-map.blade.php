@php
    $mapId = 'google-map-' . uniqid();
    $mapFn = 'initGMap_' . str_replace('-', '_', $mapId);
    $apiKey = config('services.google_maps.key');
    $center = $center ?? [39.75, -104.99];
    $zoom = $zoom ?? 9;
    $highlight = $highlight ?? null;
    $lazy = $lazy ?? false;
    $mapAreas = $areas->filter(fn ($a) => $a->latitude && $a->longitude)->map(fn ($a) => [
        'city' => $a->city,
        'slug' => $a->slug,
        'lat' => (float) $a->latitude,
        'lng' => (float) $a->longitude,
    ])->values()->all();

    $company = \App\Models\CompanySetting::getInstance();
    $companyName = $company->company_name ?? config('app.name');
    $hqLat = 39.7392;
    $hqLng = -104.9903;
@endphp

<div id="{{ $mapId }}" class="w-full h-[400px] rounded-xl shadow-md z-0 bg-zinc-800 flex items-center justify-center">
    @if($lazy)
    <span class="text-zinc-400 text-sm">Loading map...</span>
    @endif
</div>

<script>
function {{ $mapFn }}() {
    var mapEl = document.getElementById('{{ $mapId }}');
    if (!mapEl || mapEl._gmap) return;
    mapEl._gmap = true;
    mapEl.innerHTML = '';

    var map = new google.maps.Map(mapEl, {
        center: { lat: {{ $center[0] }}, lng: {{ $center[1] }} },
        zoom: {{ $zoom }},
        gestureHandling: 'cooperative',
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: false,
        styles: [
            { elementType: 'geometry', stylers: [{ color: '#242f3e' }] },
            { elementType: 'labels.text.stroke', stylers: [{ color: '#242f3e' }] },
            { elementType: 'labels.text.fill', stylers: [{ color: '#746855' }] },
            { featureType: 'administrative.locality', elementType: 'labels.text.fill', stylers: [{ color: '#d59563' }] },
            { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#38414e' }] },
            { featureType: 'road', elementType: 'geometry.stroke', stylers: [{ color: '#212a37' }] },
            { featureType: 'road', elementType: 'labels.text.fill', stylers: [{ color: '#9ca5b3' }] },
            { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#746855' }] },
            { featureType: 'road.highway', elementType: 'geometry.stroke', stylers: [{ color: '#1f2835' }] },
            { featureType: 'road.highway', elementType: 'labels.text.fill', stylers: [{ color: '#f3d19c' }] },
            { featureType: 'transit', elementType: 'geometry', stylers: [{ color: '#2f3948' }] },
            { featureType: 'transit.station', elementType: 'labels.text.fill', stylers: [{ color: '#d59563' }] },
            { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#17263c' }] },
            { featureType: 'water', elementType: 'labels.text.fill', stylers: [{ color: '#515c6d' }] },
            { featureType: 'water', elementType: 'labels.text.stroke', stylers: [{ color: '#17263c' }] },
            { featureType: 'poi', elementType: 'labels', stylers: [{ visibility: 'off' }] },
            { featureType: 'poi.park', elementType: 'geometry', stylers: [{ color: '#263c3f' }] },
            { featureType: 'poi.park', elementType: 'labels.text.fill', stylers: [{ color: '#6b9a76' }] },
        ],
    });

    var areas = @json($mapAreas);
    var highlightSlug = @json($highlight);
    var baseUrl = '{{ url("/areas") }}';
    var openWindow = null;

    // HQ marker (gold)
    var hqMarker = new google.maps.Marker({
        position: { lat: {{ $hqLat }}, lng: {{ $hqLng }} },
        map: map,
        title: '{{ addslashes($companyName) }}',
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 10,
            fillColor: '#c9a84c',
            fillOpacity: 1,
            strokeColor: '#ffffff',
            strokeWeight: 2.5,
        },
        zIndex: 1000,
    });
    var hqInfo = new google.maps.InfoWindow({
        content: '<div style="text-align:center;padding:4px 8px"><strong style="font-size:13px">{{ addslashes($companyName) }}</strong><br><span style="font-size:11px;color:#888">Company HQ</span></div>',
    });
    hqMarker.addListener('click', function() {
        if (openWindow) openWindow.close();
        hqInfo.open(map, hqMarker);
        openWindow = hqInfo;
    });

    // Service area markers
    areas.forEach(function(area) {
        var isHighlighted = highlightSlug && area.slug === highlightSlug;
        var marker = new google.maps.Marker({
            position: { lat: area.lat, lng: area.lng },
            map: map,
            title: area.city,
            icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: isHighlighted ? 10 : 7,
                fillColor: isHighlighted ? '#c9a84c' : '#4a5568',
                fillOpacity: 1,
                strokeColor: '#ffffff',
                strokeWeight: isHighlighted ? 2.5 : 2,
            },
            zIndex: isHighlighted ? 999 : 1,
        });

        var infoWindow = new google.maps.InfoWindow({
            content: '<div style="text-align:center;padding:4px 8px">' +
                '<strong style="font-size:13px">' + area.city + '</strong><br>' +
                '<a href="' + baseUrl + '/' + area.slug + '" style="font-size:11px;color:#2563eb;text-decoration:none">View Services &rarr;</a>' +
                '</div>',
        });

        marker.addListener('click', function() {
            if (openWindow) openWindow.close();
            infoWindow.open(map, marker);
            openWindow = infoWindow;
        });

        if (isHighlighted) {
            infoWindow.open(map, marker);
            openWindow = infoWindow;
        }
    });
}

@if($lazy)
(function() {
    var container = document.getElementById('{{ $mapId }}');
    if (!container) return;
    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                observer.disconnect();
                // Check if Google Maps API is already loaded
                if (typeof google !== 'undefined' && google.maps) {
                    {{ $mapFn }}();
                    return;
                }
                var script = document.createElement('script');
                script.src = 'https://maps.googleapis.com/maps/api/js?key={{ $apiKey }}&callback={{ $mapFn }}';
                script.async = true;
                script.defer = true;
                document.head.appendChild(script);
            }
        });
    }, { rootMargin: '300px' });
    observer.observe(container);
})();
@else
(function() {
    function loadAndInit() {
        if (typeof google !== 'undefined' && google.maps) {
            {{ $mapFn }}();
            return;
        }
        var script = document.createElement('script');
        script.src = 'https://maps.googleapis.com/maps/api/js?key={{ $apiKey }}&callback={{ $mapFn }}';
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadAndInit);
    } else {
        loadAndInit();
    }
})();
@endif
</script>
