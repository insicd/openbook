(function () {
    'use strict';

    function bindPicker(picker) {
        if (picker.dataset.locationBound === '1') {
            return;
        }
        picker.dataset.locationBound = '1';

        var input = picker.querySelector('[data-location-search]');
        var idField = picker.querySelector('[data-location-id]');
        var suggestions = picker.querySelector('[data-location-suggestions]');
        var currentButton = picker.querySelector('[data-location-current]');
        var removeButton = picker.querySelector('[data-location-remove]');
        var status = picker.querySelector('[data-location-status]');
        var form = picker.closest('form');
        var timer = null;
        var request = null;

        if (!input || !idField || !suggestions || !form) {
            return;
        }

        function setStatus(message) {
            if (status) {
                status.textContent = message || '';
            }
        }

        function closeSuggestions() {
            suggestions.hidden = true;
            suggestions.replaceChildren();
        }

        function notifyChanged() {
            idField.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function selectLocation(location) {
            idField.value = String(location.id || '');
            input.value = String(location.label || '');
            input.setCustomValidity('');
            removeButton.hidden = idField.value === '';
            closeSuggestions();
            setStatus('');
            notifyChanged();
        }

        function clearLocation(clearText) {
            idField.value = '';
            input.setCustomValidity('');
            if (clearText) {
                input.value = '';
            }
            removeButton.hidden = true;
            closeSuggestions();
            notifyChanged();
        }

        function renderSuggestions(items) {
            suggestions.replaceChildren();

            if (items.length === 0) {
                var empty = document.createElement('p');
                empty.className = 'ob-location-picker__empty';
                empty.textContent = picker.dataset.empty || '';
                suggestions.appendChild(empty);
            } else {
                items.forEach(function (location) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'ob-location-picker__option';
                    button.textContent = location.label;
                    button.addEventListener('click', function () {
                        selectLocation(location);
                    });
                    suggestions.appendChild(button);
                });
            }

            suggestions.hidden = false;
        }

        function search() {
            var query = input.value.trim();

            if (query.length < 2) {
                closeSuggestions();
                return;
            }

            if (request) {
                request.abort();
            }
            request = new AbortController();

            fetch(picker.dataset.suggestUrl + '?q=' + encodeURIComponent(query), {
                headers: { 'Accept': 'application/json' },
                signal: request.signal
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('location-search');
                    }
                    return response.json();
                })
                .then(function (data) {
                    renderSuggestions(Array.isArray(data.suggestions) ? data.suggestions : []);
                })
                .catch(function (error) {
                    if (error.name !== 'AbortError') {
                        closeSuggestions();
                        setStatus(picker.dataset.error || '');
                    }
                });
        }

        input.addEventListener('input', function () {
            clearLocation(false);
            window.clearTimeout(timer);
            timer = window.setTimeout(search, 250);
        });

        if (removeButton) {
            removeButton.addEventListener('click', function () {
                clearLocation(true);
                input.focus();
            });
        }

        if (currentButton) {
            currentButton.addEventListener('click', function () {
                if (!navigator.geolocation) {
                    setStatus(picker.dataset.geolocationError || '');
                    return;
                }

                currentButton.disabled = true;
                navigator.geolocation.getCurrentPosition(function (position) {
                    var token = form.querySelector('input[name="_token"]');

                    fetch(picker.dataset.nearestUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': token ? token.value : ''
                        },
                        body: JSON.stringify({
                            latitude: position.coords.latitude,
                            longitude: position.coords.longitude
                        })
                    })
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error('nearest-location');
                            }
                            return response.json();
                        })
                        .then(function (data) {
                            if (!data.location) {
                                throw new Error('nearest-location');
                            }
                            selectLocation(data.location);
                        })
                        .catch(function () {
                            setStatus(picker.dataset.geolocationError || '');
                        })
                        .finally(function () {
                            currentButton.disabled = false;
                        });
                }, function () {
                    currentButton.disabled = false;
                    setStatus(picker.dataset.geolocationError || '');
                }, {
                    enableHighAccuracy: false,
                    timeout: 10000,
                    maximumAge: 300000
                });
            });
        }

        form.addEventListener('submit', function (event) {
            input.setCustomValidity('');
            if (input.value.trim() !== '' && idField.value === '') {
                input.setCustomValidity(picker.dataset.selectionRequired || '');
                event.preventDefault();
                input.reportValidity();
            }
        });

        form.addEventListener('reset', function () {
            window.setTimeout(function () {
                input.setCustomValidity('');
                closeSuggestions();
                removeButton.hidden = idField.value === '';
                notifyChanged();
            }, 0);
        });

        document.addEventListener('click', function (event) {
            if (!picker.contains(event.target)) {
                closeSuggestions();
            }
        });
    }

    function init() {
        document.querySelectorAll('[data-location-picker]').forEach(bindPicker);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
