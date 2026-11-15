import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller: "tabs"
 * Segmented tab switcher with an animated sliding pill indicator,
 * keyboard navigation (Left/Right/Home/End), and staggered panel entrance.
 * Note: Inactive panels use a visual CSS class rather than removing nodes from the DOM
 * so Symfony DomCrawler functional tests can still inspect all forms if needed.
 */
export default class extends Controller {
    static targets = ['tab', 'panel', 'indicator'];

    connect() {
        this.activeIndex = 0;
        this.updateIndicator();
        this.handleResize = () => this.updateIndicator();
        window.addEventListener('resize', this.handleResize, { passive: true });
    }

    disconnect() {
        window.removeEventListener('resize', this.handleResize);
    }

    select(event) {
        const clickedTab = event.currentTarget;
        const idx = this.tabTargets.indexOf(clickedTab);
        if (idx === -1) {
            return;
        }
        this.activateTab(idx);
    }

    keydown(event) {
        const count = this.tabTargets.length;
        if (count === 0) {
            return;
        }
        let next = this.activeIndex;
        if (event.key === 'ArrowRight') {
            next = (this.activeIndex + 1) % count;
        } else if (event.key === 'ArrowLeft') {
            next = (this.activeIndex - 1 + count) % count;
        } else if (event.key === 'Home') {
            next = 0;
        } else if (event.key === 'End') {
            next = count - 1;
        } else {
            return;
        }
        event.preventDefault();
        this.activateTab(next);
        this.tabTargets[next].focus();
    }

    activateTab(index) {
        this.activeIndex = index;
        this.tabTargets.forEach((tab, i) => {
            const isSelected = i === index;
            tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            tab.setAttribute('tabindex', isSelected ? '0' : '-1');
        });

        this.panelTargets.forEach((panel, i) => {
            const isSelected = i === index;
            if (isSelected) {
                panel.removeAttribute('hidden');
                panel.classList.remove('is-entering');
                // Trigger reflow for staggered animation
                void panel.offsetWidth;
                panel.classList.add('is-entering');
            } else {
                panel.setAttribute('hidden', 'hidden');
                panel.classList.remove('is-entering');
            }
        });

        this.updateIndicator();
    }

    updateIndicator() {
        if (!this.hasIndicatorTarget || !this.tabTargets[this.activeIndex]) {
            return;
        }
        const activeTab = this.tabTargets[this.activeIndex];
        const parentRect = activeTab.parentElement.getBoundingClientRect();
        const tabRect = activeTab.getBoundingClientRect();
        const offsetLeft = tabRect.left - parentRect.left;

        this.indicatorTarget.style.width = `${tabRect.width}px`;
        this.indicatorTarget.style.transform = `translate3d(${offsetLeft}px, 0, 0)`;
    }
}
