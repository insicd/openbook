/**
 * Comportamenti condivisi del composer (post / commento / reply):
 * - un solo pannello opzioni aperto alla volta (fisarmonica)
 * - textarea che cresce con il testo
 * - evidenziazione dei toggle quando l'opzione ha un valore
 */
(function () {
    'use strict';

    function setPanelOpen(composer, panel, button, open) {
        panel.hidden = !open;
        if (button) {
            button.classList.toggle('is-active', open);
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        if (open) {
            var focusable = panel.querySelector('input:not([type="hidden"]), textarea, select');
            if (focusable) {
                focusable.focus();
            }
        }
    }

    function closeOtherPanels(composer, keepPanel) {
        composer.querySelectorAll('[data-composer-panel]').forEach(function (panel) {
            if (panel === keepPanel || panel.hidden) {
                return;
            }

            var toggle = composer.querySelector('[data-composer-toggle="' + panel.id + '"]');
            setPanelOpen(composer, panel, toggle, false);
        });
    }

    function syncFilledState(composer) {
        composer.querySelectorAll('[data-composer-fill]').forEach(function (field) {
            var key = field.getAttribute('data-composer-fill');
            var toggle = composer.querySelector('[data-composer-toggle$="panel-' + key + '"]');
            if (!toggle) {
                // id usa prefisso: cerca per suffisso pannello
                var panel = field.closest('[data-composer-panel]');
                if (panel) {
                    toggle = composer.querySelector('[data-composer-toggle="' + panel.id + '"]');
                }
            }

            if (!toggle) {
                return;
            }

            var filled = false;

            if (field.type === 'file') {
                filled = field.files && field.files.length > 0;
            } else if (field.tagName === 'SELECT') {
                var defaultValue = field.getAttribute('data-composer-default') || '';
                filled = field.value !== '' && field.value !== defaultValue;
            } else {
                filled = String(field.value || '').trim() !== '';
            }

            toggle.classList.toggle('is-filled', filled);
        });
    }

    function autoGrow(textarea) {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 320) + 'px';
    }

    function bindComposer(composer) {
        if (composer.dataset.composerBound === '1') {
            return;
        }
        composer.dataset.composerBound = '1';

        composer.querySelectorAll('[data-composer-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var panelId = button.getAttribute('data-composer-toggle');
                var panel = document.getElementById(panelId);
                if (!panel) {
                    return;
                }

                var willOpen = panel.hidden;
                if (willOpen) {
                    closeOtherPanels(composer, panel);
                }
                setPanelOpen(composer, panel, button, willOpen);
            });
        });

        composer.querySelectorAll('[data-composer-body]').forEach(function (textarea) {
            autoGrow(textarea);
            textarea.addEventListener('input', function () {
                autoGrow(textarea);
            });
        });

        composer.querySelectorAll('[data-composer-fill]').forEach(function (field) {
            var eventName = field.type === 'file' ? 'change' : 'input';
            if (field.tagName === 'SELECT') {
                eventName = 'change';
            }
            field.addEventListener(eventName, function () {
                syncFilledState(composer);
            });
        });

        syncFilledState(composer);

        // Tip Markdown: un solo details aperto per composer; click fuori chiude.
        composer.querySelectorAll('.ob-composer__tip').forEach(function (tip) {
            tip.addEventListener('toggle', function () {
                if (!tip.open) {
                    return;
                }
                composer.querySelectorAll('.ob-composer__tip').forEach(function (other) {
                    if (other !== tip) {
                        other.open = false;
                    }
                });
            });
        });
    }

    function init() {
        document.querySelectorAll('[data-composer]').forEach(bindComposer);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Reply form reso visibile in seguito: ri-bind se necessario.
    document.addEventListener('click', function (event) {
        var replyBtn = event.target.closest('[onclick*="risposta-"]');
        if (!replyBtn) {
            return;
        }
        window.setTimeout(function () {
            document.querySelectorAll('[data-composer]').forEach(bindComposer);
        }, 0);
    });

    // Evita che il click sul summary tip faccia submit o scroll strani.
    document.addEventListener('click', function (event) {
        var summary = event.target.closest('.ob-composer__tip-trigger');
        if (!summary) {
            return;
        }
        // Lascia il comportamento nativo di <details>.
        event.stopPropagation();
    });
})();
