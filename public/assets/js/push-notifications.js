(function () {
    'use strict';

    var root = document.getElementById('push-notification-settings');
    if (!root) {
        return;
    }

    var status = root.querySelector('[data-push-status]');
    var enableButton = root.querySelector('[data-push-enable]');
    var disableButton = root.querySelector('[data-push-disable]');
    var knownHashes = new Set(JSON.parse(root.dataset.subscriptionHashes || '[]'));
    var currentSubscription = null;

    function showState(label, canEnable, canDisable) {
        root.hidden = false;
        status.textContent = label;
        enableButton.hidden = !canEnable;
        disableButton.hidden = !canDisable;
        enableButton.disabled = false;
        disableButton.disabled = false;
    }

    function supported() {
        return window.isSecureContext
            && 'serviceWorker' in navigator
            && 'PushManager' in window
            && 'Notification' in window
            && window.crypto
            && window.crypto.subtle;
    }

    function base64UrlBytes(value) {
        var padding = '='.repeat((4 - value.length % 4) % 4);
        var binary = window.atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(binary, function (character) { return character.charCodeAt(0); });
    }

    function sameBytes(left, right) {
        if (!left || left.byteLength !== right.byteLength) {
            return false;
        }
        var bytes = new Uint8Array(left);
        for (var index = 0; index < bytes.length; index += 1) {
            if (bytes[index] !== right[index]) {
                return false;
            }
        }
        return true;
    }

    async function endpointHash(endpoint) {
        var digest = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(endpoint));
        return Array.from(new Uint8Array(digest), function (byte) {
            return byte.toString(16).padStart(2, '0');
        }).join('');
    }

    async function request(url, method, body) {
        var response = await window.fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify(body)
        });

        if (!response.ok) {
            throw new Error('Push subscription request failed.');
        }
    }

    async function refreshState() {
        if (!supported()) {
            showState(root.dataset.labelUnsupported, false, false);
            return;
        }
        if (Notification.permission === 'denied') {
            showState(root.dataset.labelDenied, false, false);
            return;
        }

        var registration = await navigator.serviceWorker.register('/service-worker.js');
        currentSubscription = await registration.pushManager.getSubscription();

        if (!currentSubscription) {
            showState(root.dataset.labelInactive, true, false);
            return;
        }

        var hash = await endpointHash(currentSubscription.endpoint);
        var expectedKey = base64UrlBytes(root.dataset.vapidPublicKey);
        var matchesKey = sameBytes(currentSubscription.options.applicationServerKey, expectedKey);

        if (knownHashes.has(hash) && matchesKey) {
            showState(root.dataset.labelActive, false, true);
        } else {
            showState(root.dataset.labelReattach, true, false);
        }
    }

    enableButton.addEventListener('click', async function () {
        enableButton.disabled = true;
        try {
            if (Notification.permission !== 'granted') {
                var permission = await Notification.requestPermission();
                if (permission !== 'granted') {
                    await refreshState();
                    return;
                }
            }

            var registration = await navigator.serviceWorker.ready;
            var expectedKey = base64UrlBytes(root.dataset.vapidPublicKey);
            currentSubscription = await registration.pushManager.getSubscription();

            if (currentSubscription && !sameBytes(currentSubscription.options.applicationServerKey, expectedKey)) {
                await currentSubscription.unsubscribe();
                currentSubscription = null;
            }
            if (!currentSubscription) {
                currentSubscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: expectedKey
                });
            }

            await request(root.dataset.subscribeUrl, 'POST', currentSubscription.toJSON());
            knownHashes.add(await endpointHash(currentSubscription.endpoint));
            showState(root.dataset.labelActive, false, true);
        } catch (error) {
            showState(root.dataset.labelError, true, false);
        }
    });

    disableButton.addEventListener('click', async function () {
        disableButton.disabled = true;
        try {
            await request(root.dataset.unsubscribeUrl, 'DELETE', {endpoint: currentSubscription.endpoint});
            knownHashes.delete(await endpointHash(currentSubscription.endpoint));
            await currentSubscription.unsubscribe();
            currentSubscription = null;
            showState(root.dataset.labelInactive, true, false);
        } catch (error) {
            showState(root.dataset.labelError, false, true);
        }
    });

    refreshState().catch(function () {
        showState(root.dataset.labelError, true, false);
    });
})();
