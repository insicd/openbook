(function () {
    'use strict';

    var widget = document.querySelector('[data-trending-widget]');

    if (!widget) {
        return;
    }

    var content = widget.querySelector('[data-trending-content]');
    var desktop = window.matchMedia('(min-width: 1024px)');
    var loading = false;
    var loaded = false;

    function showLoading() {
        var message = document.createElement('p');
        message.className = 'ob-field__help';
        message.textContent = widget.getAttribute('data-loading-label');
        content.replaceChildren(message);
    }

    function showError() {
        var message = document.createElement('p');
        message.className = 'ob-field__help';
        message.textContent = widget.getAttribute('data-error-label');

        var retry = document.createElement('a');
        retry.href = widget.getAttribute('data-url');
        retry.textContent = widget.getAttribute('data-retry-label');
        retry.addEventListener('click', function (event) {
            event.preventDefault();
            load();
        });

        content.replaceChildren(message, retry);
    }

    function showResults(data) {
        if (!Array.isArray(data.hashtags)) {
            throw new Error('invalid trending response');
        }

        if (data.hashtags.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'ob-field__help';
            empty.textContent = widget.getAttribute('data-empty-label');
            content.replaceChildren(empty);
            return;
        }

        var list = document.createElement('ul');
        list.className = 'ob-hashtag-list';

        data.hashtags.forEach(function (hashtag) {
            var item = document.createElement('li');
            var link = document.createElement('a');
            link.href = hashtag.url;
            link.textContent = '#' + hashtag.name;

            var count = document.createElement('span');
            count.className = 'ob-field__help';
            count.textContent = hashtag.uses;

            item.append(link, count);
            list.appendChild(item);
        });

        content.replaceChildren(list);

        if (data.has_more) {
            var more = document.createElement('a');
            more.className = 'ob-side-widget__more';
            more.href = widget.getAttribute('data-more-url');
            more.textContent = widget.getAttribute('data-more-label');
            content.appendChild(more);
        }
    }

    function load() {
        if (!desktop.matches || loading || loaded) {
            return;
        }

        loading = true;
        showLoading();
        fetch(widget.getAttribute('data-url'), {
            headers: { 'Accept': 'application/json' }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('unexpected response status ' + response.status);
                }

                return response.json();
            })
            .then(function (data) {
                showResults(data);
                loaded = true;
                observer.disconnect();
            })
            .catch(showError)
            .finally(function () {
                loading = false;
            });
    }

    function isInViewport() {
        var bounds = widget.getBoundingClientRect();

        return bounds.bottom > 0 && bounds.top < window.innerHeight;
    }

    var observer = new IntersectionObserver(function (entries) {
        if (entries.some(function (entry) { return entry.isIntersecting; })) {
            load();
        }
    });

    showLoading();
    observer.observe(widget);
    desktop.addEventListener('change', function () {
        if (desktop.matches && isInViewport()) {
            load();
        }
    });
})();
