/**
 * Scorrimento infinito per elenchi paginati (feed, Mondo, profilo, hashtag,
 * galleria foto, eventi, "Da scoprire", follower/seguiti). Gli elenchi diversi
 * dalla Home mantengono la paginazione in <noscript>; la Home richiede JavaScript.
 *
 * Quando il segnaposto diventa visibile, scarica la pagina indicata in
 * "data-next-url" e aggiunge i figli di `[data-infinite-scroll]`. Nella Home
 * usa la stessa funzione per caricare il primo blocco e i successivi, che
 * arrivano come frammenti HTML. Anche le pagine successive degli eventi
 * arrivano come frammenti; gli altri elenchi ricevono la pagina completa.
 */
(function () {
    'use strict';

    var container = document.querySelector('[data-infinite-scroll]');

    if (!container) {
        return;
    }

    var nextUrl = container.getAttribute('data-next-url');
    var isHomeFeed = container.hasAttribute('data-home-feed');
    var initialLoad = container.hasAttribute('data-initial-load');

    if (!nextUrl) {
        return;
    }

    var status = document.createElement('p');
    status.className = 'ob-infinite-scroll__status';
    status.hidden = true;
    container.insertAdjacentElement('afterend', status);

    var sentinel = document.createElement('div');
    sentinel.className = 'ob-infinite-scroll__sentinel';
    status.insertAdjacentElement('beforebegin', sentinel);

    var loading = false;

    function setStatus(text) {
        status.textContent = text || '';
        status.hidden = !text;
    }

    function stopObserving() {
        observer.disconnect();
        sentinel.remove();
    }

    function showRetry() {
        observer.disconnect();
        setStatus(container.getAttribute('data-error-label'));

        var retry = document.createElement('a');
        retry.href = nextUrl;
        retry.textContent = container.getAttribute('data-retry-label');
        retry.addEventListener('click', function (event) {
            event.preventDefault();
            loadNextPage();
        });

        status.append(' ', retry);
    }

    function loadNextPage() {
        if (loading || !nextUrl) {
            return;
        }

        loading = true;
        setStatus(container.getAttribute('data-loading-label'));

        fetch(nextUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('unexpected response status ' + response.status);
                }

                return response.text();
            })
            .then(function (html) {
                var freshContainer = new DOMParser()
                    .parseFromString(html, 'text/html')
                    .querySelector('[data-infinite-scroll]');

                if (isHomeFeed && !freshContainer) {
                    throw new Error('missing feed fragment');
                }

                while (freshContainer && freshContainer.firstChild) {
                    var node = freshContainer.firstChild;
                    var postId = null;

                    if (node.id && node.id.indexOf('post-') === 0) {
                        postId = node.id.slice(5);
                    }

                    if (postId && container.querySelector('[id="post-' + postId + '"]')) {
                        freshContainer.removeChild(node);
                        continue;
                    }

                    container.appendChild(node);
                }

                nextUrl = freshContainer ? freshContainer.getAttribute('data-next-url') : null;
                loading = false;
                var wasInitialLoad = initialLoad;
                initialLoad = false;

                if (nextUrl) {
                    setStatus('');
                    if (isHomeFeed) {
                        observer.observe(sentinel);
                    }
                } else {
                    stopObserving();
                    setStatus(wasInitialLoad ? '' : container.getAttribute('data-end-label'));
                }
            })
            .catch(function () {
                loading = false;
                if (isHomeFeed) {
                    showRetry();
                } else {
                    nextUrl = null;
                    stopObserving();
                    setStatus(container.getAttribute('data-error-label'));
                }
            });
    }

    var observer = new IntersectionObserver(
        function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    loadNextPage();
                }
            });
        },
        { rootMargin: '600px 0px' }
    );

    if (initialLoad) {
        loadNextPage();
    } else {
        observer.observe(sentinel);
    }
})();
