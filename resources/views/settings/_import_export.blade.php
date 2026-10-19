<section class="ob-settings-section" aria-labelledby="following-export-title">
    <div class="ob-card">
        <h2 id="following-export-title">{{ __('openbook.settings.csv_export_title') }}</h2>
        <p>{{ __('openbook.settings.csv_export_help') }}</p>
    </div>
    <div class="ob-settings-actions">
        <a class="ob-btn ob-btn--primary" href="{{ route('settings.following.export') }}">{{ __('openbook.settings.csv_export_button') }}</a>
    </div>
</section>
<section class="ob-settings-section" aria-labelledby="following-import-title">
    <div class="ob-card">
        <h2 id="following-import-title">{{ __('openbook.settings.csv_import_title') }}</h2>
        <p>{{ __('openbook.settings.csv_import_help') }}</p>
        <form id="following-import-form" method="POST" action="{{ route('settings.following.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="ob-field">
                <label for="following-csv">{{ __('openbook.settings.csv_file_label') }}</label>
                <input id="following-csv" type="file" name="csv" accept=".csv,text/csv,text/plain" required aria-describedby="following-csv-help">
                <p id="following-csv-help" class="ob-field__help">{{ __('openbook.settings.csv_file_help') }}</p>
                @error('csv')<p class="ob-field__error">{{ $message }}</p>@enderror
            </div>
        </form>
    </div>
    <div class="ob-settings-actions">
        <button type="submit" form="following-import-form" class="ob-btn ob-btn--primary">{{ __('openbook.settings.csv_import_button') }}</button>
    </div>
</section>
