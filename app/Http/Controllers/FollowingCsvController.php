<?php

namespace App\Http\Controllers;

use App\Application\Services\FollowingCsvService;
use App\Http\Requests\Settings\FollowingCsvRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FollowingCsvController extends Controller
{
    public function export(Request $request, FollowingCsvService $csv): StreamedResponse
    {
        $csv->exportQuery($request->user());

        return response()->streamDownload(function () use ($request, $csv): void {
            $stream = fopen('php://output', 'wb');
            $csv->writeExport($request->user(), $stream);
            fclose($stream);
        }, 'following_accounts.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function import(FollowingCsvRequest $request, FollowingCsvService $csv): Response
    {
        return response()->view('settings.following_result', ['result' => $csv->import($request->user(), $request->file('csv'))])
            ->header('Cache-Control', 'private, no-store');
    }
}
