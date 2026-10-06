<h2>{{ __('openbook.admin.database.tab_sanity') }}</h2>
<p class="ob-field__help">{{ __('openbook.admin.database.sanity_intro') }}</p>
<p class="ob-field__help">{{ __('openbook.admin.database.sanity_sample_help', ['limit' => $sanityBatchSize]) }}</p>

@if ($sanityResult !== null && $sanityResult['timed_out'])
    <p role="status">{{ __('openbook.admin.database.sanity_partial') }}</p>
@endif

<div class="ob-card" style="margin-top:1.5rem;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse">
        <thead>
            <tr>
                <th scope="col" style="text-align:left;padding:0.5rem 0">{{ __('openbook.admin.database.col_table') }}</th>
                <th scope="col" style="text-align:left;padding:0.5rem">{{ __('openbook.admin.database.sanity_col_type') }}</th>
                <th scope="col" style="text-align:right;padding:0.5rem 0">{{ __('openbook.admin.database.sanity_col_sample') }}</th>
                @if ($sanityResult !== null)
                    <th scope="col" style="text-align:right;padding:0.5rem">{{ __('openbook.admin.database.sanity_col_deleted') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($sanityPreview as $table => $types)
                @foreach ($types as $type => $count)
                    <tr style="border-top:1px solid var(--ob-color-border)">
                        <td style="padding:0.75rem 0"><code>{{ $table }}</code></td>
                        <td style="padding:0.75rem"><code>{{ $type }}</code></td>
                        <td style="padding:0.75rem 0;text-align:right">{{ number_format($count) }}</td>
                        @if ($sanityResult !== null)
                            <td style="padding:0.75rem;text-align:right">{{ number_format($sanityResult['deleted'][$table][$type] ?? 0) }}</td>
                        @endif
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</div>

@if (array_sum(array_map('count', $sanityUnknown)) > 0)
    <div class="ob-card" style="margin-top:1rem">
        <h3>{{ __('openbook.admin.database.sanity_unknown_title') }}</h3>
        <p class="ob-field__help">{{ __('openbook.admin.database.sanity_unknown_help') }}</p>
        <ul>
            @foreach ($sanityUnknown as $table => $types)
                @foreach ($types as $type => $count)
                    <li><code>{{ $table }} / {{ $type }}</code>: {{ number_format($count) }}</li>
                @endforeach
                @if (count($types) === 100)
                    <li>{{ __('openbook.admin.database.sanity_unknown_limit', ['table' => $table]) }}</li>
                @endif
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('admin.database.sanity.run') }}" class="ob-admin-retention__actions" style="margin-top:1.25rem"
      onsubmit="return confirm(@js(__('openbook.admin.database.sanity_confirm')))">
    @csrf
    <button type="submit" class="ob-btn ob-btn--primary">{{ __('openbook.admin.database.sanity_run') }}</button>
    <a class="ob-btn ob-btn--ghost" href="{{ route('admin.database.index', ['tab' => 'sanity']) }}">{{ __('openbook.admin.database.sanity_refresh') }}</a>
</form>
