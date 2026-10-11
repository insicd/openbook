(function () {
    'use strict';

    document.querySelectorAll('[data-profile-remote]').forEach(function (details) {
        var summary = details.querySelector('summary');
        var pinned = false;

        function close() {
            pinned = false;
            details.open = false;
        }

        details.addEventListener('mouseenter', function () {
            details.open = true;
        });
        details.addEventListener('mouseleave', function () {
            if (!pinned && !details.contains(document.activeElement)) details.open = false;
        });
        details.addEventListener('focusin', function () {
            details.open = true;
        });
        details.addEventListener('focusout', function (event) {
            if (!pinned && !details.contains(event.relatedTarget)) details.open = false;
        });
        summary.addEventListener('click', function (event) {
            event.preventDefault();
            pinned = !pinned;
            details.open = pinned;
        });
        document.addEventListener('click', function (event) {
            if (!details.contains(event.target)) close();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') close();
        });
    });
})();
