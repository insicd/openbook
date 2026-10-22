<?php

namespace App\Http\Controllers;

use App\Application\Services\StatsDataset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

final class StatsController extends Controller
{
    public function index(): View
    {
        return view('stats.index');
    }

    public function rhythm(StatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->rhythm())->header('Cache-Control', 'private, no-store');
    }

    public function languages(StatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->languages())->header('Cache-Control', 'private, no-store');
    }
}
