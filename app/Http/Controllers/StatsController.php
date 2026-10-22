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

    public function domains(StatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->domains())->header('Cache-Control', 'private, no-store');
    }

    public function weekly(StatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->weekly())->header('Cache-Control', 'private, no-store');
    }

    public function hashtags(StatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->hashtags())->header('Cache-Control', 'private, no-store');
    }

    public function geography(StatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->geography())->header('Cache-Control', 'private, no-store');
    }

    public function eventCalendar(StatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->eventCalendar())->header('Cache-Control', 'private, no-store');
    }

    public function communityActivity(StatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->communityActivity())->header('Cache-Control', 'private, no-store');
    }

    public function media(StatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->media())->header('Cache-Control', 'private, no-store');
    }

    public function polls(StatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->polls())->header('Cache-Control', 'private, no-store');
    }
}
