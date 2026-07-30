<?php

namespace App\Http\Controllers;

use App\Application\Services\InstanceSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

class InstanceRulesController extends Controller
{
    public function show(InstanceSettings $settings): View
    {
        $markdown = $settings->instanceRules();

        return view('instance.rules', [
            'rulesHtml' => $markdown !== ''
                ? Str::markdown($markdown, [
                    'html_input' => 'strip',
                    'allow_unsafe_links' => false,
                ])
                : null,
        ]);
    }
}
