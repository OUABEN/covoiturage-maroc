import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller: "home-map"
 * Interactive Morocco network map built on local WebP image (carte-maroc.webp) via Leaflet CRS.Simple,
 * with clickable lime #C6F432 city markers (>= 44px touch target), straight route line,
 * city-photo selection cards in the side panel, /api/map/trips integration, and keyboard accessibility.
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
        'selectedCityPreview',
        'resultsContainer',
        'resultsCount',
    ];

    static values = {
        cities: Array,
        apiUrl: { type: String, default: '/api/map/trips' },
    };

    // Dimensions of carte-maroc.webp coordinate space: width=1000, height=558
    static MAP_WIDTH = 1000;
    static MAP_HEIGHT = 558;

    // Calibrated (x, y from top-left) on carte-maroc.webp matching Morocco's illuminated city nodes
    static CITY_MAP_POINTS = {
        rabat: { x: 351, y: 119 },
        fes: { x: 407, y: 119 },
        casablanca: { x: 327, y: 136 },
        'el-jadida': { x: 302, y: 146 },
        safi: { x: 284, y: 175 },
        essaouira: { x: 268, y: 199 },
        marrakech: { x: 318, y: 198 },
        agadir: { x: 272, y: 240 },
        taroudant: { x: 296, y: 238 },
        tiznit: { x: 266, y: 263 },
    };

    // City slugs that have a local WebP photo in /images/cities/
    static CITY_PHOTOS = {
        agadir: { slug: 'agadir', alt: "Plage et baie d'Agadir au coucher du soleil" },
        casablanca: { slug: 'casablanca', alt: 'Mosquée Hassan II de Casablanca au crépuscule' },
        marrakech: { slug: 'marrakech', alt: 'Minaret de la Koutoubia à Marrakech' },
        rabat: { slug: 'rabat', alt: 'Ruelle bleue et blanche de la kasbah des Oudayas à Rabat' },
        fes: { slug: 'fes', alt: 'Vue panoramique sur la médina de Fès au coucher du soleil' },
        essaouira: { slug: 'essaouira', alt: 'Remparts de la Skala et barques bleues du port d’Essaouira' },
        safi: { slug: 'safi', alt: 'Forteresse côtière sur la falaise de Safi' },
    };

    connect() {
        this.selectedFrom = null;
        this.selectedTo = null;
        this.markers = new Map();
        this.polyline = null;
        this.mapInitialized = false;

        // Pre-fill date input with today + 2 days if empty
        if (this.hasDateInputTarget && !this.dateInputTarget.value) {
            const defaultDate = new Date();
            defaultDate.setDate(defaultDate.getDate() + 2);
            this.dateInputTarget.value = defaultDate.toISOString().slice(0, 10);
        }
    }

    disconnect() {
        if (this.map) {
            this.map.remove();
            this.map = null;
        }
    }

    normalizeCityKey(name) {
        return String(name || '')
            .trim()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\s+/g, '-');
    }

    getCityMapLatLng(city) {
        const key = this.normalizeCityKey(city.name);
        const calibrated = this.constructor.CITY_MAP_POINTS[key];
        let px;
        let py;

        if (calibrated) {
            px = calibrated.x;
            py = calibrated.y;
        } else if (city.lat != null && city.lng != null) {
            // Linear fallback derived from calibrated Moroccan cities on carte-maroc.webp
            px = 345 + (Number(city.lng) + 7.0) * 30.6;
            py = 119 + (34.02 - Number(city.lat)) * 33.1;
        } else {
            return null;
        }

        // CRS.Simple y-axis runs upward from bottom (0) to top (MAP_HEIGHT)
        return [this.constructor.MAP_HEIGHT - py, px];
    }

    renderCityThumbHtml(city, height = 76) {
        const key = this.normalizeCityKey(city.name);
        const photo = this.constructor.CITY_PHOTOS[key];
        if (photo) {
            return `
                <div class="city-thumb-wrapper position-relative overflow-hidden" style="background: linear-gradient(135deg, #0B0B0C 0%, #1C1C1F 100%); height: ${height}px;">
                    <img src="/images/cities/${photo.slug}-sm.webp"
                         srcset="/images/cities/${photo.slug}-sm.webp 800w, /images/cities/${photo.slug}.webp 1600w"
                         sizes="220px"
                         width="300"
                         height="${height}"
                         loading="lazy"
                         decoding="async"
                         alt="${photo.alt}"
                         class="w-100 h-100 object-fit-cover d-block"
                         onerror="this.style.display='none'; this.nextElementSibling.classList.remove('d-none'); this.nextElementSibling.classList.add('d-flex');">
                    <div class="d-none flex-column align-items-center justify-content-center h-100 p-2 text-center"
                         style="background: radial-gradient(circle at 80% 20%, rgba(198, 244, 50, 0.14), transparent 55%), linear-gradient(135deg, #0B0B0C 0%, #1C1C1F 100%); color: #FFFFFF;">
                        <span class="badge badge-lime mb-1" style="font-size: 10px;">Maroc</span>
                        <span class="fw-bold small">${city.name}</span>
                    </div>
                </div>
            `;
        }

        return `
            <div class="d-flex flex-column align-items-center justify-content-center p-2 text-center"
                 style="height: ${height}px; background: radial-gradient(circle at 80% 20%, rgba(198, 244, 50, 0.14), transparent 55%), linear-gradient(135deg, #0B0B0C 0%, #1C1C1F 100%); color: #FFFFFF;">
                <span class="badge badge-lime mb-1" style="font-size: 10px;"><i class="bi bi-geo-alt-fill me-1" aria-hidden="true"></i>Maroc</span>
                <span class="fw-bold small">${city.name}</span>
            </div>
        `;
    }

    updateSelectedCityPreview() {
        if (!this.hasSelectedCityPreviewTarget) {
            return;
        }

        if (!this.selectedFrom && !this.selectedTo) {
            this.selectedCityPreviewTarget.classList.add('d-none');
            this.selectedCityPreviewTarget.innerHTML = '';
            return;
        }

        const cards = [];
        if (this.selectedFrom) {
            cards.push(`
                <div class="${this.selectedTo ? 'col-6' : 'col-12'}">
                    <div class="rounded-3 overflow-hidden border position-relative" style="border-color: var(--border) !important;">
                        ${this.renderCityThumbHtml(this.selectedFrom, 72)}
                        <span class="position-absolute top-0 start-0 m-1 badge badge-lime" style="font-size: 10px;">Départ</span>
                        <div class="px-2 py-1 small fw-bold text-truncate" style="background: var(--surface);">${this.selectedFrom.name}</div>
                    </div>
                </div>
            `);
        }
        if (this.selectedTo) {
            cards.push(`
                <div class="col-6">
                    <div class="rounded-3 overflow-hidden border position-relative" style="border-color: var(--border) !important;">
                        ${this.renderCityThumbHtml(this.selectedTo, 72)}
                        <span class="position-absolute top-0 start-0 m-1 badge bg-dark text-light border" style="font-size: 10px; border-color: var(--accent) !important;">Arrivée</span>
                        <div class="px-2 py-1 small fw-bold text-truncate" style="background: var(--surface);">${this.selectedTo.name}</div>
                    </div>
                </div>
            `);
        }

        this.selectedCityPreviewTarget.classList.remove('d-none');
        this.selectedCityPreviewTarget.innerHTML = `<div class="row g-2">${cards.join('')}</div>`;
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
                this.map.fitBounds([[170, 120], [535, 520]]);
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
        this.canvasTarget.style.backgroundColor = '#0B0B0C';

        const imageBounds = [[0, 0], [this.constructor.MAP_HEIGHT, this.constructor.MAP_WIDTH]];

        this.map = L.map(this.canvasTarget, {
            crs: L.CRS.Simple,
            scrollWheelZoom: false,
            zoomControl: true,
            attributionControl: false,
            minZoom: -0.5,
            maxZoom: 2.5,
            zoomSnap: 0.25,
            maxBounds: [
                [-40, -40],
                [this.constructor.MAP_HEIGHT + 40, this.constructor.MAP_WIDTH + 40],
            ],
            maxBoundsViscosity: 0.85,
        });

        L.imageOverlay('/images/cities/carte-maroc.webp', imageBounds, {
            alt: 'Carte du Maroc — réseau AtlasCovoit',
        }).addTo(this.map);

        // Focus cleanly on the 10-city corridor while showing the glowing Morocco outline
        this.map.fitBounds([[170, 120], [535, 520]]);

        this.renderCityMarkers();
    }

    renderCityMarkers() {
        const L = window.L;
        if (!L || !this.map || !Array.isArray(this.citiesValue)) {
            return;
        }

        this.citiesValue.forEach((city) => {
            const latLng = this.getCityMapLatLng(city);
            if (!latLng) {
                return;
            }

            const icon = this.createMarkerIcon(city, 'default');
            const marker = L.marker(latLng, {
                icon,
                title: city.name,
                alt: `Ville : ${city.name}`,
                keyboard: true,
            }).addTo(this.map);

            marker.on('click', () => this.handleCityClick(city));
            this.markers.set(city.id, { marker, city, latLng });
        });
    }

    createMarkerIcon(city, state = 'default') {
        const L = window.L;
        let badgeHtml = '';
        let extraStyle = '';

        if (state === 'from') {
            badgeHtml = '<span class="marker-role-tag" style="position: absolute; top: -11px; left: 50%; transform: translateX(-50%); background: #0B0B0C; color: #C6F432; font-size: 9px; font-weight: 800; padding: 1px 6px; border-radius: 9999px; border: 1px solid #C6F432; white-space: nowrap;">1. DÉPART</span>';
            extraStyle = 'outline: 3px solid #FFFFFF; box-shadow: 0 0 16px rgba(198, 244, 50, 0.9); transform: scale(1.06);';
        } else if (state === 'to') {
            badgeHtml = '<span class="marker-role-tag" style="position: absolute; top: -11px; left: 50%; transform: translateX(-50%); background: #0B0B0C; color: #C6F432; font-size: 9px; font-weight: 800; padding: 1px 6px; border-radius: 9999px; border: 1px solid #C6F432; white-space: nowrap;">2. ARRIVÉE</span>';
            extraStyle = 'outline: 3px solid #FFFFFF; box-shadow: 0 0 16px rgba(198, 244, 50, 0.9); transform: scale(1.06);';
        }

        const html = `
            <div style="position: relative; display: inline-flex; align-items: center; justify-content: center; min-width: 44px; min-height: 44px;">
                ${badgeHtml}
                <button type="button"
                        class="city-marker-btn"
                        aria-label="Sélectionner ville de ${city.name}"
                        style="min-width: 44px; min-height: 44px; padding: 4px 10px; background-color: #C6F432; color: #0B0B0C; font-weight: 800; font-size: 11px; font-family: inherit; border: 2px solid #0B0B0C; border-radius: 9999px; box-shadow: 0 4px 14px rgba(0,0,0,0.45); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 5px; white-space: nowrap; transition: transform 0.15s ease, box-shadow 0.15s ease; ${extraStyle}">
                    <span style="width: 7px; height: 7px; border-radius: 50%; background-color: #0B0B0C; display: inline-block; flex-shrink: 0;"></span>
                    <span>${city.name}</span>
                </button>
            </div>
        `;

        return L.divIcon({
            className: 'home-map-city-divicon',
            html,
            iconSize: null,
            iconAnchor: [38, 22],
        });
    }

    selectCityCard(event) {
        const id = parseInt(event.currentTarget.dataset.cityId || '', 10);
        if (isNaN(id)) return;
        const city = this.citiesValue.find((c) => c.id === id);
        if (city) {
            this.handleCityClick(city);
        }
    }

    handleCityClick(city) {
        if (!this.selectedFrom) {
            this.setFrom(city);
        } else if (!this.selectedTo) {
            if (this.selectedFrom.id === city.id) {
                this.resetSelection();
            } else {
                this.setTo(city);
            }
        } else {
            this.resetSelection(false);
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

        this.updateSelectedCityPreview();
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

        this.updateSelectedCityPreview();
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

        const fromItem = this.markers.get(this.selectedFrom.id);
        const toItem = this.markers.get(this.selectedTo.id);
        const fromLatLng = fromItem?.latLng || this.getCityMapLatLng(this.selectedFrom);
        const toLatLng = toItem?.latLng || this.getCityMapLatLng(this.selectedTo);
        if (!fromLatLng || !toLatLng) {
            return;
        }

        this.polyline = L.polyline([fromLatLng, toLatLng], {
            color: '#C6F432',
            weight: 4,
            dashArray: '8, 8',
            opacity: 0.95,
        }).addTo(this.map);

        const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.map.fitBounds(this.polyline.getBounds(), {
            padding: [65, 65],
            maxZoom: 1.5,
            animate: !reducedMotion,
        });
    }

    swapCities() {
        if (!this.selectedFrom || !this.selectedTo) {
            return;
        }

        const newFrom = this.selectedTo;
        const newTo = this.selectedFrom;

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
            this.updateSelectedCityPreview();
            this.showInitialState();

            if (this.map) {
                const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                this.map.fitBounds([[170, 120], [535, 520]], { animate: !reducedMotion });
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
                    this.toBadgeTarget.classList.remove('badge-lime');
                }
                this.updateSelectedCityPreview();
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
                    this.toBadgeTarget.classList.remove('badge-lime');
                }
                if (this.polyline) {
                    this.polyline.remove();
                    this.polyline = null;
                }
                this.updateSelectedCityPreview();
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
        const cities = Array.isArray(this.citiesValue) ? this.citiesValue : [];
        const cityCards = cities.map((city) => `
            <div class="col-6">
                <button type="button"
                        class="btn p-0 w-100 text-start border rounded-3 overflow-hidden position-relative card-interactive bg-transparent"
                        style="border-color: var(--border) !important;"
                        data-city-id="${city.id}"
                        data-action="click->home-map#selectCityCard"
                        aria-label="Sélectionner ${city.name}">
                    ${this.renderCityThumbHtml(city, 76)}
                    <div class="px-2 py-1 d-flex justify-content-between align-items-center" style="background: var(--surface);">
                        <span class="small fw-bold text-truncate">${city.name}</span>
                        <i class="bi bi-plus-circle-fill text-lime small" aria-hidden="true"></i>
                    </div>
                </button>
            </div>
        `).join('');

        this.resultsContainerTarget.innerHTML = `
            <div class="p-1">
                <div class="text-center mb-3">
                    <div class="fw-bold small text-uppercase text-muted mb-1">Sélectionnez votre itinéraire</div>
                    <div class="small text-muted">Cliquez sur une ville sur la carte ou sur une vignette ci-dessous (1er clic = départ, 2ème = arrivée).</div>
                </div>
                <div class="row g-2">${cityCards}</div>
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
