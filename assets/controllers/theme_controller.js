import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller: "theme"
 * Reads/writes localStorage("theme"), defaults to prefers-color-scheme,
 * applies data-theme="light|dark" on <html>, updates accessible toggle state,
 * and dispatches "theme:changed" with CartoDB Dark Matter / Positron tile URLs.
 */
export default class extends Controller {
    static targets = ['icon', 'label'];

    static TILE_URLS = {
        dark: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
        light: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
    };

    connect() {
        this.currentTheme = this.resolveInitialTheme();
        this.applyTheme(this.currentTheme, false);

        this.mediaQuery = window.matchMedia('(prefers-color-scheme: light)');
        this.handleSystemChange = (event) => {
            if (!localStorage.getItem('theme')) {
                this.applyTheme(event.matches ? 'light' : 'dark', true);
            }
        };
        if (this.mediaQuery.addEventListener) {
            this.mediaQuery.addEventListener('change', this.handleSystemChange);
        }
    }

    disconnect() {
        if (this.mediaQuery && this.mediaQuery.removeEventListener && this.handleSystemChange) {
            this.mediaQuery.removeEventListener('change', this.handleSystemChange);
        }
    }

    toggle(event) {
        const nextTheme = this.currentTheme === 'dark' ? 'light' : 'dark';
        try {
            localStorage.setItem('theme', nextTheme);
        } catch (_) {
            // Ignore storage errors in private browsing
        }

        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduceMotion || typeof document.startViewTransition !== 'function') {
            this.applyTheme(nextTheme, true);
            return;
        }

        // Circular reveal expanding from the toggle button (View Transitions API)
        const rect = this.element.getBoundingClientRect();
        const x = event && event.clientX ? event.clientX : rect.left + rect.width / 2;
        const y = event && event.clientY ? event.clientY : rect.top + rect.height / 2;
        const radius = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));
        const html = document.documentElement;

        html.classList.add('theme-transition');
        const transition = document.startViewTransition(() => this.applyTheme(nextTheme, true));

        transition.ready
            .then(() => {
                html.animate(
                    { clipPath: [`circle(0px at ${x}px ${y}px)`, `circle(${radius}px at ${x}px ${y}px)`] },
                    { duration: 620, easing: 'cubic-bezier(0.16, 1, 0.3, 1)', pseudoElement: '::view-transition-new(root)' }
                );
            })
            .catch(() => {});

        transition.finished.finally(() => html.classList.remove('theme-transition'));
    }

    resolveInitialTheme() {
        try {
            const saved = localStorage.getItem('theme');
            if (saved === 'light' || saved === 'dark') {
                return saved;
            }
        } catch (_) {
            // Ignore storage errors
        }
        const htmlAttr = document.documentElement.getAttribute('data-theme');
        if (htmlAttr === 'light' || htmlAttr === 'dark') {
            return htmlAttr;
        }

        return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    }

    applyTheme(theme, persistEvent = true) {
        this.currentTheme = theme;
        const root = document.documentElement;
        root.setAttribute('data-theme', theme);
        root.setAttribute('data-bs-theme', theme);

        const isDark = theme === 'dark';
        this.element.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        this.element.setAttribute(
            'aria-label',
            isDark ? 'Passer au mode clair (actuellement mode sombre)' : 'Passer au mode sombre (actuellement mode clair)'
        );

        if (this.hasIconTarget) {
            this.iconTarget.className = isDark ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
        }
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = isDark ? 'Clair' : 'Sombre';
        }

        if (persistEvent) {
            window.dispatchEvent(
                new CustomEvent('theme:changed', {
                    detail: {
                        theme,
                        tileUrl: this.constructor.TILE_URLS[theme] || this.constructor.TILE_URLS.dark,
                    },
                })
            );
        }
    }
}
