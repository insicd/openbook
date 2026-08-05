/**
 * Scorciatoia "nuovo post":
 * - in home: mostra + quando il composer inline esce dalla viewport e al
 *   click vi riporta il focus (comportamento storico);
 * - nelle altre pagine: + sempre visibile e apre il dialog con lo stesso
 *   composer (fallback senza JS: link alla home con #ob-composer).
 */
(function () {
    'use strict';

    var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-compose-trigger]'));
    var modal = document.getElementById('ob-compose-modal');
    var pageComposer = document.getElementById('ob-composer');
    var isHome = buttons.some(function (button) {
        return button.getAttribute('data-compose-home') === '1';
    });

    if (buttons.length === 0) {
        return;
    }

    var scrollFallback = 180;
    var composerGap = 12;
    var ticking = false;
    var lastFocus = null;

    function headerHeight() {
        var header = document.querySelector('.ob-header');

        return header ? Math.ceil(header.getBoundingClientRect().height) : 64;
    }

    function setVisible(visible) {
        buttons.forEach(function (button) {
            button.classList.toggle('is-visible', visible);
            button.setAttribute('aria-hidden', visible ? 'false' : 'true');
            button.tabIndex = visible ? 0 : -1;
        });
    }

    function shouldShowOnHome() {
        if (!pageComposer) {
            return window.scrollY > scrollFallback;
        }

        var rect = pageComposer.getBoundingClientRect();

        return rect.bottom < headerHeight() + composerGap;
    }

    function updateHomeVisibility() {
        setVisible(shouldShowOnHome());
    }

    function focusPageComposer() {
        if (!pageComposer) {
            return false;
        }

        var targetTop = window.scrollY + pageComposer.getBoundingClientRect().top - headerHeight() - composerGap;

        window.scrollTo({
            top: Math.max(0, targetTop),
            behavior: 'smooth',
        });

        window.setTimeout(function () {
            var field = pageComposer.querySelector('#composer-body, textarea[name="body"]');
            if (field) {
                field.focus({ preventScroll: true });
            }
        }, 320);

        return true;
    }

    function openModal() {
        if (!modal) {
            return false;
        }

        if (!modal.hidden) {
            var already = modal.querySelector('#modal-composer-body, textarea[name="body"]');
            if (already) {
                already.focus();
            }
            return true;
        }

        lastFocus = document.activeElement;
        modal.hidden = false;
        document.documentElement.classList.add('ob-compose-modal-open');

        window.setTimeout(function () {
            var field = modal.querySelector('#modal-composer-body, textarea[name="body"]');
            if (field) {
                field.focus();
            }
        }, 30);

        return true;
    }

    function closeModal() {
        if (!modal || modal.hidden) {
            return;
        }

        modal.hidden = true;
        document.documentElement.classList.remove('ob-compose-modal-open');

        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
        lastFocus = null;
    }

    function onTriggerClick(event) {
        if (isHome) {
            if (focusPageComposer()) {
                event.preventDefault();
            }
            return;
        }

        if (openModal()) {
            event.preventDefault();
        }
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', onTriggerClick);
    });

    if (modal) {
        modal.querySelectorAll('[data-compose-modal-close]').forEach(function (closer) {
            closer.addEventListener('click', function (event) {
                event.preventDefault();
                closeModal();
            });
        });

        // Evita che i click nel dialog chiudano il modal (backdrop e' sibling).
        var dialog = modal.querySelector('.ob-compose-modal__dialog');
        if (dialog) {
            dialog.addEventListener('click', function (event) {
                event.stopPropagation();
            });
        }
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal && !modal.hidden) {
            event.preventDefault();
            closeModal();
        }
    });

    if (isHome) {
        window.addEventListener('scroll', function () {
            if (ticking) {
                return;
            }

            ticking = true;
            window.requestAnimationFrame(function () {
                updateHomeVisibility();
                ticking = false;
            });
        }, { passive: true });

        window.addEventListener('resize', updateHomeVisibility);

        if (window.location.hash === '#ob-composer') {
            focusPageComposer();
        }

        updateHomeVisibility();
    } else {
        setVisible(true);

        if (modal && modal.getAttribute('data-open-on-load') === '1') {
            openModal();
        }
    }
})();
