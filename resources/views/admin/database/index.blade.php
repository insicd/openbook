@extends('layouts.admin')

@section('title', __('openbook.admin.database.title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('openbook.admin.database.title') }}</h1>
    <p class="ob-field__help">{{ __('openbook.admin.database.intro', ['hours' => $retentionHours]) }}</p>

    <div class="ob-admin-stats" style="margin-top:1rem">
        <div class="ob-card ob-admin-stat">
            <strong>{{ $totalSizeLabel }}</strong>
            <span>{{ __('openbook.admin.database.total_size') }}</span>
        </div>
        <div class="ob-card ob-admin-stat">
            <strong>{{ number_format($totalPurgeable) }}</strong>
            <span>{{ __('openbook.admin.database.total_purgeable', ['hours' => $retentionHours]) }}</span>
        </div>
    </div>

    @if ($totalPurgeable > 0)
        <form method="POST" action="{{ route('admin.database.purge') }}" style="margin-top:1.25rem"
              onsubmit="return confirm(@js(__('openbook.admin.database.confirm_all', ['hours' => $retentionHours])))">
            @csrf
            <button type="submit" class="ob-btn ob-btn--primary">
                {{ __('openbook.admin.database.purge_all', ['hours' => $retentionHours]) }}
            </button>
        </form>
    @endif

    <div class="ob-card" style="margin-top:1.5rem;overflow-x:auto">
        <table style="width:100%;border-collapse:collapse">
            <thead>
                <tr>
                    <th style="text-align:left;padding:0.5rem 0">{{ __('openbook.admin.database.col_table') }}</th>
                    <th style="text-align:right;padding:0.5rem 0">{{ __('openbook.admin.database.col_rows') }}</th>
                    <th style="text-align:right;padding:0.5rem 0">{{ __('openbook.admin.database.col_size') }}</th>
                    <th style="text-align:right;padding:0.5rem 0">{{ __('openbook.admin.database.col_purgeable', ['hours' => $retentionHours]) }}</th>
                    <th style="text-align:right;padding:0.5rem 0"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tables as $table)
                    <tr style="border-top:1px solid var(--ob-border, #e5e7eb)">
                        <td style="padding:0.75rem 0;vertical-align:top">
                            <strong>{{ $table['label'] }}</strong>
                            <p class="ob-field__help" style="margin:0.35rem 0 0">{{ $table['description'] }}</p>
                            <code class="ob-field__help">{{ $table['table'] }}</code>
                        </td>
                        <td style="padding:0.75rem 0;text-align:right;vertical-align:top">{{ number_format($table['row_count']) }}</td>
                        <td style="padding:0.75rem 0;text-align:right;vertical-align:top">{{ $table['size_label'] }}</td>
                        <td style="padding:0.75rem 0;text-align:right;vertical-align:top">{{ number_format($table['purgeable_count']) }}</td>
                        <td style="padding:0.75rem 0;text-align:right;vertical-align:top">
                            @if ($table['purgeable_count'] > 0)
                                <form method="POST" action="{{ route('admin.database.purge') }}"
                                      onsubmit="return confirm(@js(__('openbook.admin.database.confirm_table', ['table' => $table['label'], 'hours' => $retentionHours])))">
                                    @csrf
                                    <input type="hidden" name="table" value="{{ $table['key'] }}">
                                    <button type="submit" class="ob-btn ob-btn--ghost">{{ __('openbook.admin.database.purge') }}</button>
                                </form>
                            @else
                                <span class="ob-field__help">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
