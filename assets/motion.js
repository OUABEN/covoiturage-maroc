/**
 * AtlasCovoit — Motion System runtime
 * Scroll progress, navbar shrink, back-to-top ring, auto scroll-reveal with stagger,
 * cursor spotlight on cards, magnetic primary buttons, click ripples,
 * loading spinner on submit, auto-hiding toasts and animated confirm dialog.
 * Pure progressive enhancement: no markup or form field is altered server-side.
 */

const root = document.documentElement;
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

function onReady(callback) {
    if (document.readyState !== 'loading') {
        callback();
    } else {
        document.addEventListener('DOMContentLoaded', callback, { once: true });
    }
}

/* 1. Scroll progress, navbar shrink, back-to-top ------------------------------------ */
function initScrollEffects() {
    const progress = document.querySelector('.scroll-progress');
    const navbar = document.querySelector('.navbar-glass');
    const backToTop = document.querySelector('.back-to-top');
    let ticking = false;

    const update = () => {
        const max = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
        const ratio = Math.min(1, Math.max(0, window.scrollY / max));
        if (progress) {
            progress.style.setProperty('--scroll', ratio.toFixed(4));
        }
        if (navbar) {
            navbar.classList.toggle('is-scrolled', window.scrollY > 12);
        }
        if (backToTop) {
            backToTop.style.setProperty('--scroll-pct', (ratio * 100).toFixed(2));
            backToTop.classList.toggle('is-visible', window.scrollY > 420);
        }
        ticking = false;
    };

    window.addEventListener('scroll', () => {
        if (!ticking) {
            ticking = true;
            requestAnimationFrame(update);
        }
    }, { passive: true });
    window.addEventListener('resize', update, { passive: true });
    update();

    if (backToTop) {
        backToTop.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
        });
    }
}

/* 2. Auto scroll-reveal with stagger ------------------------------------------------ */
function initAutoReveal() {
    const candidates = Array.from(document.querySelectorAll('main .card, main [data-reveal]')).filter((el) => {
        if (el.closest('[data-controller~="reveal"]')) {
            return false;
        }
        return !(el.classList.contains('card') && el.parentElement && el.parentElement.closest('.card'));
    });

    if (reduceMotion || !('IntersectionObserver' in window)) {
        candidates.forEach((el) => el.classList.add('is-revealed'));
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        let batchIndex = 0;
        entries.forEach((entry) => {
            if (!entry.isIntersecting) {
                return;
            }
            const el = entry.target;
            el.style.transitionDelay = `${Math.min(batchIndex, 7) * 75}ms`;
            batchIndex += 1;
            el.classList.add('is-revealed');
            observer.unobserve(el);
            window.setTimeout(() => {
                el.style.transitionDelay = '';
            }, 1400);
        });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

    candidates.forEach((el) => observer.observe(el));
}

/* 3. Cursor spotlight ------------------------------------------------------------- */
function initSpotlight() {
    if (!finePointer || reduceMotion) {
        return;
    }
    let current = null;
    let frame = null;
    let lastEvent = null;

    const paint = () => {
        frame = null;
        if (!lastEvent) {
            return;
        }
        const target = lastEvent.target instanceof Element ? lastEvent.target.closest('.card, .spotlight') : null;
        if (current && current !== target) {
            current.style.setProperty('--mx', '-999px');
            current.style.setProperty('--my', '-999px');
        }
        current = target;
        if (target) {
            const rect = target.getBoundingClientRect();
            target.style.setProperty('--mx', `${lastEvent.clientX - rect.left}px`);
            target.style.setProperty('--my', `${lastEvent.clientY - rect.top}px`);
        }
    };

    document.addEventListener('pointermove', (event) => {
        lastEvent = event;
        if (!frame) {
            frame = requestAnimationFrame(paint);
        }
    }, { passive: true });
}

/* 4. Magnetic primary buttons ------------------------------------------------------ */
function initMagnetic() {
    if (!finePointer || reduceMotion) {
        return;
    }
    document.querySelectorAll('.btn-lime, .btn-brand').forEach((btn) => {
        btn.addEventListener('pointermove', (event) => {
            const rect = btn.getBoundingClientRect();
            const dx = (event.clientX - (rect.left + rect.width / 2)) / (rect.width / 2);
            const dy = (event.clientY - (rect.top + rect.height / 2)) / (rect.height / 2);
            btn.style.setProperty('--mag-x', `${(dx * 5).toFixed(2)}px`);
            btn.style.setProperty('--mag-y', `${(dy * 4).toFixed(2)}px`);
        });
        btn.addEventListener('pointerleave', () => {
            btn.style.setProperty('--mag-x', '0px');
            btn.style.setProperty('--mag-y', '0px');
        });
    });
}

/* 5. Ripple on click ------------------------------------------------------------- */
function initRipple() {
    if (reduceMotion) {
        return;
    }
    document.addEventListener('pointerdown', (event) => {
        const btn = event.target instanceof Element ? event.target.closest('.btn, .btn-theme-toggle, .seg-tab') : null;
        if (!btn) {
            return;
        }
        const rect = btn.getBoundingClientRect();
        const size = Math.max(rect.width, rect.height) * 2.2;
        const ripple = document.createElement('span');
        ripple.className = 'ripple';
        ripple.style.width = `${size}px`;
        ripple.style.height = `${size}px`;
        ripple.style.left = `${event.clientX - rect.left - size / 2}px`;
        ripple.style.top = `${event.clientY - rect.top - size / 2}px`;
        btn.appendChild(ripple);
        ripple.addEventListener('animationend', () => ripple.remove(), { once: true });
    });
}

/* 6. Animated confirm dialog for forms[data-confirm] -------------------------------- */
function buildConfirmDialog() {
    const dialog = document.createElement('dialog');
    dialog.className = 'confirm-dialog';
    dialog.setAttribute('aria-labelledby', 'confirm-dialog-title');
    dialog.innerHTML = `
        <form method="dialog" class="p-4">
            <div class="d-flex align-items-start gap-3 mb-3">
                <div class="confirm-dialog__icon flex-shrink-0" aria-hidden="true"><i class="bi bi-exclamation-octagon-fill"></i></div>
                <div>
                    <h2 id="confirm-dialog-title" class="h5 fw-extrabold mb-1">Confirmer cette action</h2>
                    <p class="text-muted small mb-0" data-confirm-message></p>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-4">
                <button value="cancel" class="btn btn-outline-light px-3">Retour</button>
                <button value="confirm" class="btn btn-danger px-3" data-confirm-accept>Confirmer</button>
            </div>
        </form>`;
    document.body.appendChild(dialog);

    return dialog;
}

function initConfirmForms() {
    let dialog = null;

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) {
            return;
        }
        if (form.dataset.confirmed === '1') {
            delete form.dataset.confirmed;
            return;
        }
        if (typeof HTMLDialogElement === 'undefined') {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        dialog = dialog || buildConfirmDialog();
        dialog.querySelector('[data-confirm-message]').textContent = form.dataset.confirm;
        dialog.querySelector('[data-confirm-accept]').textContent = form.dataset.confirmLabel || 'Confirmer';
        const submitter = event.submitter || null;

        dialog.addEventListener('close', () => {
            if (dialog.returnValue === 'confirm') {
                form.dataset.confirmed = '1';
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
                } else {
                    form.submit();
                }
            }
        }, { once: true });

        dialog.returnValue = '';
        dialog.showModal();
    }, true);
}

/* 7. Loading spinner on submit ---------------------------------------------------- */
function initSubmitLoading() {
    document.addEventListener('submit', (event) => {
        if (event.defaultPrevented) {
            return;
        }
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method === 'dialog') {
            return;
        }
        const btn = event.submitter || form.querySelector('[type="submit"]');
        if (btn && btn.classList.contains('btn') && !btn.classList.contains('no-loading')) {
            btn.classList.add('is-loading');
            btn.setAttribute('aria-busy', 'true');
        }
    });

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            document.querySelectorAll('.btn.is-loading').forEach((btn) => {
                btn.classList.remove('is-loading');
                btn.removeAttribute('aria-busy');
            });
        }
    });
}

/* 8. Auto-hiding toasts (success / info / warning only) ----------------------------- */
function initToasts() {
    document.querySelectorAll('main .alert-success, main .alert-info, main .alert-warning').forEach((alert) => {
        if (!alert.classList.contains('alert-dismissible')) {
            return;
        }
        const timer = document.createElement('span');
        timer.className = 'alert-timer';
        timer.setAttribute('aria-hidden', 'true');
        alert.appendChild(timer);
        timer.addEventListener('animationend', () => {
            alert.classList.add('is-leaving');
            alert.addEventListener('animationend', () => alert.remove(), { once: true });
        }, { once: true });
    });
}

onReady(() => {
    initScrollEffects();
    initAutoReveal();
    initSpotlight();
    initMagnetic();
    initRipple();
    initConfirmForms();
    initSubmitLoading();
    initToasts();
    root.classList.add('motion-booted');
});
