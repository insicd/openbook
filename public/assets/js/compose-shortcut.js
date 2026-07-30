/**
 * Scorciatoia "nuovo post": mostra un pulsante + (in header su desktop,
 * FAB in basso a destra su mobile) quando il composer non e' piu' in vista
 * (o dopo un po' di scroll se la pagina non ha un composer). Al click
 * riporta il focus sul composer, oppure naviga alla Home con #ob-composer.
 */
(function () {
    'use strict';

    var headerBtn = document.getElementById('ob-compose-header');
    var fab = document.getElementById('ob-compose-fab');
    var buttons = [headerBtn, fab].filter(Boolean);

    if (buttons.length === 0) {
        return;
    }

    var composer = document.getElementById('ob-composer');
    var scrollFallback = 180;
    var composerGap = 12;
    var ticking = false;

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

    function shouldShow() {
        if (!composer) {
            return window.scrollY > scrollFallback;
        }

        var rect = composer.getBoundingClientRect();

        // Composer uscito verso l'alto oltre la sticky header.
        return rect.bottom < headerHeight() + composerGap;
    }

    function update() {
        setVisible(shouldShow());
    }

    function focusComposer() {
        if (!composer) {
            return false;
        }

        // scrollIntoView(block:start) ignora la navbar sticky: calcoliamo
        // la posizione cosi' il bordo superiore del composer resta sotto
        // l'header e tutto il box resta leggibile.
        var targetTop = window.scrollY + composer.getBoundingClientRect().top - headerHeight() - composerGap;

        window.scrollTo({
            top: Math.max(0, targetTop),
            behavior: 'smooth',
        });

        window.setTimeout(function () {
            var field = composer.querySelector('#composer-body, textarea[name="body"]');
            if (field) {
                field.focus({ preventScroll: true });
            }
        }, 320);

        return true;
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', function (event) {
            if (focusComposer()) {
                event.preventDefault();
            }
        });
    });

    window.addEventListener('scroll', function () {
        if (ticking) {
            return;
        }

        ticking = true;
        window.requestAnimationFrame(function () {
            update();
            ticking = false;
        });
    }, { passive: true });

    window.addEventListener('resize', update);

    if (window.location.hash === '#ob-composer') {
        focusComposer();
    }

    update();
})();
