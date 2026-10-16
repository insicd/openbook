@inject('userDates', 'App\Support\UserDate')
<script src="{{ \App\Support\Assets::url('assets/js/timezone.js') }}"
        data-automatic="{{ $userDates->isAutomatic() ? '1' : '0' }}"
        data-safe-request="{{ request()->isMethod('GET') ? '1' : '0' }}"
        data-timezone="{{ $userDates->timezone() }}"></script>
