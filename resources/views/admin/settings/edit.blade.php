@extends('layouts.admin')
@section('title', __('openbook.admin.settings.title').' - '.config('app.name'))
@section('content')
    <h1>{{ __('openbook.admin.settings.title') }}</h1>
    <p class="ob-field__help">{{ __('openbook.admin.settings.intro') }}</p>
    <x-admin-tabs :label="__('openbook.admin.settings.title')" :tabs="$tabs" />
    <form method="POST" action="{{ route('admin.settings.update') }}" class="ob-card" style="margin-top:1rem" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        <input type="hidden" name="tab" value="{{ $activeTab }}">
        @include('admin.settings.'.$activeTab)
        <button type="submit" class="ob-btn ob-btn--primary" style="margin-top:1.25rem">{{ __('openbook.admin.settings.save') }}</button>
    </form>
    <script>
        (function () {
            var input = document.getElementById('favicon');
            var preview = document.getElementById('admin-favicon-preview');

            if (!input || !preview || !window.FileReader) {
                return;
            }

            input.addEventListener('change', function () {
                var file = input.files && input.files[0];

                if (!file || file.type.indexOf('image/') !== 0) {
                    return;
                }

                var reader = new FileReader();
                reader.onload = function (event) {
                    preview.src = event.target.result;
                };
                reader.readAsDataURL(file);
            });
        })();
    </script>
@endsection
