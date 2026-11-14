import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller: "tilt"
 * Applies a subtle ±6° 3D tilt and dynamic shadow on trip & city cards on hover.
 * Disabled on mobile/touch screens and when prefers-reduced-motion is active.
 */
export default class extends Controller {
    connect() {
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.maxDeg = 6;
        this.onEnter = this.handleEnter.bind(this);
        this.onMove = this.handleMove.bind(this);
        this.onLeave = this.handleLeave.bind(this);

        this.element.addEventListener('pointerenter', this.onEnter);
        this.element.addEventListener('pointermove', this.onMove);
        this.element.addEventListener('pointerleave', this.onLeave);
    }

    disconnect() {
        this.element.removeEventListener('pointerenter', this.onEnter);
        this.element.removeEventListener('pointermove', this.onMove);
        this.element.removeEventListener('pointerleave', this.onLeave);
    }

    handleEnter(event) {
        if (this.reducedMotion || event.pointerType === 'touch' || window.innerWidth < 768) {
            return;
        }
        this.element.style.willChange = 'transform';
    }

    handleMove(event) {
        if (this.reducedMotion || event.pointerType === 'touch' || window.innerWidth < 768) {
            return;
        }

        const rect = this.element.getBoundingClientRect();
        const x = (event.clientX - rect.left) / rect.width - 0.5;
        const y = (event.clientY - rect.top) / rect.height - 0.5;

        const rotateY = (x * this.maxDeg * 2).toFixed(2);
        const rotateX = (-y * this.maxDeg * 2).toFixed(2);

        this.element.style.transform = `perspective(900px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) translateZ(2px)`;
    }

    handleLeave() {
        this.element.style.transform = '';
        this.element.style.willChange = 'auto';
    }
}
