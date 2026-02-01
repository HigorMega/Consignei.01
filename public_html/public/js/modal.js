(() => {
    const focusableSelectors = [
        'a[href]',
        'button:not([disabled])',
        'textarea:not([disabled])',
        'input:not([disabled])',
        'select:not([disabled])',
        '[tabindex]:not([tabindex="-1"])'
    ].join(', ');

    const openStack = [];
    const lastFocused = new Map();
    let bodyOverflow = '';

    const getFirstFocusable = (modal) => {
        const candidates = Array.from(modal.querySelectorAll(focusableSelectors));
        return candidates.find((el) => {
            if (el.hasAttribute('disabled')) return false;
            if (el.getAttribute('aria-hidden') === 'true') return false;
            return el.offsetParent !== null;
        });
    };

    const lockScroll = () => {
        if (openStack.length === 1) {
            bodyOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
        }
    };

    const unlockScroll = () => {
        if (openStack.length === 0) {
            document.body.style.overflow = bodyOverflow || '';
        }
    };

    const openModal = (id, trigger) => {
        const modal = document.getElementById(id);
        if (!modal || modal.dataset.modalIgnore === 'true') return;
        if (!openStack.includes(modal)) {
            openStack.push(modal);
        }
        lastFocused.set(modal, trigger || document.activeElement);
        modal.classList.add('open');
        lockScroll();

        const focusable = getFirstFocusable(modal);
        if (focusable) {
            focusable.focus({ preventScroll: true });
        } else {
            if (!modal.hasAttribute('tabindex')) {
                modal.setAttribute('tabindex', '-1');
            }
            modal.focus({ preventScroll: true });
        }
    };

    const closeModal = (id) => {
        const modal = document.getElementById(id);
        if (!modal || modal.dataset.modalIgnore === 'true') return;
        modal.classList.remove('open');
        const index = openStack.indexOf(modal);
        if (index >= 0) {
            openStack.splice(index, 1);
        }
        unlockScroll();
        const previous = lastFocused.get(modal);
        if (previous && typeof previous.focus === 'function') {
            previous.focus({ preventScroll: true });
        }
        lastFocused.delete(modal);
    };

    const closeTopModal = () => {
        for (let i = openStack.length - 1; i >= 0; i -= 1) {
            const modal = openStack[i];
            if (modal && modal.classList.contains('open')) {
                closeModal(modal.id);
                break;
            }
        }
    };

    document.addEventListener('click', (event) => {
        const openTrigger = event.target.closest('[data-modal-open]');
        if (openTrigger) {
            const targetId = openTrigger.getAttribute('data-modal-open');
            if (targetId) {
                event.preventDefault();
                openModal(targetId, openTrigger);
            }
            return;
        }

        const closeTrigger = event.target.closest('[data-modal-close]');
        if (closeTrigger) {
            const targetId = closeTrigger.getAttribute('data-modal-close');
            const modal = targetId ? document.getElementById(targetId) : closeTrigger.closest('.modal-overlay');
            if (modal && modal.id) {
                event.preventDefault();
                closeModal(modal.id);
            }
            return;
        }

        const overlay = event.target.closest('.modal-overlay.open');
        if (overlay && overlay.dataset.modalIgnore !== 'true' && event.target === overlay) {
            if (overlay.dataset.modalOverlay !== 'false') {
                closeModal(overlay.id);
            }
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeTopModal();
        }
    });

    window.openModal = openModal;
    window.closeModal = closeModal;
})();
