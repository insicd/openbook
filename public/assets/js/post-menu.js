/**
 * Chiude i menu a tendina dei post (overflow "tre puntini" e menu
 * condividi) quando se ne apre un altro o si clicca fuori: senza questo,
 * piu' menu resterebbero aperti contemporaneamente e un click fuori non
 * li chiuderebbe (comportamento nativo di <details>).
 */
(function () {
    'use strict';

    var MENU_SELECTOR = '.ob-post__menu, .ob-post__share-menu';

    document.addEventListener('click', function (event) {
        var openMenus = document.querySelectorAll(MENU_SELECTOR + '[open]');

        if (openMenus.length === 0) {
            return;
        }

        openMenus.forEach(function (menu) {
            if (!menu.contains(event.target)) {
                menu.removeAttribute('open');
            }
        });
    });

    document.addEventListener('toggle', function (event) {
        var target = event.target;

        if (!target.matches || !target.matches(MENU_SELECTOR) || !target.open) {
            return;
        }

        document.querySelectorAll(MENU_SELECTOR + '[open]').forEach(function (menu) {
            if (menu !== target) {
                menu.removeAttribute('open');
            }
        });
    }, true);
})();
