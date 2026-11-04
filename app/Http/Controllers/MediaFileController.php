<?php

namespace App\Http\Controllers;

use App\Infrastructure\Media\Media;
use App\Infrastructure\Media\MediaFileResponder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MediaFileController extends Controller
{
    public function show(Request $request, Media $media, MediaFileResponder $responder, ?string $variant = null): Response
    {
        return $responder->respond($request, $media, $variant);
    }
}
