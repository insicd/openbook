/** Carica le anteprime dei link senza rallentare il rendering della pagina. */
(function () {
    'use strict';

    var waiting = [];
    var active = 0;
    var maxActive = 3;

    function addText(parent, className, value) {
        if (!value) {
            return;
        }

        var element = document.createElement('span');
        element.className = className;
        element.textContent = value;
        parent.appendChild(element);
    }

    function showPreview(container, data) {
        if (!data || !data.available || !data.title || !data.url) {
            return;
        }

        var url;

        try {
            url = new URL(data.url);
        } catch (error) {
            return;
        }

        if (url.protocol !== 'https:' && url.protocol !== 'http:') {
            return;
        }

        var link = document.createElement('a');
        link.href = url.href;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';

        if (data.image_url) {
            var image = document.createElement('img');
            image.src = data.image_url;
            image.alt = '';
            image.loading = 'lazy';
            image.referrerPolicy = 'no-referrer';
            image.addEventListener('error', function () { image.remove(); });
            link.appendChild(image);
        }

        var content = document.createElement('span');
        content.className = 'ob-post__link-preview-content';
        addText(content, 'ob-post__link-preview-site', data.site_name || url.hostname);
        addText(content, 'ob-post__link-preview-title', data.title);
        addText(content, 'ob-post__link-preview-description', data.description);
        link.appendChild(content);
        container.appendChild(link);
        container.hidden = false;
    }

    function fetchPreview(container) {
        active++;

        fetch(container.getAttribute('data-link-preview-url'), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Preview unavailable');
                }

                return response.json();
            })
            .then(function (data) { showPreview(container, data); })
            .catch(function () { /* The original link remains in the post. */ })
            .finally(function () {
                active--;
                drain();
            });
    }

    function drain() {
        while (active < maxActive && waiting.length > 0) {
            fetchPreview(waiting.shift());
        }
    }

    function queue(container) {
        if (container.dataset.linkPreviewQueued) {
            return;
        }

        container.dataset.linkPreviewQueued = '1';
        waiting.push(container);
        drain();
    }

    function scan(root) {
        if (root.nodeType !== 1) {
            return;
        }

        if (root.matches('[data-link-preview-url]')) {
            queue(root);
        }

        root.querySelectorAll('[data-link-preview-url]').forEach(queue);
    }

    scan(document.body);

    new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(scan);
        });
    }).observe(document.body, { childList: true, subtree: true });
})();
