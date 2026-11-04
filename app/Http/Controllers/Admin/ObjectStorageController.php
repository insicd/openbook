<?php

namespace App\Http\Controllers\Admin;

use App\Application\Services\ObjectStorageSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ObjectStorageRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class ObjectStorageController extends Controller
{
    public function edit(ObjectStorageSettings $settings): View
    {
        return view('admin.object-storage.edit', ['settings' => $settings->values(), 'hasSecret' => $settings->hasSecret()]);
    }

    public function update(ObjectStorageRequest $request, ObjectStorageSettings $settings): RedirectResponse
    {
        $settings->save($request->validated());

        return redirect()->route('admin.object_storage.edit')->with('status', __('object_storage.saved'));
    }

    public function verify(ObjectStorageSettings $settings): RedirectResponse
    {
        $result = $settings->verify();
        $message = __('object_storage.result_'.$result['stage']);
        if ($result['cleanup_failed']) {
            $message .= ' '.__('object_storage.cleanup_failed');
        }

        $redirect = redirect()->route('admin.object_storage.edit');

        return $result['stage'] === 'ok'
            ? $redirect->with('status', $message)
            : $redirect->withErrors(['connection' => $message]);
    }
}
