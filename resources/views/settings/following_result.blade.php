@extends('layouts.app')

@section('title', __('openbook.settings.csv_result_title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('openbook.settings.csv_result_title') }}</h1>
    @include('settings._tabs', ['activeTab' => 'import_export'])
    <section class="ob-settings-section" aria-labelledby="following-result-title">
        <div class="ob-card">
            <h2 id="following-result-title">{{ __('openbook.settings.csv_import_title') }}</h2>
            <p>{{ __('openbook.settings.csv_result_counts', ['followed' => $result['followed'], 'pending' => $result['pending'], 'existing' => $result['existing'], 'duplicates' => $result['duplicates'], 'failed' => count($result['failed'])]) }}</p>
            @if ($result['failed'])
                <h3>{{ __('openbook.settings.csv_failed_title') }}</h3>
                <ul class="ob-csv-addresses">
                    @foreach ($result['failed'] as $address)<li>{{ $address }}</li>@endforeach
                </ul>
            @endif
        </div>
        <div class="ob-settings-actions">
            <a class="ob-btn ob-btn--primary" href="{{ route('settings.edit', ['tab' => 'import_export']) }}">{{ __('openbook.settings.csv_back') }}</a>
        </div>
    </section>
@endsection
