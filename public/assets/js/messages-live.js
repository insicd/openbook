/**
 * Thread messaggi: invio Ajax e polling per nuovi messaggi senza ricaricare.
 */
(function () {
    'use strict';

    var thread = document.getElementById('ob-message-thread');

    if (!thread) {
        return;
    }

    var feedUrl = thread.getAttribute('data-feed-url');
    var pollMs = parseInt(thread.getAttribute('data-poll-ms') || '5000', 10);
    var emptyLabel = thread.getAttribute('data-empty-label') || '';
    var form = document.getElementById('ob-message-composer');
    var textarea = document.getElementById('message-body');
    var submitBtn = document.getElementById('ob-message-submit');
    var errorEl = document.getElementById('ob-message-error');
    var i18n = document.getElementById('ob-message-i18n');
    var sendErrorLabel = i18n ? i18n.getAttribute('data-send-error') || 'Invio non riuscito.' : 'Invio non riuscito.';

    var lastMessageId = thread.getAttribute('data-last-message-id') || null;
    var etag = null;
    var timer = null;
    var pollInFlight = false;
    var sendInFlight = false;

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function clearError() {
        if (!errorEl) {
            return;
        }

        errorEl.hidden = true;
        errorEl.textContent = '';
    }

    function showError(message) {
        if (!errorEl) {
            return;
        }

        errorEl.textContent = message;
        errorEl.hidden = false;
    }

    function removeEmptyState() {
        var empty = thread.querySelector('[data-message-empty]');

        if (empty) {
            empty.remove();
        }
    }

    function isNearBottom() {
        var threshold = 80;

        return thread.scrollHeight - thread.scrollTop - thread.clientHeight <= threshold;
    }

    function scrollToBottom() {
        thread.scrollTop = thread.scrollHeight;
    }

    function setLastMessageId(id) {
        lastMessageId = id || null;
        thread.setAttribute('data-last-message-id', id || '');
    }

    function existingMessageIds() {
        var ids = {};

        thread.querySelectorAll('[data-message-id]').forEach(function (el) {
            ids[el.getAttribute('data-message-id')] = true;
        });

        return ids;
    }

    function renderBubble(item) {
        var mineClass = item.mine ? 'ob-message-bubble--mine' : 'ob-message-bubble--theirs';

        return (
            '<article class="ob-message-bubble ' +
            mineClass +
            '" data-message-id="' +
            escapeHtml(item.id) +
            '">' +
            '<div class="ob-message-bubble__meta">' +
            '<span>' +
            escapeHtml(item.author_name) +
            '</span>' +
            '<time datetime="' +
            escapeHtml(item.published_at) +
            '">' +
            escapeHtml(item.published_label) +
            '</time>' +
            '</div>' +
            '<div class="ob-message-bubble__body">' +
            item.body_html +
            '</div>' +
            '</article>'
        );
    }

    function appendMessages(items) {
        if (!items || items.length === 0) {
            return;
        }

        var known = existingMessageIds();
        var stickToBottom = isNearBottom();
        var appended = false;

        items.forEach(function (item) {
            if (!item.id || known[item.id]) {
                return;
            }

            removeEmptyState();
            thread.insertAdjacentHTML('beforeend', renderBubble(item));
            known[item.id] = true;
            setLastMessageId(item.id);
            appended = true;
        });

        if (appended && stickToBottom) {
            scrollToBottom();
        }
    }

    function buildFeedUrl() {
        var url = new URL(feedUrl, window.location.origin);

        if (lastMessageId) {
            url.searchParams.set('after', lastMessageId);
        }

        return url.toString();
    }

    function poll() {
        if (pollInFlight || document.hidden) {
            return;
        }

        pollInFlight = true;

        var headers = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };

        if (etag) {
            headers['If-None-Match'] = etag;
        }

        fetch(buildFeedUrl(), {
            method: 'GET',
            headers: headers,
            credentials: 'same-origin',
            cache: 'no-store',
        })
            .then(function (response) {
                if (response.status === 304) {
                    return null;
                }

                if (!response.ok) {
                    throw new Error('feed failed');
                }

                var nextEtag = response.headers.get('ETag');

                if (nextEtag) {
                    etag = nextEtag;
                }

                return response.json();
            })
            .then(function (payload) {
                if (payload && payload.messages) {
                    appendMessages(payload.messages);
                }
            })
            .catch(function () {
                // Ignora errori di rete occasionali.
            })
            .finally(function () {
                pollInFlight = false;
            });
    }

    function schedulePoll() {
        if (timer) {
            clearInterval(timer);
        }

        timer = setInterval(poll, pollMs);
    }

    function sendMessage(event) {
        if (!form || !textarea) {
            return;
        }

        event.preventDefault();
        clearError();

        var body = textarea.value.trim();

        if (body === '' || sendInFlight) {
            return;
        }

        sendInFlight = true;

        if (submitBtn) {
            submitBtn.disabled = true;
        }

        var formData = new FormData(form);

        fetch(form.action, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: formData,
        })
            .then(function (response) {
                if (response.status === 422) {
                    return response.json().then(function (data) {
                        var firstError = data.errors && data.errors.body ? data.errors.body[0] : sendErrorLabel;
                        throw new Error(firstError);
                    });
                }

                if (!response.ok) {
                    throw new Error(sendErrorLabel);
                }

                return response.json();
            })
            .then(function (payload) {
                if (payload && payload.message) {
                    appendMessages([payload.message]);
                    textarea.value = '';
                    textarea.focus();
                    etag = null;
                    poll();
                }
            })
            .catch(function (error) {
                showError(error.message || sendErrorLabel);
            })
            .finally(function () {
                sendInFlight = false;

                if (submitBtn) {
                    submitBtn.disabled = false;
                }
            });
    }

    if (form && form.getAttribute('data-ajax') === '1') {
        form.addEventListener('submit', sendMessage);
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            poll();
        }
    });

    scrollToBottom();
    poll();
    schedulePoll();
})();
