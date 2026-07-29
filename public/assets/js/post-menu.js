/**
 * Chiude i menu "tre puntini" dei post (<details class="ob-post__menu">)
 * quando si apre un altro menu o si clicca fuori: senza questo, piu' menu
 * resterebbero aperti contemporaneamente e un click fuori non li chiuderebbe
 * (comportamento nativo di <details>).
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var openMenus = document.querySelectorAll('.ob-post__menu[open]');

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

        if (!target.classList || !target.classList.contains('ob-post__menu') || !target.open) {
            return;
        }

        document.querySelectorAll('.ob-post__menu[open]').forEach(function (menu) {
            if (menu !== target) {
                menu.removeAttribute('open');
            }
        });
    }, true);
})();
