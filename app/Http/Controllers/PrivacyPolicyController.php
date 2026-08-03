<?php

namespace App\Http\Controllers;

use App\Application\Services\InstanceSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

class PrivacyPolicyController extends Controller
{
    public function show(InstanceSettings $settings): View
    {
        $markdown = $settings->privacyPolicy();

        return view('instance.privacy', [
            'privacyHtml' => $markdown !== ''
                ? Str::markdown($markdown, [
                    'html_input' => 'strip',
                    'allow_unsafe_links' => false,
                ])
                : null,
        ]);
    }
}
