/**
 * Scorciatoia "nuovo post":
 * - in home: mostra + quando il composer inline esce dalla viewport e al
 *   click vi riporta il focus (comportamento storico);
 * - nelle altre pagine: + sempre visibile e apre il dialog con lo stesso
 *   composer (fallback senza JS: link alla home con #ob-composer).
 *
 * Voce menu "Modifica": apre lo stesso dialog precompilato (fallback
 * senza JS: GET /posts/{id}/modifica).
 */
(function () {
    'use strict';

    var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-compose-trigger]'));
    var modal = document.getElementById('ob-compose-modal');
    var pageComposer = document.getElementById('ob-composer');
    var isHome = buttons.some(function (button) {
        return button.getAttribute('data-compose-home') === '1';
    });

    if (buttons.length === 0 && !modal) {
        return;
    }

    var scrollFallback = 180;
    var composerGap = 12;
    var ticking = false;
    var lastFocus = null;
    var editing = false;

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

    function modalForm() {
        return modal ? modal.querySelector('form') : null;
    }

    function modalComposer() {
        return modal ? modal.querySelector('[data-composer]') : null;
    }

    function ensureMethodField(form, method) {
        var existing = form.querySelector('input[name="_method"]');

        if (method === 'POST') {
            if (existing) {
                existing.remove();
            }
            return;
        }

        if (!existing) {
            existing = document.createElement('input');
            existing.type = 'hidden';
            existing.name = '_method';
            form.appendChild(existing);
        }

        existing.value = method;
    }

    function setPanelBySuffix(composer, suffix, open) {
        var panel = composer.querySelector('[data-composer-panel][id$="panel-' + suffix + '"]');
        if (!panel) {
            return;
        }

        var toggle = composer.querySelector('[data-composer-toggle="' + panel.id + '"]');
        panel.hidden = !open;

        if (toggle) {
            toggle.classList.toggle('is-active', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    function setCommunityControlsVisible(composer, visible) {
        composer.querySelectorAll('[data-composer-toggle$="panel-community"]').forEach(function (toggle) {
            toggle.hidden = !visible;
        });
        composer.querySelectorAll('[data-composer-panel][id$="panel-community"]').forEach(function (panel) {
            if (!visible) {
                panel.hidden = true;
            }
        });
    }

    function refreshComposerUi(composer) {
        composer.querySelectorAll('[data-composer-body]').forEach(function (textarea) {
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        });
        composer.querySelectorAll('[data-composer-fill]').forEach(function (field) {
            var eventName = field.tagName === 'SELECT' || field.type === 'file' ? 'change' : 'input';
            field.dispatchEvent(new Event(eventName, { bubbles: true }));
        });
    }

    function setModalChrome(isEdit) {
        var title = document.getElementById('ob-compose-modal-title');
        var form = modalForm();
        var submit = form ? form.querySelector('.ob-composer__submit') : null;

        if (title) {
            title.textContent = isEdit
                ? (modal.getAttribute('data-title-edit') || title.textContent)
                : (modal.getAttribute('data-title-create') || title.textContent);
        }

        if (submit) {
            submit.textContent = isEdit
                ? (modal.getAttribute('data-submit-save') || submit.textContent)
                : (modal.getAttribute('data-submit-create') || submit.textContent);
        }
    }

    function restoreMediaHelp(form) {
        var help = form.querySelector('#modal_composer-panel-media .ob-field__help');
        if (!help) {
            return;
        }

        var original = help.getAttribute('data-default-help');
        if (original !== null) {
            help.textContent = original;
        }
    }

    function visibilityOriginalDefault(select) {
        if (!select) {
            return 'public';
        }

        if (!select.hasAttribute('data-composer-original-default')) {
            select.setAttribute(
                'data-composer-original-default',
                select.getAttribute('data-composer-default') || 'public'
            );
        }

        return select.getAttribute('data-composer-original-default');
    }

    function resetComposerToCreate() {
        var form = modalForm();
        var composer = modalComposer();

        if (!form || !composer) {
            editing = false;
            return;
        }

        var createAction = form.getAttribute('data-composer-create-action');
        if (createAction) {
            form.action = createAction;
        }

        form.removeAttribute('data-composer-editing');
        ensureMethodField(form, 'POST');
        form.reset();
        restoreMediaHelp(form);
        setCommunityControlsVisible(composer, true);
        setModalChrome(false);

        var visibility = form.querySelector('[data-composer-fill="visibility"]');
        var defaultVisibility = visibilityOriginalDefault(visibility);
        if (visibility) {
            visibility.setAttribute('data-composer-default', defaultVisibility);
        }

        setPanelBySuffix(composer, 'title', false);
        setPanelBySuffix(composer, 'cw', false);
        setPanelBySuffix(composer, 'media', false);
        setPanelBySuffix(composer, 'community', false);
        setPanelBySuffix(composer, 'visibility', defaultVisibility !== 'public');
        refreshComposerUi(composer);
        editing = false;
    }

    function applyEditFromLink(link) {
        var form = modalForm();
        var composer = modalComposer();

        if (!form || !composer) {
            return false;
        }

        resetComposerToCreate();

        var action = link.getAttribute('data-edit-action') || link.getAttribute('href');
        if (!action) {
            return false;
        }

        form.action = action;
        form.setAttribute('data-composer-editing', '1');
        ensureMethodField(form, 'PUT');

        var body = form.querySelector('[data-composer-body]');
        var title = form.querySelector('[data-composer-fill="title"]');
        var cw = form.querySelector('[data-composer-fill="cw"]');
        var visibility = form.querySelector('[data-composer-fill="visibility"]');
        var bodyValue = link.getAttribute('data-edit-body') || '';
        var titleValue = link.getAttribute('data-edit-title') || '';
        var cwValue = link.getAttribute('data-edit-cw') || '';
        var visibilityValue = link.getAttribute('data-edit-visibility') || 'public';
        var mediaHelpText = link.getAttribute('data-edit-media-help');

        if (body) {
            body.value = bodyValue;
        }
        if (title) {
            title.value = titleValue;
        }
        if (cw) {
            cw.value = cwValue;
        }
        if (visibility) {
            visibilityOriginalDefault(visibility);
            visibility.value = visibilityValue;
            visibility.setAttribute('data-composer-default', visibilityValue);
        }

        var help = form.querySelector('#modal_composer-panel-media .ob-field__help');
        if (help && mediaHelpText) {
            if (help.getAttribute('data-default-help') === null) {
                help.setAttribute('data-default-help', help.textContent);
            }
            help.textContent = mediaHelpText;
        }

        setCommunityControlsVisible(composer, false);
        setModalChrome(true);
        setPanelBySuffix(composer, 'title', titleValue.trim() !== '');
        setPanelBySuffix(composer, 'cw', cwValue.trim() !== '');
        setPanelBySuffix(composer, 'media', mediaHelpText !== null);
        setPanelBySuffix(composer, 'visibility', visibilityValue !== 'public');
        refreshComposerUi(composer);
        editing = true;

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
        resetComposerToCreate();

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

        if (editing) {
            resetComposerToCreate();
        }

        if (openModal()) {
            event.preventDefault();
        }
    }

    function onEditClick(event) {
        var link = event.target.closest('[data-edit-post]');
        if (!link || !modal) {
            return;
        }

        if (!applyEditFromLink(link)) {
            return;
        }

        if (openModal()) {
            event.preventDefault();
        }
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', onTriggerClick);
    });

    document.addEventListener('click', onEditClick);

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

    if (buttons.length === 0) {
        return;
    }

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
