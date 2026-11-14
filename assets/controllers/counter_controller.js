import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller: "counter"
 * Smoothly animates key numerical figures when scrolled into view (duration ~500ms).
 * Immediately displays final value if prefers-reduced-motion is enabled.
 */
export default class extends Controller {
    static values = {
        target: Number,
        suffix: { type: String, default: '' },
        prefix: { type: String, default: '' },
        decimals: { type: Number, default: 0 },
    };

    connect() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) {
            this.renderValue(this.targetValue);
            return;
        }

        this.animated = false;
        this.observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting && !this.animated) {
                        this.animated = true;
                        this.animateCount();
                        this.observer.unobserve(this.element);
                    }
                });
            },
            { threshold: 0.25 }
        );

        this.observer.observe(this.element);
    }

    disconnect() {
        if (this.observer) {
            this.observer.disconnect();
        }
    }

    animateCount() {
        const duration = 520;
        const start = performance.now();
        const endVal = this.targetValue;

        const step = (now) => {
            const progress = Math.min(1, (now - start) / duration);
            // Ease-out cubic
            const eased = 1 - Math.pow(1 - progress, 3);
            this.renderValue(endVal * eased);
            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                this.renderValue(endVal);
            }
        };

        requestAnimationFrame(step);
    }

    renderValue(val) {
        const formatted = this.decimalsValue > 0
            ? val.toFixed(this.decimalsValue).replace('.', ',')
            : Math.round(val).toString();
        this.element.textContent = `${this.prefixValue}${formatted}${this.suffixValue}`;
    }
}
