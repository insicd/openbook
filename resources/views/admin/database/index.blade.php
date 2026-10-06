@extends('layouts.admin')

@section('title', __('openbook.admin.database.title').' - '.config('app.name'))

@section('content')
    <h1>{{ __('openbook.admin.database.title') }}</h1>
    <div class="ob-card ob-admin-stat" style="margin-top:1rem">
        <strong>{{ $totalSizeLabel }}</strong>
        <span>{{ __('openbook.admin.database.total_size') }}</span>
    </div>

    <nav class="ob-admin-database-tabs" aria-label="{{ __('openbook.admin.database.tabs_aria') }}">
        <a href="{{ route('admin.database.index', ['tab' => 'retention']) }}"
           class="ob-profile-tabs__tab {{ $activeTab === 'retention' ? 'is-active' : '' }}"
           @if ($activeTab === 'retention') aria-current="page" @endif>{{ __('openbook.admin.database.tab_retention') }}</a>
        <a href="{{ route('admin.database.index', ['tab' => 'maintenance']) }}"
           class="ob-profile-tabs__tab {{ $activeTab === 'maintenance' ? 'is-active' : '' }}"
           @if ($activeTab === 'maintenance') aria-current="page" @endif>{{ __('openbook.admin.database.tab_maintenance') }}</a>
    </nav>

    @if ($activeTab === 'retention')
        <section class="ob-card ob-admin-retention" style="margin-top:1.5rem" aria-labelledby="remote-retention-title">
            <h2 id="remote-retention-title">{{ __('openbook.admin.database.retention_title') }}</h2>
            <p class="ob-field__help">{{ __('openbook.admin.database.retention_intro') }}</p>
            <form method="POST" action="{{ route('admin.database.retention.update') }}">
                @csrf
                @method('PUT')
                <div class="ob-field" style="margin-top:1rem">
                    <h3 id="retention-non-pertinent-title">{{ __('openbook.admin.database.retention_non_pertinent') }}</h3>
                    <p id="retention-non-pertinent-help" class="ob-field__help">{{ __('openbook.admin.database.retention_non_pertinent_help') }}</p>
                    <div class="ob-admin-retention__duration">
                        <input id="remote_post_non_pertinent_retention_days" name="remote_post_non_pertinent_retention_days" type="number"
                               min="0" max="2147483647" step="1" required aria-describedby="retention-non-pertinent-help"
                               aria-labelledby="retention-non-pertinent-title retention-non-pertinent-label"
                               value="{{ old('remote_post_non_pertinent_retention_days', $nonPertinentDays) }}">
                        <label id="retention-non-pertinent-label" for="remote_post_non_pertinent_retention_days">{{ __('openbook.admin.database.retention_days') }}</label>
                    </div>
                    @error('remote_post_non_pertinent_retention_days')
                        <p class="ob-field__error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="ob-field" style="margin-top:1rem">
                    <h3 id="retention-pertinent-title">{{ __('openbook.admin.database.retention_pertinent') }}</h3>
                    <p id="retention-pertinent-help" class="ob-field__help">{{ __('openbook.admin.database.retention_pertinent_help') }}</p>
                    <div class="ob-admin-retention__duration">
                        <input id="remote_post_pertinent_retention_days" name="remote_post_pertinent_retention_days" type="number"
                               min="0" max="2147483647" step="1" required aria-describedby="retention-pertinent-help retention-order-note"
                               aria-labelledby="retention-pertinent-title retention-pertinent-label"
                               value="{{ old('remote_post_pertinent_retention_days', $pertinentDays) }}">
                        <label id="retention-pertinent-label" for="remote_post_pertinent_retention_days">{{ __('openbook.admin.database.retention_days') }}</label>
                    </div>
                    <p id="retention-order-note" class="ob-field__help">{{ __('openbook.admin.database.retention_order_note') }}</p>
                    @error('remote_post_pertinent_retention_days')
                        <p class="ob-field__error">{{ $message }}</p>
                    @enderror
                </div>
                <h3>{{ __('openbook.admin.database.retention_preserved') }}</h3>
                <p class="ob-field__help">{{ __('openbook.admin.database.retention_exclusions') }}</p>
                <div class="ob-admin-retention__actions">
                    <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.admin.database.retention_save') }}</button>
                    <a class="ob-btn ob-btn--ghost" href="{{ route('admin.database.index', ['tab' => 'retention', 'preview' => 1]) }}#retention-preview">
                        {{ __('openbook.admin.database.retention_preview') }}
                    </a>
                </div>
            </form>
            @if ($preview !== null)
                <section id="retention-preview" aria-labelledby="retention-preview-title" style="margin-top:1.5rem">
                    <h3 id="retention-preview-title">{{ __('openbook.admin.database.retention_preview') }}</h3>
                    <p class="ob-field__help">{{ __('openbook.admin.database.retention_preview_help') }}</p>
                    <div class="ob-admin-retention__preview">
                        @foreach (['nonPertinent' => ['retention_non_pertinent', $nonPertinentDays], 'pertinent' => ['retention_pertinent', $pertinentDays]] as $category => [$label, $days])
                            <div>
                                <h4>{{ __('openbook.admin.database.'.$label) }}</h4>
                                @if ($days === 0)
                                    <p class="ob-field__help">{{ __('openbook.admin.database.retention_preview_disabled') }}</p>
                                @elseif ($preview[$category]->isEmpty())
                                    <p class="ob-field__help">{{ __('openbook.admin.database.retention_preview_empty') }}</p>
                                @else
                                    <ol>
                                        @foreach ($preview[$category] as $post)
                                            <li><a href="{{ route('posts.show', ['post' => $post->id]) }}" target="_blank" rel="noopener">{{ $post->id }}</a></li>
                                        @endforeach
                                    </ol>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        </section>
    @else
        <h2>{{ __('openbook.admin.database.tab_maintenance') }}</h2>
        <p class="ob-field__help">{{ __('openbook.admin.database.intro') }}</p>
        <div class="ob-admin-stats" style="margin-top:1rem">
            <div class="ob-card ob-admin-stat">
                <strong>{{ $maintenanceSizeLabel }}</strong>
                <span>{{ __('openbook.admin.database.maintenance_size') }}</span>
            </div>
            <div class="ob-card ob-admin-stat">
                <strong>{{ number_format($totalPurgeable) }}</strong>
                <span>{{ __('openbook.admin.database.total_purgeable') }}</span>
            </div>
        </div>

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
        @if ($totalPurgeable > 0)
            <form method="POST" action="{{ route('admin.database.purge') }}" style="margin-top:1.25rem"
                  onsubmit="return confirm(@js(__('openbook.admin.database.confirm_all', ['hours' => $retentionHours])))">
                @csrf
                <button type="submit" class="ob-btn ob-btn--primary">
                    {{ __('openbook.admin.database.purge_all', ['hours' => $retentionHours]) }}
                </button>
            </form>
        @endif

    @endif
@endsection
