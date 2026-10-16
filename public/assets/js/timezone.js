(function () {
    'use strict';

    var script = document.currentScript;
    var cookieName = 'openbook_timezone';

    function cookieValue() {
        var cookies = document.cookie.split(';');
        for (var i = 0; i < cookies.length; i++) {
            var pair = cookies[i].trim();
            if (pair.indexOf(cookieName + '=') === 0) {
                return pair.slice(cookieName.length + 1);
            }
        }
        return '';
    }

    try {
        var timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if (!timezone) return;
        var encoded = encodeURIComponent(timezone);
        if (cookieValue() === encoded) return;

        document.cookie = cookieName + '=' + encoded + '; Path=/; Max-Age=31536000; SameSite=Lax'
            + (location.protocol === 'https:' ? '; Secure' : '');

        // Run in <head>, before forms can be edited. Blocked cookies must not cause reloads.
        if (script.dataset.automatic === '1' && script.dataset.safeRequest === '1'
            && cookieValue() === encoded && script.dataset.timezone !== timezone) {
            location.reload();
        }
    } catch (error) {
        // Without Intl or cookie access, the server's fallback remains usable.
    }
})();
