/**
 * Scorrimento infinito per elenchi paginati (feed, Mondo, profilo, hashtag,
 * "Da scoprire", follower/seguiti): sostituisce la paginazione a numeri di
 * pagina, che pero' resta disponibile in <noscript> nelle viste che lo
 * usano, cosi' da non perdere alcuna funzionalita' per chi naviga senza
 * JavaScript.
 *
 * Nessuna libreria esterna, nessuna route/API dedicata sul server: quando il
 * segnaposto in fondo alla pagina diventa visibile, viene semplicemente
 * scaricata la stessa pagina successiva indicata in "data-next-url" (cursore
 * ?cursor=... calcolato lato server), se ne estrae il solo elenco di post
 * (`[data-infinite-scroll]`) e i suoi figli vengono spostati in coda
 * all'elenco corrente.
 */
(function () {
    'use strict';

    var container = document.querySelector('[data-infinite-scroll]');

    if (!container) {
        return;
    }

    var nextUrl = container.getAttribute('data-next-url');

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

                while (freshContainer && freshContainer.firstChild) {
                    var node = freshContainer.firstChild;
                    var postId = null;

                    if (node.id && node.id.indexOf('post-') === 0) {
                        postId = node.id.slice(5);
                    }

                    if (postId && container.querySelector('#post-' + CSS.escape(postId))) {
                        freshContainer.removeChild(node);
                        continue;
                    }

                    container.appendChild(node);
                }

                nextUrl = freshContainer ? freshContainer.getAttribute('data-next-url') : null;
                loading = false;

                if (nextUrl) {
                    setStatus('');
                } else {
                    stopObserving();
                    setStatus(container.getAttribute('data-end-label'));
                }
            })
            .catch(function () {
                loading = false;
                nextUrl = null;
                stopObserving();
                setStatus(container.getAttribute('data-error-label'));
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

    observer.observe(sentinel);
})();
