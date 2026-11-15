import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller: "home-map"
 * Manages the interactive Leaflet map search on the homepage.
 * - Tabs toggle (Formulaire | Carte)
 * - CartoDB Dark Matter / Positron tiles dynamically switching on theme:changed
 * - Lime #C6F432 city markers (>= 44px touch target)
 * - Click 1 = Departure, Click 2 = Arrival
 * - Straight lime line between selected cities
 * - Public API /api/map/trips integration with skeleton, empty, error states
 * - Keyboard accessible alternative selects
 * - Respect of prefers-reduced-motion
 */
export default class extends Controller {
    static targets = [
        'formPane',
        'mapPane',
        'formTabBtn',
        'mapTabBtn',
        'canvas',
        'mapHint',
        'fromBadge',
        'toBadge',
        'dateInput',
        'swapBtn',
        'resetBtn',
        'keyboardFrom',
        'keyboardTo',
        'resultsContainer',
        'resultsCount',
    ];

    static values = {
        cities: Array,
        apiUrl: { type: String, default: '/api/map/trips' },
    };

    static TILE_URLS = {
        dark: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
        light: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
    };

    connect() {
        this.selectedFrom = null;
        this.selectedTo = null;
        this.markers = new Map();
        this.polyline = null;
        this.mapInitialized = false;

        this.handleThemeChanged = (event) => {
            const theme = event.detail?.theme || document.documentElement.getAttribute('data-theme') || 'dark';
            const nextUrl = this.constructor.TILE_URLS[theme] || this.constructor.TILE_URLS.dark;
            if (this.tileLayer) {
                this.tileLayer.setUrl(nextUrl);
            }
        };
        window.addEventListener('theme:changed', this.handleThemeChanged);

        // Pre-fill date input with today or +2 days if empty
        if (this.hasDateInputTarget && !this.dateInputTarget.value) {
            const defaultDate = new Date();
            defaultDate.setDate(defaultDate.getDate() + 2);
            this.dateInputTarget.value = defaultDate.toISOString().slice(0, 10);
        }
    }

    disconnect() {
        window.removeEventListener('theme:changed', this.handleThemeChanged);
        if (this.map) {
            this.map.remove();
            this.map = null;
        }
    }

    showFormTab() {
        if (this.hasFormPaneTarget) this.formPaneTarget.classList.remove('d-none');
        if (this.hasMapPaneTarget) this.mapPaneTarget.classList.add('d-none');
        if (this.hasFormTabBtnTarget) {
            this.formTabBtnTarget.classList.add('active', 'btn-lime');
            this.formTabBtnTarget.classList.remove('btn-outline-light');
            this.formTabBtnTarget.setAttribute('aria-selected', 'true');
        }
        if (this.hasMapTabBtnTarget) {
            this.mapTabBtnTarget.classList.remove('active', 'btn-lime');
            this.mapTabBtnTarget.classList.add('btn-outline-light');
            this.mapTabBtnTarget.setAttribute('aria-selected', 'false');
        }
    }

    showMapTab() {
        if (this.hasFormPaneTarget) this.formPaneTarget.classList.add('d-none');
        if (this.hasMapPaneTarget) this.mapPaneTarget.classList.remove('d-none');
        if (this.hasFormTabBtnTarget) {
            this.formTabBtnTarget.classList.remove('active', 'btn-lime');
            this.formTabBtnTarget.classList.add('btn-outline-light');
            this.formTabBtnTarget.setAttribute('aria-selected', 'false');
        }
        if (this.hasMapTabBtnTarget) {
            this.mapTabBtnTarget.classList.add('active', 'btn-lime');
            this.mapTabBtnTarget.classList.remove('btn-outline-light');
            this.mapTabBtnTarget.setAttribute('aria-selected', 'true');
        }

        if (!this.mapInitialized) {
            this.initMapWhenReady();
        } else if (this.map) {
            setTimeout(() => {
                this.map.invalidateSize();
            }, 100);
        }
    }

    initMapWhenReady() {
        if (typeof window.L !== 'undefined') {
            this.buildMap();
            return;
        }

        let attempts = 0;
        const timer = setInterval(() => {
            attempts += 1;
            if (typeof window.L !== 'undefined') {
                clearInterval(timer);
                this.buildMap();
            } else if (attempts > 30) {
                clearInterval(timer);
            }
        }, 100);
    }

    buildMap() {
        const L = window.L;
        if (!L || !this.hasCanvasTarget || this.mapInitialized) {
            return;
        }

        this.mapInitialized = true;

        const currentTheme = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
        const initialTileUrl = this.constructor.TILE_URLS[currentTheme];

        // Center Morocco [31.7917, -7.0926]
        this.map = L.map(this.canvasTarget, {
            scrollWheelZoom: false,
            zoomControl: true,
            minZoom: 5,
            maxZoom: 12,
            maxBounds: [
                [20.5, -18.0],
                [36.5, -0.5],
            ],
            maxBoundsViscosity: 0.8,
        }).setView([31.7917, -7.0926], 6);

        this.tileLayer = L.tileLayer(initialTileUrl, {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions" target="_blank" rel="noopener">CARTO</a>',
            subdomains: 'abcd',
            maxZoom: 19,
        }).addTo(this.map);

        this.renderCityMarkers();
    }

    renderCityMarkers() {
        const L = window.L;
        if (!L || !this.map || !Array.isArray(this.citiesValue)) {
            return;
        }

        this.citiesValue.forEach((city) => {
            if (city.lat == null || city.lng == null) {
                return;
            }

            const icon = this.createMarkerIcon(city, 'default');
            const marker = L.marker([city.lat, city.lng], {
                icon,
                title: city.name,
                alt: `Ville : ${city.name}`,
                keyboard: true,
            }).addTo(this.map);

            marker.on('click', () => this.handleCityClick(city));
            this.markers.set(city.id, { marker, city });
        });
    }

    createMarkerIcon(city, state = 'default') {
        const L = window.L;
        let badgeHtml = '';
        let extraStyle = '';

        if (state === 'from') {
            badgeHtml = '<span class="marker-role-tag" style="position: absolute; top: -10px; left: 50%; transform: translateX(-50%); background: #0B0B0C; color: #C6F432; font-size: 10px; font-weight: 800; padding: 2px 6px; border-radius: 9999px; border: 1px solid #C6F432; white-space: nowrap;">1. DÉPART</span>';
            extraStyle = 'outline: 3px solid #FFFFFF; box-shadow: 0 0 16px rgba(198, 244, 50, 0.9); transform: scale(1.08);';
        } else if (state === 'to') {
            badgeHtml = '<span class="marker-role-tag" style="position: absolute; top: -10px; left: 50%; transform: translateX(-50%); background: #0B0B0C; color: #C6F432; font-size: 10px; font-weight: 800; padding: 2px 6px; border-radius: 9999px; border: 1px solid #C6F432; white-space: nowrap;">2. ARRIVÉE</span>';
            extraStyle = 'outline: 3px solid #FFFFFF; box-shadow: 0 0 16px rgba(198, 244, 50, 0.9); transform: scale(1.08);';
        }

        const html = `
            <div style="position: relative; display: inline-block;">
                ${badgeHtml}
                <button type="button"
                        class="city-marker-btn"
                        aria-label="Sélectionner ville de ${city.name}"
                        style="min-width: 44px; min-height: 44px; padding: 6px 12px; background-color: #C6F432; color: #0B0B0C; font-weight: 800; font-size: 13px; font-family: inherit; border: 2px solid #0B0B0C; border-radius: 9999px; box-shadow: 0 4px 14px rgba(0,0,0,0.35); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: transform 0.15s ease, box-shadow 0.15s ease; ${extraStyle}">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background-color: #0B0B0C; display: inline-block;"></span>
                    <span>${city.name}</span>
                </button>
            </div>
        `;

        return L.divIcon({
            className: 'home-map-city-divicon',
            html,
            iconSize: null,
            iconAnchor: [34, 22],
        });
    }

    handleCityClick(city) {
        if (!this.selectedFrom) {
            this.setFrom(city);
        } else if (!this.selectedTo) {
            if (this.selectedFrom.id === city.id) {
                // Clicked departure again -> reset departure
                this.resetSelection();
            } else {
                this.setTo(city);
            }
        } else {
            // Both already selected -> clicking a new city starts a new departure
            this.resetSelection();
            this.setFrom(city);
        }
    }

    setFrom(city) {
        this.selectedFrom = city;
        this.updateMarkerState(city.id, 'from');

        if (this.hasFromBadgeTarget) {
            this.fromBadgeTarget.textContent = city.name;
            this.fromBadgeTarget.classList.remove('text-muted');
            this.fromBadgeTarget.classList.add('badge-lime');
        }

        if (this.hasKeyboardFromTarget) {
            this.keyboardFromTarget.value = city.id;
        }

        if (this.hasMapHintTarget) {
            this.mapHintTarget.innerHTML = `<i class="bi bi-geo-alt-fill me-1 text-lime" aria-hidden="true"></i>Départ : <strong>${city.name}</strong>. Cliquez maintenant sur votre ville d'arrivée.`;
        }

        this.fetchTrips();
    }

    setTo(city) {
        this.selectedTo = city;
        this.updateMarkerState(city.id, 'to');

        if (this.hasToBadgeTarget) {
            this.toBadgeTarget.textContent = city.name;
            this.toBadgeTarget.classList.remove('text-muted');
            this.toBadgeTarget.classList.add('badge-lime');
        }

        if (this.hasKeyboardToTarget) {
            this.keyboardToTarget.value = city.id;
        }

        if (this.hasSwapBtnTarget) {
            this.swapBtnTarget.removeAttribute('disabled');
        }

        if (this.hasMapHintTarget) {
            this.mapHintTarget.innerHTML = `<i class="bi bi-signpost-2-fill me-1 text-lime" aria-hidden="true"></i>Itinéraire : <strong>${this.selectedFrom.name} &rarr; ${city.name}</strong>`;
        }

        this.drawRouteLine();
        this.fetchTrips();
    }

    updateMarkerState(cityId, state) {
        const item = this.markers.get(cityId);
        if (item && item.marker) {
            item.marker.setIcon(this.createMarkerIcon(item.city, state));
        }
    }

    drawRouteLine() {
        const L = window.L;
        if (!L || !this.map || !this.selectedFrom || !this.selectedTo) {
            return;
        }

        if (this.polyline) {
            this.polyline.remove();
            this.polyline = null;
        }

        const latLngs = [
            [this.selectedFrom.lat, this.selectedFrom.lng],
            [this.selectedTo.lat, this.selectedTo.lng],
        ];

        this.polyline = L.polyline(latLngs, {
            color: '#C6F432',
            weight: 4,
            dashArray: '8, 8',
            opacity: 0.95,
        }).addTo(this.map);

        const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.map.fitBounds(this.polyline.getBounds(), {
            padding: [45, 45],
            maxZoom: 9,
            animate: !reducedMotion,
        });
    }

    swapCities() {
        if (!this.selectedFrom || !this.selectedTo) {
            return;
        }

        const temp = this.selectedFrom;
        const newFrom = this.selectedTo;
        const newTo = temp;

        this.resetSelection(false);
        this.setFrom(newFrom);
        this.setTo(newTo);
    }

    resetSelection(clearUi = true) {
        if (this.selectedFrom) {
            this.updateMarkerState(this.selectedFrom.id, 'default');
        }
        if (this.selectedTo) {
            this.updateMarkerState(this.selectedTo.id, 'default');
        }

        if (this.polyline) {
            this.polyline.remove();
            this.polyline = null;
        }

        this.selectedFrom = null;
        this.selectedTo = null;

        if (clearUi) {
            if (this.hasFromBadgeTarget) {
                this.fromBadgeTarget.textContent = 'Choisir départ';
                this.fromBadgeTarget.classList.add('text-muted');
                this.fromBadgeTarget.classList.remove('badge-lime');
            }
            if (this.hasToBadgeTarget) {
                this.toBadgeTarget.textContent = 'Choisir arrivée';
                this.toBadgeTarget.classList.add('text-muted');
                this.toBadgeTarget.classList.remove('badge-lime');
            }
            if (this.hasKeyboardFromTarget) this.keyboardFromTarget.value = '';
            if (this.hasKeyboardToTarget) this.keyboardToTarget.value = '';
            if (this.hasSwapBtnTarget) this.swapBtnTarget.setAttribute('disabled', 'disabled');
            if (this.hasMapHintTarget) {
                this.mapHintTarget.innerHTML = `<i class="bi bi-cursor-fill me-1 text-lime" aria-hidden="true"></i>Cliquez sur une ville de départ`;
            }
            if (this.hasResultsCountTarget) this.resultsCountTarget.textContent = '';
            this.showInitialState();

            if (this.map) {
                const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                this.map.setView([31.7917, -7.0926], 6, { animate: !reducedMotion });
            }
        }
    }

    onKeyboardFromChange(event) {
        const id = parseInt(event.target.value, 10);
        if (isNaN(id) || id <= 0) {
            this.resetSelection();
            return;
        }
        const city = this.citiesValue.find((c) => c.id === id);
        if (city) {
            if (this.selectedFrom) this.updateMarkerState(this.selectedFrom.id, 'default');
            this.setFrom(city);
            if (this.selectedTo && this.selectedTo.id === city.id) {
                this.selectedTo = null;
                if (this.hasToBadgeTarget) {
                    this.toBadgeTarget.textContent = 'Choisir arrivée';
                    this.toBadgeTarget.classList.add('text-muted');
                }
            }
        }
    }

    onKeyboardToChange(event) {
        const id = parseInt(event.target.value, 10);
        if (isNaN(id) || id <= 0) {
            if (this.selectedTo) {
                this.updateMarkerState(this.selectedTo.id, 'default');
                this.selectedTo = null;
                if (this.hasToBadgeTarget) {
                    this.toBadgeTarget.textContent = 'Choisir arrivée';
                    this.toBadgeTarget.classList.add('text-muted');
                }
                if (this.polyline) {
                    this.polyline.remove();
                    this.polyline = null;
                }
                this.fetchTrips();
            }
            return;
        }
        const city = this.citiesValue.find((c) => c.id === id);
        if (city && this.selectedFrom && this.selectedFrom.id !== city.id) {
            this.setTo(city);
        }
    }

    onDateChange() {
        if (this.selectedFrom) {
            this.fetchTrips();
        }
    }

    retry() {
        this.fetchTrips();
    }

    async fetchTrips() {
        if (!this.selectedFrom || !this.hasResultsContainerTarget) {
            return;
        }

        const dateVal = this.hasDateInputTarget ? this.dateInputTarget.value.trim() : '';
        const params = new URLSearchParams({
            from: this.selectedFrom.id,
        });

        if (this.selectedTo) {
            params.set('to', this.selectedTo.id);
        }
        if (dateVal) {
            params.set('date', dateVal);
        }

        this.showSkeletonState();

        try {
            const url = `${this.apiUrlValue}?${params.toString()}`;
            const response = await fetch(url, {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error(`Erreur HTTP: ${response.status}`);
            }

            const data = await response.json();
            this.renderTrips(data);
        } catch (error) {
            this.showErrorState();
        }
    }

    showInitialState() {
        if (!this.hasResultsContainerTarget) return;
        this.resultsContainerTarget.innerHTML = `
            <div class="p-4 text-center text-muted">
                <i class="bi bi-map fs-1 mb-2 d-block text-lime" aria-hidden="true"></i>
                <div class="fw-bold mb-1">Sélectionnez votre itinéraire</div>
                <div class="small">Cliquez sur une ville sur la carte (1er clic = départ, 2ème clic = arrivée) ou utilisez l'alternative clavier.</div>
            </div>
        `;
    }

    showSkeletonState() {
        if (!this.hasResultsContainerTarget) return;
        this.resultsContainerTarget.innerHTML = `
            <div class="d-flex flex-column gap-3 py-2" aria-busy="true" aria-live="polite">
                ${[1, 2].map(() => `
                    <div class="p-3 rounded-3 border" style="background: var(--surface); border-color: var(--border);">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="placeholder-glow w-50"><span class="placeholder col-8 rounded"></span></div>
                            <div class="placeholder-glow w-25 text-end"><span class="placeholder col-6 rounded"></span></div>
                        </div>
                        <div class="placeholder-glow"><span class="placeholder col-12 rounded mb-2"></span></div>
                        <div class="d-flex justify-content-between align-items-center pt-2">
                            <div class="placeholder-glow w-50"><span class="placeholder col-6 rounded"></span></div>
                            <div class="placeholder-glow w-25"><span class="placeholder col-10 rounded"></span></div>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    showErrorState() {
        if (!this.hasResultsContainerTarget) return;
        this.resultsContainerTarget.innerHTML = `
            <div class="p-4 text-center">
                <i class="bi bi-exclamation-triangle-fill fs-2 text-warning mb-2 d-block" aria-hidden="true"></i>
                <div class="fw-bold mb-1">Impossible de charger les trajets</div>
                <div class="small text-muted mb-3">Une erreur réseau est survenue lors de la récupération des données.</div>
                <button type="button" class="btn btn-sm btn-outline-light" data-action="click->home-map#retry">
                    <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Réessayer
                </button>
            </div>
        `;
    }

    renderTrips(data) {
        if (!this.hasResultsContainerTarget) return;

        const trips = Array.isArray(data.trips) ? data.trips : [];
        const count = trips.length;

        if (this.hasResultsCountTarget) {
            this.resultsCountTarget.textContent = `${count} trajet${count > 1 ? 's' : ''}`;
        }

        if (count === 0) {
            const destinationText = this.selectedTo ? `vers <strong>${this.selectedTo.name}</strong>` : '';
            const dateText = data.date ? `le <strong>${data.date}</strong>` : 'à cette date';
            this.resultsContainerTarget.innerHTML = `
                <div class="p-4 text-center">
                    <div class="mx-auto mb-2 d-inline-flex align-items-center justify-content-center rounded-circle"
                         style="width: 52px; height: 52px; background: var(--surface-2); border: 1px solid var(--border);">
                        <i class="bi bi-search fs-3 text-muted" aria-hidden="true"></i>
                    </div>
                    <div class="fw-bold mb-1">Aucun trajet trouvé</div>
                    <div class="small text-muted mb-3">
                        Aucun conducteur n'a publié de trajet au départ de <strong>${data.from.name}</strong> ${destinationText} ${dateText}.
                    </div>
                    <div class="small text-muted">Essayez de modifier la date ou de choisir une autre destination.</div>
                </div>
            `;
            return;
        }

        const cardsHtml = trips.map((trip) => {
            const driverName = `${trip.driver.firstName ?? 'Conducteur'} ${trip.driver.lastNameInitial ?? ''}`;
            const timeInfo = trip.arrivalTimeFormatted
                ? `${trip.departureTimeFormatted ?? '--:--'} &rarr; ${trip.arrivalTimeFormatted}`
                : `Départ : ${trip.departureTimeFormatted ?? '--:--'}`;

            const seatsBadge = trip.availableSeats <= 1
                ? `<span class="badge badge-danger-soft"><i class="bi bi-fire me-1"></i>${trip.availableSeats} place</span>`
                : `<span class="badge badge-surface"><i class="bi bi-person-check me-1"></i>${trip.availableSeats} places</span>`;

            let destinationsHtml = '';
            if (!this.selectedTo && Array.isArray(trip.destinations) && trip.destinations.length > 0) {
                destinationsHtml = `
                    <div class="small text-muted mt-2 pt-2 border-top">
                        <span class="fw-semibold">Arrêts desservis :</span>
                        <div class="d-flex flex-wrap gap-1 mt-1">
                            ${trip.destinations.map((d) => `
                                <a href="${d.detailUrl}" class="badge bg-dark border text-decoration-none text-light py-1 px-2">
                                    ${d.cityName} (${d.portionPrice} MAD)
                                </a>
                            `).join('')}
                        </div>
                    </div>
                `;
            }

            return `
                <div class="p-3 rounded-3 border card-interactive" style="background: var(--surface); border-color: var(--border);">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <div class="fw-bold fs-6">${timeInfo}</div>
                            <div class="small text-muted">Date : ${trip.dateFormatted ?? ''} &bull; ${trip.stopsCount ?? 2} arrêts</div>
                        </div>
                        <div class="text-end">
                            <span class="badge badge-lime fs-6">${trip.portionPrice} MAD</span>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                        <div class="small">
                            <span class="text-muted">Conducteur :</span> <strong>${driverName}</strong>
                            <div class="mt-1">${seatsBadge}</div>
                        </div>
                        <a href="${trip.detailUrl}" class="btn btn-lime btn-sm">
                            Voir <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                        </a>
                    </div>
                    ${destinationsHtml}
                </div>
            `;
        }).join('');

        this.resultsContainerTarget.innerHTML = `
            <div class="d-flex flex-column gap-3 py-1">
                ${cardsHtml}
            </div>
        `;
    }
}
