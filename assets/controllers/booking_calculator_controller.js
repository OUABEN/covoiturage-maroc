import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller for the Trip Detail page:
 * - Recalculates portion unit price, total price, and available seats in real time
 *   when from_stop, to_stop, or seats change.
 * - Highlights the selected portion on the vertical stop timeline.
 * - Renders the interactive Leaflet map with CartoDB Dark Matter / Positron tiles
 *   (switching dynamically on theme:changed) and draws the Lime (#C6F432) polyline
 *   from /api/route?from=ID&to=ID.
 */
export default class extends Controller {
    static targets = [
        'fromSelect',
        'toSelect',
        'seatsInput',
        'portionLabel',
        'unitPrice',
        'totalPrice',
        'availableSeats',
        'timelineStep',
        'mapContainer',
        'mapStatus',
        'submitButton',
    ];

    static values = {
        stops: Array,
        routeEndpoint: String,
    };

    connect() {
        this.recalculate();
        this.initMap();

        this.handleThemeChange = (event) => {
            const theme = event.detail?.theme || document.documentElement.getAttribute('data-theme') || 'dark';
            this.updateMapTileLayer(theme);
        };
        window.addEventListener('theme:changed', this.handleThemeChange);
    }

    disconnect() {
        if (this.handleThemeChange) {
            window.removeEventListener('theme:changed', this.handleThemeChange);
        }
        if (this.map) {
            this.map.remove();
            this.map = null;
        }
    }

    recalculate() {
        const stops = this.stopsValue || [];
        if (stops.length < 2) {
            return;
        }

        let fromStopId = this.hasFromSelectTarget ? parseInt(this.fromSelectTarget.value, 10) : stops[0].id;
        let toStopId = this.hasToSelectTarget ? parseInt(this.toSelectTarget.value, 10) : stops[stops.length - 1].id;

        const fromStop = stops.find((s) => s.id === fromStopId) || stops[0];
        let toStop = stops.find((s) => s.id === toStopId) || stops[stops.length - 1];

        // Disable invalid destination options (stopOrder <= fromStop.order)
        if (this.hasToSelectTarget) {
            Array.from(this.toSelectTarget.options).forEach((option) => {
                const candidate = stops.find((s) => s.id === parseInt(option.value, 10));
                if (candidate) {
                    option.disabled = candidate.order <= fromStop.order;
                }
            });

            if (toStop.order <= fromStop.order) {
                const nextValid = stops.find((s) => s.order > fromStop.order) || stops[stops.length - 1];
                toStop = nextValid;
                this.toSelectTarget.value = String(toStop.id);
            }
        }

        // Compute unit price and minimum available seats across [fromStop.order, toStop.order[
        let unitPrice = 0;
        let minAvailable = Infinity;

        stops.forEach((stop) => {
            if (stop.order > fromStop.order && stop.order <= toStop.order) {
                unitPrice += parseFloat(stop.priceFromPrevious || 0);
                const segAvail = parseInt(stop.segmentAvailable ?? 0, 10);
                if (segAvail < minAvailable) {
                    minAvailable = segAvail;
                }
            }
        });

        if (!Number.isFinite(minAvailable)) {
            minAvailable = 0;
        }

        const seats = this.hasSeatsInputTarget
            ? Math.max(1, parseInt(this.seatsInputTarget.value || '1', 10))
            : 1;

        const totalPrice = unitPrice * seats;

        if (this.hasPortionLabelTarget) {
            this.portionLabelTarget.textContent = `${fromStop.cityName} → ${toStop.cityName}`;
        }

        if (this.hasUnitPriceTarget) {
            this.unitPriceTarget.textContent = `${unitPrice.toFixed(2)} MAD`;
        }

        if (this.hasTotalPriceTarget) {
            this.totalPriceTarget.textContent = `${totalPrice.toFixed(2)} MAD`;
        }

        if (this.hasAvailableSeatsTarget) {
            this.availableSeatsTarget.textContent = `${minAvailable} place(s) disponible(s)`;
        }

        // Update timeline highlighting
        if (this.hasTimelineStepTarget) {
            this.timelineStepTargets.forEach((el) => {
                const stepOrder = parseInt(el.dataset.stopOrder, 10);
                const isActive = stepOrder >= fromStop.order && stepOrder <= toStop.order;
                el.classList.toggle('is-active-portion', isActive);
            });
        }
    }

    incrementSeats() {
        if (!this.hasSeatsInputTarget) return;
        const max = parseInt(this.seatsInputTarget.max || '8', 10);
        const current = parseInt(this.seatsInputTarget.value || '1', 10);
        if (current < max) {
            this.seatsInputTarget.value = String(current + 1);
            this.recalculate();
        }
    }

    decrementSeats() {
        if (!this.hasSeatsInputTarget) return;
        const current = parseInt(this.seatsInputTarget.value || '1', 10);
        if (current > 1) {
            this.seatsInputTarget.value = String(current - 1);
            this.recalculate();
        }
    }

    async initMap() {
        if (!this.hasMapContainerTarget) {
            return;
        }

        const stops = (this.stopsValue || []).filter(
            (s) => Number.isFinite(s.lat) && Number.isFinite(s.lng)
        );

        if (typeof window.L === 'undefined' || stops.length === 0) {
            if (this.hasMapStatusTarget) {
                this.mapStatusTarget.textContent = 'Aperçu cartographique prêt (coordonnées régionales chargées).';
            }
            return;
        }

        const L = window.L;
        this.map = L.map(this.mapContainerTarget, {
            scrollWheelZoom: false,
            zoomControl: true,
        });

        const initialTheme = document.documentElement.getAttribute('data-theme') || 'dark';
        this.updateMapTileLayer(initialTheme);

        const latLngs = [];

        stops.forEach((stop, index) => {
            const point = [stop.lat, stop.lng];
            latLngs.push(point);

            const icon = L.divIcon({
                className: 'leaflet-stop-marker',
                html: `<span>${index + 1}</span>`,
                iconSize: [28, 28],
                iconAnchor: [14, 14],
            });

            const marker = L.marker(point, { icon }).addTo(this.map);
            marker.bindPopup(
                `<div class="small">
                    <strong>${index + 1}. ${stop.cityName}</strong><br>
                    <span>${stop.timeLabel || ''}</span>
                    ${index > 0 ? `<br><span class="fw-bold">+${parseFloat(stop.priceFromPrevious).toFixed(0)} MAD</span>` : '<br><span>Départ</span>'}
                </div>`
            );
        });

        if (latLngs.length >= 2) {
            this.map.fitBounds(latLngs, { padding: [36, 36] });
            await this.drawRouteSegments(stops);
        } else if (latLngs.length === 1) {
            this.map.setView(latLngs[0], 10);
        }
    }

    updateMapTileLayer(theme) {
        if (!this.map || typeof window.L === 'undefined') {
            return;
        }

        const tileUrl = theme === 'light'
            ? 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png'
            : 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';

        if (this.tileLayer) {
            this.map.removeLayer(this.tileLayer);
        }

        this.tileLayer = window.L.tileLayer(tileUrl, {
            attribution: '&copy; OpenStreetMap &copy; CARTO',
            subdomains: 'abcd',
            maxZoom: 19,
        }).addTo(this.map);
    }

    async drawRouteSegments(stops) {
        const L = window.L;
        const allCoords = [];
        let totalDistanceKm = 0;
        let totalDurationMin = 0;

        for (let i = 0; i < stops.length - 1; i++) {
            const fromStop = stops[i];
            const toStop = stops[i + 1];

            try {
                const url = `${this.routeEndpointValue || '/api/route'}?from=${encodeURIComponent(fromStop.cityId)}&to=${encodeURIComponent(toStop.cityId)}`;
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                const data = await response.json();
                if (data.distanceKm) totalDistanceKm += parseFloat(data.distanceKm);
                if (data.durationMinutes || data.durationMin) {
                    totalDurationMin += parseInt(data.durationMinutes ?? data.durationMin, 10);
                }

                if (data.geometry && Array.isArray(data.geometry.coordinates)) {
                    const segLatLngs = data.geometry.coordinates.map(([lon, lat]) => [lat, lon]);
                    allCoords.push(...segLatLngs);
                } else {
                    allCoords.push([fromStop.lat, fromStop.lng], [toStop.lat, toStop.lng]);
                }
            } catch {
                allCoords.push([fromStop.lat, fromStop.lng], [toStop.lat, toStop.lng]);
            }
        }

        if (allCoords.length >= 2 && this.map) {
            // Dark casing line underneath for contrast in both light and dark modes
            L.polyline(allCoords, {
                color: '#0B0B0C',
                weight: 8,
                opacity: 0.85,
                lineCap: 'round',
                lineJoin: 'round',
            }).addTo(this.map);

            // Signature Lime Green (#C6F432) polyline on top
            const routeLine = L.polyline(allCoords, {
                color: '#C6F432',
                weight: 4.5,
                opacity: 1,
                lineCap: 'round',
                lineJoin: 'round',
            }).addTo(this.map);

            this.map.fitBounds(routeLine.getBounds(), { padding: [36, 36] });
        }

        if (this.hasMapStatusTarget && totalDistanceKm > 0) {
            this.mapStatusTarget.textContent = `Tracé OSRM chargé : ${totalDistanceKm.toFixed(1)} km · ~${totalDurationMin} min`;
        }
    }
}
