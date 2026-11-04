<?php

namespace App\Http\Controllers\Admin;

use App\Application\Services\InstanceSettings;
use App\Domain\Moderation\DomainBlock;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class ModerationSettingsController extends Controller
{
    public function edit(Request $request, InstanceSettings $settings): View
    {
        $activeTab = $request->query('tab') === 'domains' ? 'domains' : 'content';

        return view('admin.moderation.edit', [
            'activeTab' => $activeTab,
            'tabs' => [
                ['label' => __('openbook.admin.settings.tab_content'), 'url' => route('admin.moderation.edit'), 'active' => $activeTab === 'content'],
                ['label' => __('openbook.admin.nav.domain_blocks'), 'url' => route('admin.moderation.edit', ['tab' => 'domains']), 'active' => $activeTab === 'domains'],
            ],
            'blocks' => $activeTab === 'domains' ? DomainBlock::query()->with('creator')->orderBy('domain')->paginate(40)->withQueryString() : null,
            'worldHideContentWarnings' => $settings->worldHidesContentWarnings(),
            'forcedContentWarningHashtags' => implode("\n", $settings->forcedContentWarningHashtags()),
        ]);
    }

    public function update(Request $request, InstanceSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'world_hide_content_warnings' => ['sometimes', 'boolean'],
            'forced_content_warning_hashtags' => ['nullable', 'string', 'max:4000'],
        ]);
        $data['world_hide_content_warnings'] = $request->boolean('world_hide_content_warnings');
        $data['forced_content_warning_hashtags'] = $data['forced_content_warning_hashtags'] ?? '';
        DB::transaction(fn () => $settings->update($data, $request->user()));

        return redirect()->route('admin.moderation.edit')->with('status', __('openbook.admin.settings.saved'));
    }
}
