import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller: "trip-map"
 * Renders a Leaflet map with CartoDB Dark Matter (dark mode) / CartoDB Positron (light mode) tiles,
 * custom Lime #C6F432 stop markers, and route polylines fetched from /api/route.
 */
export default class extends Controller {
    static targets = ['canvas', 'distance', 'duration', 'status'];

    static values = {
        stops: Array,
        routeEndpoint: { type: String, default: '/api/route' },
    };

    static TILE_URLS = {
        dark: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
        light: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
    };

    connect() {
        this.handleThemeChanged = (event) => {
            const theme = event.detail?.theme || document.documentElement.getAttribute('data-theme') || 'dark';
            const nextUrl = event.detail?.tileUrl || this.constructor.TILE_URLS[theme] || this.constructor.TILE_URLS.dark;
            if (this.tileLayer) {
                this.tileLayer.setUrl(nextUrl);
            }
        };
        window.addEventListener('theme:changed', this.handleThemeChanged);

        this.initMapWhenReady();
    }

    disconnect() {
        window.removeEventListener('theme:changed', this.handleThemeChanged);
        if (this.map) {
            this.map.remove();
            this.map = null;
        }
    }

    initMapWhenReady() {
        if (typeof window.L !== 'undefined') {
            this.renderMap();
            return;
        }

        // Wait briefly if Leaflet script is loading asynchronously
        let attempts = 0;
        const timer = setInterval(() => {
            attempts += 1;
            if (typeof window.L !== 'undefined') {
                clearInterval(timer);
                this.renderMap();
            } else if (attempts > 25) {
                clearInterval(timer);
            }
        }, 120);
    }

    async renderMap() {
        const L = window.L;
        if (!L || !this.hasCanvasTarget || !Array.isArray(this.stopsValue) || this.stopsValue.length === 0) {
            return;
        }

        const currentTheme = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
        const initialTileUrl = this.constructor.TILE_URLS[currentTheme];

        const firstStop = this.stopsValue[0];
        this.map = L.map(this.canvasTarget, {
            scrollWheelZoom: false,
            zoomControl: true,
        }).setView([firstStop.lat, firstStop.lon], 7);

        this.tileLayer = L.tileLayer(initialTileUrl, {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
            subdomains: 'abcd',
            maxZoom: 19,
        }).addTo(this.map);

        const latLngBounds = [];

        this.stopsValue.forEach((stop, index) => {
            const lat = parseFloat(stop.lat);
            const lon = parseFloat(stop.lon);
            if (Number.isNaN(lat) || Number.isNaN(lon)) {
                return;
            }
            latLngBounds.push([lat, lon]);

            const icon = L.divIcon({
                className: 'leaflet-custom-div-icon',
                html: `<div class="leaflet-stop-pin" title="${stop.name}">${index + 1}</div>`,
                iconSize: [30, 30],
                iconAnchor: [15, 15],
                popupAnchor: [0, -15],
            });

            const timeLabel = stop.departureTime
                ? `Départ : ${stop.departureTime}`
                : (stop.arrivalTime ? `Arrivée : ${stop.arrivalTime}` : '');

            L.marker([lat, lon], { icon })
                .addTo(this.map)
                .bindPopup(
                    `<div class="small">
                        <strong>Étape ${index + 1} · ${stop.name}</strong><br>
                        <span class="text-muted">${timeLabel}</span>
                    </div>`
                );
        });

        if (latLngBounds.length > 1) {
            this.map.fitBounds(latLngBounds, { padding: [36, 36] });
        }

        await this.loadSegmentsFromApi(L, latLngBounds);
    }

    async loadSegmentsFromApi(L, fallbackLatLngs) {
        if (this.stopsValue.length < 2) {
            return;
        }

        let totalDistanceKm = 0;
        let totalDurationMin = 0;
        let anyGeometryDrawn = false;

        for (let i = 0; i < this.stopsValue.length - 1; i += 1) {
            const fromStop = this.stopsValue[i];
            const toStop = this.stopsValue[i + 1];

            try {
                const url = `${this.routeEndpointValue}?from=${encodeURIComponent(fromStop.cityId)}&to=${encodeURIComponent(toStop.cityId)}`;
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    continue;
                }

                const data = await response.json();
                if (typeof data.distanceKm === 'number') {
                    totalDistanceKm += data.distanceKm;
                }
                if (typeof data.durationMinutes === 'number') {
                    totalDurationMin += data.durationMinutes;
                }

                if (data.geometry && Array.isArray(data.geometry.coordinates)) {
                    // Dark casing underneath for WCAG contrast in both light and dark modes
                    L.geoJSON(data.geometry, {
                        style: {
                            color: '#0B0B0C',
                            weight: 8,
                            opacity: 0.75,
                        },
                    }).addTo(this.map);

                    // Signature Lime #C6F432 route line
                    L.geoJSON(data.geometry, {
                        style: {
                            color: '#C6F432',
                            weight: 5,
                            opacity: 0.98,
                        },
                    }).addTo(this.map);

                    anyGeometryDrawn = true;
                }
            } catch (_) {
                // Fallback handled below
            }
        }

        if (!anyGeometryDrawn && fallbackLatLngs.length > 1) {
            L.polyline(fallbackLatLngs, {
                color: '#0B0B0C',
                weight: 7,
                opacity: 0.7,
            }).addTo(this.map);

            L.polyline(fallbackLatLngs, {
                color: '#C6F432',
                weight: 4,
                dashArray: '8, 8',
                opacity: 0.95,
            }).addTo(this.map);
        }

        if (totalDistanceKm > 0 && this.hasDistanceTarget) {
            this.distanceTarget.textContent = `${totalDistanceKm.toFixed(1)} km`;
        }

        if (totalDurationMin > 0 && this.hasDurationTarget) {
            const hours = Math.floor(totalDurationMin / 60);
            const mins = totalDurationMin % 60;
            this.durationTarget.textContent = hours > 0 ? `${hours}h${mins.toString().padStart(2, '0')}` : `${mins} min`;
        }
    }
}
