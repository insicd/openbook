<?php

namespace App\Http\Controllers\Admin;

use App\Application\Services\InstanceSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

final class AppearanceController extends Controller
{
    public function edit(InstanceSettings $settings): View
    {
        return view('admin.appearance.edit', [
            'customCss' => $settings->customCss(),
            'maxLength' => InstanceSettings::CUSTOM_CSS_MAX_LENGTH,
        ]);
    }

    public function update(Request $request, InstanceSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'custom_css' => ['nullable', 'string', 'max:'.InstanceSettings::CUSTOM_CSS_MAX_LENGTH],
        ], [], [
            'custom_css' => __('openbook.admin.appearance.css_label'),
        ]);

        $settings->updateCustomCss((string) ($data['custom_css'] ?? ''), $request->user());

        return redirect()
            ->route('admin.appearance.edit')
            ->with('status', __('openbook.admin.appearance.saved'));
    }

    /**
     * Pagina campione del sito pubblico, caricata in iframe dall'editor.
     * Non applica il CSS salvato come "definitivo": parte dal testo attuale
     * (gia' in DB) e accetta aggiornamenti live via postMessage, cosi' una
     * bozza non finisce sulla home vera.
     */
    public function preview(InstanceSettings $settings): Response
    {
        return response()
            ->view('admin.appearance.preview', [
                'isCssPreview' => true,
                'previewCss' => $settings->customCss(),
            ])
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('X-Robots-Tag', 'noindex');
    }
}
