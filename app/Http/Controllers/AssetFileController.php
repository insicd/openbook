<?php

namespace App\Http\Controllers;

use App\Infrastructure\Media\AssetStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AssetFileController extends Controller
{
    public function show(Request $request, AssetStorage $storage, string $kind, string $id, string $slot, string $version): Response
    {
        return $storage->respond($request, $kind, $id, $slot, $version);
    }
}
