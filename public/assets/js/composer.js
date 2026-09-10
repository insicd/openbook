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

    function formatFileSize(bytes) {
        var unit = bytes >= 1024 * 1024 ? 'MB' : 'KB';
        var divisor = unit === 'MB' ? 1024 * 1024 : 1024;
        return (Math.round(bytes / divisor * 10) / 10) + ' ' + unit;
    }

    function validateFileSizes(field, report) {
        field.setCustomValidity('');

        var mediaMax = Number(field.dataset.maxMediaBytes || 0);
        var videoMax = Number(field.dataset.maxVideoBytes || 0);
        var videoMimes = (field.dataset.videoMimeTypes || '').split(',');

        Array.prototype.some.call(field.files || [], function (file) {
            var isVideo = videoMax > 0 && (videoMimes.indexOf(file.type) !== -1 || /\.(mp4|m4v|mov|webm|ogv)$/i.test(file.name));
            var max = isVideo ? videoMax : mediaMax;

            if (max <= 0 || file.size <= max) {
                return false;
            }

            var message = field.dataset.fileTooLarge || 'The selected file is too large.';
            field.setCustomValidity(message
                .replace(':file', file.name)
                .replace(':size', formatFileSize(file.size))
                .replace(':limit', formatFileSize(max)));
            return true;
        });

        if (report && !field.checkValidity()) {
            field.reportValidity();
        }

        return field.checkValidity();
    }

    function resetComposerUpload(form) {
        var submit = form.querySelector('.ob-composer__submit');
        var upload = form.querySelector('[data-composer-upload]');
        var progress = form.querySelector('[data-composer-upload-progress]');
        var percent = form.querySelector('[data-composer-upload-percent]');

        delete form.dataset.uploading;
        form.removeAttribute('aria-busy');
        if (submit) {
            submit.disabled = false;
        }
        if (upload) {
            upload.hidden = true;
        }
        if (progress) {
            progress.value = 0;
        }
        if (percent) {
            percent.hidden = false;
            percent.textContent = '0%';
        }
    }

    function uploadComposer(form) {
        var submit = form.querySelector('.ob-composer__submit');
        var upload = form.querySelector('[data-composer-upload]');
        var progress = form.querySelector('[data-composer-upload-progress]');
        var percent = form.querySelector('[data-composer-upload-percent]');
        var xhr = new XMLHttpRequest();

        form.dataset.uploading = '1';
        form.setAttribute('aria-busy', 'true');
        if (submit) {
            submit.disabled = true;
        }
        if (upload) {
            upload.hidden = false;
        }

        xhr.open(form.method || 'POST', form.action);
        xhr.responseType = 'json';
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.addEventListener('progress', function (event) {
            if (!event.lengthComputable || !progress) {
                if (progress) {
                    progress.removeAttribute('value');
                }
                if (percent) {
                    percent.hidden = true;
                }
                return;
            }

            var value = Math.min(100, Math.round(event.loaded / event.total * 100));
            progress.value = value;
            if (percent) {
                percent.hidden = false;
                percent.textContent = value + '%';
            }
        });

        function fail(message) {
            resetComposerUpload(form);
            window.alert(message || form.dataset.uploadFailed || 'Upload failed. Please try again.');
        }

        xhr.addEventListener('load', function () {
            var response = xhr.response || {};
            if (xhr.status >= 200 && xhr.status < 300 && response.redirect) {
                window.location.assign(response.redirect);
                return;
            }

            var errors = response.errors || {};
            var firstError = Object.keys(errors).reduce(function (found, key) {
                return found || (Array.isArray(errors[key]) ? errors[key][0] : errors[key]);
            }, '');
            fail(firstError || response.message);
        });
        xhr.addEventListener('error', function () {
            fail();
        });
        xhr.addEventListener('abort', function () {
            fail();
        });
        xhr.send(new FormData(form));
    }

    function hasSelectedFiles(form) {
        return Array.prototype.some.call(form.querySelectorAll('input[type="file"]'), function (field) {
            return field.files && field.files.length > 0;
        });
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
                if (field.type === 'file') {
                    validateFileSizes(field, true);
                }
                syncFilledState(composer);
            });
        });

        var form = composer.querySelector('form');
        if (form) {
            form.addEventListener('submit', function (event) {
                if (form.dataset.uploading === '1') {
                    event.preventDefault();
                    return;
                }

                var valid = true;
                form.querySelectorAll('input[type="file"][data-max-media-bytes]').forEach(function (field) {
                    valid = validateFileSizes(field, false) && valid;
                });
                if (!valid) {
                    event.preventDefault();
                    form.reportValidity();
                    return;
                }

                if (form.dataset.composerAjaxUpload === '1'
                    && form.dataset.composerEditing !== '1'
                    && hasSelectedFiles(form)
                    && window.FormData
                    && window.XMLHttpRequest) {
                    event.preventDefault();
                    uploadComposer(form);
                }
            });
        }

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

    // Safari puo' ripristinare dal back-forward cache il pulsante disabilitato.
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) {
            return;
        }
        document.querySelectorAll('form[data-composer-ajax-upload]').forEach(resetComposerUpload);
    });

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
