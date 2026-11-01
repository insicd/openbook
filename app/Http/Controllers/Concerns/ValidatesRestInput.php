<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Exceptions\HttpResponseException;

trait ValidatesRestInput
{
    protected function invalid(array $errors): never
    {
        throw new HttpResponseException(response()->json([
            'type' => 'about:blank', 'title' => 'Unprocessable Content', 'status' => 422,
            'errors' => (object) $errors,
        ], 422, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store']));
    }
}
