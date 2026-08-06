<?php

namespace App\Http\Controllers\Admin;

use App\Application\Services\DatabaseMaintenanceService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DatabaseMaintenanceController extends Controller
{
    public function index(DatabaseMaintenanceService $maintenance): View
    {
        $tables = $maintenance->snapshots();
        $totalSizeBytes = array_sum(array_column($tables, 'size_bytes'));
        $totalPurgeable = array_sum(array_column($tables, 'purgeable_count'));

        return view('admin.database.index', [
            'tables' => $tables,
            'retentionHours' => DatabaseMaintenanceService::RETENTION_HOURS,
            'totalSizeLabel' => $this->formatBytes($totalSizeBytes),
            'totalPurgeable' => $totalPurgeable,
        ]);
    }

    public function purge(Request $request, DatabaseMaintenanceService $maintenance): RedirectResponse
    {
        $validKeys = array_column($maintenance->snapshots(), 'key');

        $data = $request->validate([
            'table' => ['nullable', 'string', Rule::in($validKeys)],
        ]);

        if (isset($data['table'])) {
            $deleted = $maintenance->purgeKey($data['table'], $request->user());

            return back()->with('status', __('openbook.admin.database.purged_table', [
                'table' => __(
                    'openbook.admin.database.tables.'.$data['table'],
                    [],
                    $data['table'],
                ),
                'count' => $deleted,
            ]));
        }

        $deletedByTable = $maintenance->purgeAll($request->user());
        $total = array_sum($deletedByTable);

        return back()->with('status', __('openbook.admin.database.purged_all', [
            'count' => $total,
        ]));
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / 1024 / 1024, 1).' MB';
        }

        return round($bytes / 1024 / 1024 / 1024, 2).' GB';
    }
}
