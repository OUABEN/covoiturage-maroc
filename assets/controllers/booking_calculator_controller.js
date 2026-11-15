import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller: "booking-calculator"
 * Recalculates portion unit price, total price, selected cities label,
 * and minimum segment seat availability in real time when the user changes
 * boarding stop, alighting stop, or number of seats.
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
    ];

    static values = {
        segments: Array,
    };

    connect() {
        this.recalculate();
    }

    recalculate() {
        if (!this.hasFromSelectTarget || !this.hasToSelectTarget) {
            return;
        }

        const fromOption = this.fromSelectTarget.options[this.fromSelectTarget.selectedIndex];
        if (!fromOption) {
            return;
        }

        const fromOrder = parseInt(fromOption.dataset.stopOrder || '0', 10);
        const fromCity = fromOption.dataset.cityName || '';

        // Disable invalid destination options (stopOrder <= fromOrder)
        let validToSelected = false;
        let firstValidIndex = -1;

        Array.from(this.toSelectTarget.options).forEach((opt, idx) => {
            const optOrder = parseInt(opt.dataset.stopOrder || '0', 10);
            const isValid = optOrder > fromOrder;
            opt.disabled = !isValid;
            if (isValid && firstValidIndex === -1) {
                firstValidIndex = idx;
            }
            if (isValid && idx === this.toSelectTarget.selectedIndex) {
                validToSelected = true;
            }
        });

        if (!validToSelected && firstValidIndex !== -1) {
            this.toSelectTarget.selectedIndex = firstValidIndex;
        }

        const toOption = this.toSelectTarget.options[this.toSelectTarget.selectedIndex];
        if (!toOption) {
            return;
        }

        const toOrder = parseInt(toOption.dataset.stopOrder || '1', 10);
        const toCity = toOption.dataset.cityName || '';

        // Sum segment prices & find minimum available seats on [fromOrder, toOrder)
        let unitPrice = 0;
        let minAvailable = null;

        if (Array.isArray(this.segmentsValue)) {
            this.segmentsValue.forEach((seg) => {
                if (seg.index >= fromOrder && seg.index < toOrder) {
                    unitPrice += parseFloat(seg.price || 0);
                    const avail = parseInt(seg.available ?? 0, 10);
                    if (minAvailable === null || avail < minAvailable) {
                        minAvailable = avail;
                    }
                }
            });
        }

        const seats = this.hasSeatsInputTarget
            ? Math.max(1, parseInt(this.seatsInputTarget.value || '1', 10))
            : 1;

        const totalPrice = unitPrice * seats;

        if (this.hasPortionLabelTarget && fromCity && toCity) {
            this.portionLabelTarget.textContent = `${fromCity} → ${toCity}`;
        }

        if (this.hasUnitPriceTarget) {
            this.unitPriceTarget.textContent = `${unitPrice.toFixed(2)} MAD`;
        }

        if (this.hasTotalPriceTarget) {
            this.totalPriceTarget.textContent = `${totalPrice.toFixed(2)} MAD`;
        }

        if (this.hasAvailableSeatsTarget && minAvailable !== null) {
            this.availableSeatsTarget.textContent = `${minAvailable} place(s) disponible(s)`;
        }
    }
}
