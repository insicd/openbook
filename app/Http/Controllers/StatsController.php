<?php

namespace App\Http\Controllers;

use App\Application\Services\CachedStatsDataset;
use App\Application\Services\InstanceSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

final class StatsController extends Controller
{
    public function index(InstanceSettings $settings): View
    {
        return view('stats.index', ['retention' => [
            'pertinent' => $settings->remotePostPertinentRetentionDays(),
            'non_pertinent' => $settings->remotePostNonPertinentRetentionDays(),
        ]]);
    }

    public function rhythm(CachedStatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->get('rhythm'))->header('Cache-Control', 'private, no-store');
    }

    public function languages(CachedStatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->get('languages'))->header('Cache-Control', 'private, no-store');
    }

    public function domains(CachedStatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->get('domains'))->header('Cache-Control', 'private, no-store');
    }

    public function weekly(CachedStatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->get('weekly'))->header('Cache-Control', 'private, no-store');
    }

    public function hashtags(CachedStatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->get('hashtags'))->header('Cache-Control', 'private, no-store');
    }

    public function geography(CachedStatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->get('geography'))->header('Cache-Control', 'private, no-store');
    }

    public function eventCalendar(CachedStatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->get('eventCalendar'))->header('Cache-Control', 'private, no-store');
    }

    public function communityActivity(CachedStatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->get('communityActivity'))->header('Cache-Control', 'private, no-store');
    }

    public function media(CachedStatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->get('media'))->header('Cache-Control', 'private, no-store');
    }

    public function polls(CachedStatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->get('polls'))->header('Cache-Control', 'private, no-store');
    }

    public function acquisition(CachedStatsDataset $datasets): JsonResponse
    {
        return response()->json($datasets->get('acquisition'))->header('Cache-Control', 'private, no-store');
    }
}
