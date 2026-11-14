import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller: "reveal"
 * Uses IntersectionObserver to trigger fade + slide-up animations (transform & opacity only)
 * with optional staggered delay. Respects prefers-reduced-motion.
 */
export default class extends Controller {
    static values = {
        delay: { type: Number, default: 0 },
    };

    connect() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) {
            this.element.classList.add('is-revealed');
            return;
        }

        this.element.classList.add('reveal-on-scroll');
        if (this.delayValue > 0) {
            this.element.style.transitionDelay = `${this.delayValue}ms`;
        }

        this.observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        this.element.classList.add('is-revealed');
                        this.observer.unobserve(this.element);
                    }
                });
            },
            { threshold: 0.12 }
        );

        this.observer.observe(this.element);
    }

    disconnect() {
        if (this.observer) {
            this.observer.disconnect();
        }
    }
}
