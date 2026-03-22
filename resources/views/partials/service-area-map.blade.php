@php
    $mapId = 'service-area-map-' . uniqid();
    $locationiqKey = config('services.locationiq.key');
    $center = $center ?? [39.75, -104.99];
    $zoom = $zoom ?? 9;
    $highlight = $highlight ?? null;
    $mapAreas = $areas->filter(fn ($a) => $a->latitude && $a->longitude)->map(fn ($a) => [
        'city' => $a->city,
        'slug' => $a->slug,
        'lat' => (float) $a->latitude,
        'lng' => (float) $a->longitude,
    ])->values()->all();

    $company = \App\Models\CompanySetting::getInstance();
    $companyName = $company->company_name ?? config('app.name');
    // Company HQ uses Denver coordinates (virtual office)
    $hqLat = 39.7392;
    $hqLng = -104.9903;
@endphp

<div id="{{ $mapId }}" class="w-full h-[400px] rounded-xl shadow-md z-0"></div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var mapEl = document.getElementById('{{ $mapId }}');
        if (!mapEl || mapEl._leaflet_id) return;

        var map = L.map('{{ $mapId }}', {
            scrollWheelZoom: false,
        }).setView([{{ $center[0] }}, {{ $center[1] }}], {{ $zoom }});

        @if($locationiqKey)
            L.tileLayer('https://{s}-tiles.locationiq.com/v3/streets/r/{z}/{x}/{y}.png?key={{ $locationiqKey }}', {
                attribution: '&copy; <a href="https://locationiq.com">LocationIQ</a> &copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>',
                subdomains: ['a', 'b', 'c'],
                maxZoom: 18,
            }).addTo(map);
        @else
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 18,
            }).addTo(map);
        @endif

        var areas = {!! json_encode($mapAreas) !!};
        var highlight = {!! json_encode($highlight) !!};

        areas.forEach(function (area) {
            var isHighlighted = highlight && area.slug === highlight;
            var marker = L.circleMarker([area.lat, area.lng], {
                radius: isHighlighted ? 10 : 7,
                fillColor: isHighlighted ? '#10b981' : '#2563eb',
                color: '#fff',
                weight: 2,
                fillOpacity: isHighlighted ? 1 : 0.85,
            }).addTo(map);

            var url = '{{ url("/areas") }}/' + area.slug;
            marker.bindPopup(
                '<div class="text-center">' +
                '<strong class="text-sm">' + area.city + '</strong><br>' +
                '<a href="' + url + '" class="text-blue-600 text-xs hover:underline">View Services &rarr;</a>' +
                '</div>'
            );

            if (isHighlighted) {
                marker.openPopup();
            }
        });

        // Company HQ marker
        var hqIcon = L.divIcon({
            className: '',
            html: '<div style="background:#f59e0b;width:18px;height:18px;border-radius:50%;border:3px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center">' +
                  '<svg width="10" height="10" viewBox="0 0 24 24" fill="#fff"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 010-5 2.5 2.5 0 010 5z"/></svg>' +
                  '</div>',
            iconSize: [18, 18],
            iconAnchor: [9, 9],
        });
        L.marker([{{ $hqLat }}, {{ $hqLng }}], { icon: hqIcon, zIndexOffset: 1000 })
            .addTo(map)
            .bindPopup('<div class="text-center"><strong class="text-sm">{{ $companyName }}</strong><br><span class="text-xs text-zinc-500">Company HQ</span></div>');
    });
</script>
