<?php

namespace App\Http\Controllers;

use App\Application\Services\ExternalLinkPreviewService;
use App\Domain\Posts\Post;
use Illuminate\Http\JsonResponse;

final class ExternalLinkPreviewController extends Controller
{
    public function __invoke(Post $post, ExternalLinkPreviewService $previews): JsonResponse
    {
        $url = $previews->eligibleUrl($post);

        if ($url === null) {
            abort(404);
        }

        return response()->json($previews->preview($url) ?? ['available' => false]);
    }
}
