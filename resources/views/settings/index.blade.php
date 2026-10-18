@extends('layouts.app')

@section('title', __('openbook.settings.title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('openbook.settings.title') }}</h1>

    @include('settings._tabs')

    @if ($activeTab === 'account')
        @include('settings._account')
    @elseif ($activeTab === 'notifications')
        @include('settings._notifications')
    @elseif ($activeTab === 'migration')
        @include('settings._account_aliases')
        @include('settings._account_migration')
    @else
        @include('settings._profile')
    @endif

    @if ($activeTab === 'profile')
    <script>
        (function () {
            function previewImage(inputId, containerId, imgClass) {
                var input = document.getElementById(inputId);
                var container = document.getElementById(containerId);

                if (!input || !container || !window.FileReader) {
                    return;
                }

                input.addEventListener('change', function () {
                    var file = input.files && input.files[0];

                    if (!file || file.type.indexOf('image/') !== 0) {
                        return;
                    }

                    var reader = new FileReader();
                    reader.onload = function (event) {
                        var img = document.createElement('img');
                        img.src = event.target.result;
                        img.alt = '';
                        if (imgClass) {
                            img.className = imgClass;
                        }
                        container.innerHTML = '';
                        container.appendChild(img);
                    };
                    reader.readAsDataURL(file);
                });
            }

            previewImage('settings-avatar-input', 'settings-avatar-preview', null);
            previewImage('settings-cover-input', 'settings-cover-preview', 'ob-settings-cover-preview');
        })();
    </script>
    <script src="{{ \App\Support\Assets::url('assets/js/profile-fields.js') }}" defer></script>
    @endif
    @if ($activeTab === 'notifications')
    <script src="{{ \App\Support\Assets::url('assets/js/push-notifications.js') }}" defer></script>
    @endif
@endsection
