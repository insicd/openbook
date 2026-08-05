/**
 * Menu a tendina dei post (overflow "tre puntini" e menu condividi):
 * - un solo menu aperto alla volta; click fuori lo chiude;
 * - voce "Copia link": scrive l'URL del post nella clipboard.
 */
(function () {
    'use strict';

    var MENU_SELECTOR = '.ob-post__menu, .ob-post__share-menu';

    document.addEventListener('click', function (event) {
        var copyBtn = event.target.closest('[data-copy-url]');

        if (copyBtn) {
            event.preventDefault();
            copyPostUrl(copyBtn);
            return;
        }

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

    function copyPostUrl(button) {
        var url = button.getAttribute('data-copy-url');
        var done = button.getAttribute('data-copy-done') || 'OK';
        var error = button.getAttribute('data-copy-error') || 'Error';
        var labelEl = button.querySelector('[data-copy-text]');
        var original = button.getAttribute('data-copy-label')
            || (labelEl ? labelEl.textContent : '');

        if (!url) {
            return;
        }

        writeClipboard(url).then(function () {
            flashLabel(button, labelEl, done, original);
            closeParentMenu(button);
        }).catch(function () {
            flashLabel(button, labelEl, error, original);
        });
    }

    function writeClipboard(text) {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            var input = document.createElement('textarea');
            input.value = text;
            input.setAttribute('readonly', '');
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.select();
            input.setSelectionRange(0, input.value.length);

            try {
                if (!document.execCommand('copy')) {
                    reject(new Error('copy failed'));
                } else {
                    resolve();
                }
            } catch (err) {
                reject(err);
            } finally {
                document.body.removeChild(input);
            }
        });
    }

    function flashLabel(button, labelEl, message, original) {
        if (labelEl) {
            labelEl.textContent = message;
        } else {
            button.setAttribute('aria-label', message);
        }

        window.setTimeout(function () {
            if (labelEl) {
                labelEl.textContent = original;
            } else if (original) {
                button.setAttribute('aria-label', original);
            }
        }, 1600);
    }

    function closeParentMenu(button) {
        var menu = button.closest(MENU_SELECTOR);
        if (menu) {
            // Lascia leggere "Link copiato" un attimo, poi chiudi.
            window.setTimeout(function () {
                menu.removeAttribute('open');
            }, 700);
        }
    }
})();
