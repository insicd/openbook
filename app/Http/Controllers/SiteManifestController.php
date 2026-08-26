<?php

namespace App\Http\Controllers;

use App\Application\Services\InstanceSettings;
use Illuminate\Http\JsonResponse;

/**
 * Web App Manifest usato da Android/Chrome per "Aggiungi alla schermata Home".
 * Il nome segue l'impostazione istanza; le icone arrivano dall'upload admin.
 */
class SiteManifestController extends Controller
{
    public function show(InstanceSettings $settings): JsonResponse
    {
        $name = $settings->siteName();
        $icons = [];

        foreach ([192, 512] as $size) {
            $any = $settings->androidIconUrl($size);
            if ($any !== null) {
                $icons[] = [
                    'src' => $any,
                    'sizes' => $size.'x'.$size,
                    'type' => 'image/png',
                    'purpose' => 'any',
                ];
            }

            $maskable = $settings->maskableIconUrl($size);
            if ($maskable !== null) {
                $icons[] = [
                    'src' => $maskable,
                    'sizes' => $size.'x'.$size,
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ];
            }
        }

        return response()
            ->json([
                'name' => $name,
                'short_name' => mb_substr($name, 0, 12),
                'start_url' => '/',
                'scope' => '/',
                'display' => 'standalone',
                'background_color' => '#ffffff',
                'theme_color' => '#1877f2',
                'lang' => str_replace('_', '-', app()->getLocale()),
                'icons' => $icons,
            ])
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'no-store');
    }
}
