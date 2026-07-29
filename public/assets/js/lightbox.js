/**
 * Lightbox per le immagini dei post: nessuna libreria esterna, un solo
 * overlay condiviso da tutta la pagina (vedi il markup in
 * "layouts/app.blade.php") popolato al volo con l'immagine cliccata.
 *
 * Delega gli eventi su "document" invece di legarli a ogni singola <img>:
 * i post vengono renderizzati in molte viste diverse (feed, profilo, tag,
 * pagina del singolo post, sezione "Mondo") e questo file viene caricato
 * una sola volta per l'intera pagina, senza bisogno di "risvegliare" nulla
 * quando cambiano i post mostrati.
 */
(function () {
    'use strict';

    var overlay = document.getElementById('ob-lightbox');

    if (!overlay) {
        return;
    }

    var image = document.getElementById('ob-lightbox-img');
    var closeButton = document.getElementById('ob-lightbox-close');
    var prevButton = document.getElementById('ob-lightbox-prev');
    var nextButton = document.getElementById('ob-lightbox-next');

    var currentGroup = [];
    var currentIndex = -1;
    var triggerElement = null;

    function fullSrcOf(img) {
        return img.getAttribute('data-full-src') || img.currentSrc || img.src;
    }

    function show(index) {
        if (index < 0 || index >= currentGroup.length) {
            return;
        }

        currentIndex = index;

        var img = currentGroup[currentIndex];
        image.src = fullSrcOf(img);
        image.alt = img.alt || '';

        var hasMultiple = currentGroup.length > 1;
        prevButton.hidden = !hasMultiple;
        nextButton.hidden = !hasMultiple;
    }

    function open(group, index, trigger) {
        currentGroup = group;
        triggerElement = trigger;
        show(index);
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
        closeButton.focus();
    }

    function close() {
        overlay.hidden = true;
        image.src = '';
        document.body.style.overflow = '';
        currentGroup = [];
        currentIndex = -1;

        if (triggerElement) {
            triggerElement.focus();
            triggerElement = null;
        }
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-lightbox-trigger]');

        if (!trigger) {
            return;
        }

        event.preventDefault();

        var group = trigger.closest('[data-lightbox-group]');
        var groupImages = group
            ? Array.prototype.slice.call(group.querySelectorAll('[data-lightbox-trigger]'))
            : [trigger];

        open(groupImages, groupImages.indexOf(trigger), trigger);
    });

    closeButton.addEventListener('click', close);
    prevButton.addEventListener('click', function () {
        show((currentIndex - 1 + currentGroup.length) % currentGroup.length);
    });
    nextButton.addEventListener('click', function () {
        show((currentIndex + 1) % currentGroup.length);
    });

    overlay.addEventListener('click', function (event) {
        if (event.target === overlay) {
            close();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (overlay.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            close();
        } else if (event.key === 'ArrowLeft') {
            prevButton.click();
        } else if (event.key === 'ArrowRight') {
            nextButton.click();
        }
    });
})();
