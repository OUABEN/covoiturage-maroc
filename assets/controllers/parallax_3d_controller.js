import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller: "parallax-3d"
 * Applies lightweight 3D perspective & layer translation/rotation on the Hero scene
 * in response to pointer movement. Disabled on mobile (< 992px) and prefers-reduced-motion.
 */
export default class extends Controller {
    static targets = ['stage', 'layer'];

    connect() {
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.isDesktop = window.innerWidth >= 992;
        this.rafId = null;
    }

    move(event) {
        if (this.reducedMotion || !this.isDesktop || !this.hasStageTarget) {
            return;
        }

        const rect = this.element.getBoundingClientRect();
        const relX = (event.clientX - rect.left) / rect.width - 0.5;
        const relY = (event.clientY - rect.top) / rect.height - 0.5;

        if (this.rafId) {
            cancelAnimationFrame(this.rafId);
        }

        this.rafId = requestAnimationFrame(() => {
            const rotY = (relX * 10).toFixed(2);
            const rotX = (-relY * 8).toFixed(2);
            this.stageTarget.style.transform = `rotateX(${rotX}deg) rotateY(${rotY}deg)`;

            this.layerTargets.forEach((layer) => {
                const depth = parseFloat(layer.dataset.depth || '18');
                const tx = (relX * depth).toFixed(1);
                const ty = (relY * depth).toFixed(1);
                const tz = Math.abs(depth).toFixed(0);
                layer.style.transform = `translate3d(${tx}px, ${ty}px, ${tz}px)`;
            });
        });
    }

    leave() {
        if (!this.hasStageTarget) {
            return;
        }
        this.stageTarget.style.transform = 'rotateX(0deg) rotateY(0deg)';
        this.layerTargets.forEach((layer) => {
            const tz = Math.abs(parseFloat(layer.dataset.depth || '18')).toFixed(0);
            layer.style.transform = `translate3d(0px, 0px, ${tz}px)`;
        });
    }
}
