import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller: "trip-builder"
 * Powers the multi-stop trip publication form (/trajets/nouveau):
 * - Dynamic addition/removal of stop rows with smooth entrance/exit animations
 * - Automatic OSRM route & price suggestion via /api/route
 * - Live summary sidebar (chain of cities, total distance, cumulative price, max revenue)
 */
export default class extends Controller {
    static targets = [
        'stopsContainer',
        'vehicleSelect',
        'seatsInput',
        'summaryChain',
        'summaryPrice',
        'summaryRevenue',
        'summaryStopsCount',
        'stepIndicator',
    ];

    static values = {
        routeEndpoint: { type: String, default: '/api/route' },
        cityOptionsHtml: String,
    };

    connect() {
        this.totalDistanceKm = 0;
        this.refreshSuggestedPrices();
        this.updateSummary();
    }

    onVehicleChange() {
        if (!this.hasVehicleSelectTarget || !this.hasSeatsInputTarget) {
            return;
        }
        const opt = this.vehicleSelectTarget.options[this.vehicleSelectTarget.selectedIndex];
        if (opt && opt.dataset.maxSeats) {
            const maxSeats = parseInt(opt.dataset.maxSeats, 10);
            this.seatsInputTarget.max = maxSeats;
            if (parseInt(this.seatsInputTarget.value || '1', 10) > maxSeats) {
                this.seatsInputTarget.value = maxSeats;
            }
        }
        this.highlightStep(0);
        this.updateSummary();
    }

    onFieldInput() {
        this.updateSummary();
    }

    onCityChange() {
        this.highlightStep(1);
        this.refreshSuggestedPrices();
        this.updateSummary();
    }

    addStop() {
        if (!this.hasStopsContainerTarget) {
            return;
        }
        const index = this.stopsContainerTarget.querySelectorAll('.stop-row').length;
        const row = document.createElement('div');
        row.className = 'stop-row stop-item is-entering';
        row.innerHTML = `
            <div class="stop-item__num" data-stop-badge>${index + 1}</div>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="fw-extrabold small" data-stop-title>Étape ${index + 1}</span>
                    <span class="badge badge-surface small">Arrêt ou arrivée</span>
                </div>
                <button type="button" class="btn btn-outline-danger btn-sm py-1 px-2 remove-stop-btn" data-action="click->trip-builder#removeStop">
                    <i class="bi bi-trash3 me-1" aria-hidden="true"></i>Retirer
                </button>
            </div>
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label small">Ville</label>
                    <select name="stops[${index}][city_id]" class="form-select form-select-sm" required data-action="change->trip-builder#onCityChange">
                        ${this.cityOptionsHtmlValue}
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Heure d'arrivée</label>
                    <input type="datetime-local" name="stops[${index}][arrival_time]" class="form-control form-control-sm" required data-action="input->trip-builder#onFieldInput">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Heure de départ (optionnel)</label>
                    <input type="datetime-local" name="stops[${index}][departure_time]" class="form-control form-control-sm" data-action="input->trip-builder#onFieldInput">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Prix du segment (MAD)</label>
                    <div class="price-input-wrap" data-price-wrap>
                        <input type="number" step="0.01" min="0.01" name="stops[${index}][price_from_previous]" class="form-control form-control-sm" value="80.00" required data-action="input->trip-builder#onFieldInput">
                    </div>
                    <div class="osrm-hint" data-osrm-hint>Calcul OSRM…</div>
                </div>
            </div>
        `;
        this.stopsContainerTarget.appendChild(row);
        this.reindexStops();
        this.highlightStep(1);
        this.refreshSuggestedPrices();
        this.updateSummary();
    }

    removeStop(event) {
        const row = event.currentTarget.closest('.stop-row');
        if (!row || !this.hasStopsContainerTarget) {
            return;
        }
        if (this.stopsContainerTarget.querySelectorAll('.stop-row').length <= 2) {
            return;
        }
        row.classList.add('is-leaving');
        window.setTimeout(() => {
            row.remove();
            this.reindexStops();
            this.refreshSuggestedPrices();
            this.updateSummary();
        }, 240);
    }

    reindexStops() {
        const rows = Array.from(this.stopsContainerTarget.querySelectorAll('.stop-row'));
        rows.forEach((row, idx) => {
            const badge = row.querySelector('[data-stop-badge]');
            if (badge) {
                badge.textContent = `${idx + 1}`;
            }
            const title = row.querySelector('[data-stop-title]');
            if (title) {
                title.textContent = idx === 0 ? 'Étape 1 (Départ)' : `Étape ${idx + 1}`;
            }
            row.querySelectorAll('[name^="stops["]').forEach((input) => {
                input.name = input.name.replace(/^stops\[\d+\]/, `stops[${idx}]`);
            });
        });
    }

    async refreshSuggestedPrices() {
        if (!this.hasStopsContainerTarget) {
            return;
        }
        const rows = Array.from(this.stopsContainerTarget.querySelectorAll('.stop-row'));
        for (let i = 1; i < rows.length; i += 1) {
            const prevSelect = rows[i - 1].querySelector('select[name*="[city_id]"]');
            const currSelect = rows[i].querySelector('select[name*="[city_id]"]');
            const priceInput = rows[i].querySelector('input[name*="[price_from_previous]"]');
            const wrap = rows[i].querySelector('[data-price-wrap]');
            const hint = rows[i].querySelector('[data-osrm-hint]');
            if (!prevSelect || !currSelect || !priceInput) {
                continue;
            }

            const fromId = prevSelect.value;
            const toId = currSelect.value;
            if (!fromId || !toId || fromId === toId) {
                if (hint) {
                    hint.textContent = 'Choisissez 2 villes distinctes';
                }
                continue;
            }

            if (wrap) {
                wrap.classList.add('is-computing');
            }
            try {
                const resp = await fetch(`${this.routeEndpointValue}?from=${encodeURIComponent(fromId)}&to=${encodeURIComponent(toId)}`, {
                    headers: { Accept: 'application/json' },
                });
                if (resp.ok) {
                    const data = await resp.json();
                    if (data.suggestedPrice) {
                        priceInput.value = data.suggestedPrice;
                        priceInput.title = `Distance estimée : ${data.distanceKm} km (~${data.durationMinutes} min) — Prix suggéré OSRM : ${data.suggestedPrice} MAD`;
                    }
                    if (hint && typeof data.distanceKm === 'number') {
                        hint.textContent = `OSRM : ~${data.distanceKm} km · ${data.durationMinutes} min · suggéré ${data.suggestedPrice} MAD`;
                    }
                }
            } catch (_) {
                if (hint) {
                    hint.textContent = 'Tarif libre';
                }
            } finally {
                if (wrap) {
                    wrap.classList.remove('is-computing');
                }
                this.updateSummary();
            }
        }
    }

    updateSummary() {
        if (!this.hasStopsContainerTarget) {
            return;
        }
        const rows = Array.from(this.stopsContainerTarget.querySelectorAll('.stop-row'));
        const cities = [];
        let totalPrice = 0;

        rows.forEach((row, idx) => {
            const select = row.querySelector('select[name*="[city_id]"]');
            if (select && select.options[select.selectedIndex]) {
                cities.push(select.options[select.selectedIndex].text.trim());
            }
            if (idx > 0) {
                const priceInput = row.querySelector('input[name*="[price_from_previous]"]');
                const val = parseFloat(priceInput?.value || '0');
                if (!Number.isNaN(val)) {
                    totalPrice += val;
                }
            }
        });

        const seats = this.hasSeatsInputTarget
            ? Math.max(1, parseInt(this.seatsInputTarget.value || '1', 10))
            : 1;

        if (this.hasSummaryChainTarget) {
            this.summaryChainTarget.innerHTML = cities
                .map((c, i) => `<div class="trip-summary__city"><span class="dot"></span><span>${i + 1}. ${c}</span></div>`)
                .join('');
        }

        if (this.hasSummaryStopsCountTarget) {
            this.summaryStopsCountTarget.textContent = `${rows.length} étapes (${Math.max(1, rows.length - 1)} segment(s))`;
        }

        if (this.hasSummaryPriceTarget) {
            this.summaryPriceTarget.textContent = `${totalPrice.toFixed(2)} MAD`;
        }

        if (this.hasSummaryRevenueTarget) {
            this.summaryRevenueTarget.textContent = `${(totalPrice * seats).toFixed(2)} MAD`;
        }
    }

    highlightStep(stepIdx) {
        if (!this.hasStepIndicatorTarget) {
            return;
        }
        this.stepIndicatorTargets.forEach((el, idx) => {
            el.classList.toggle('is-current', idx === stepIdx);
            el.classList.toggle('is-done', idx < stepIdx);
        });
    }
}
