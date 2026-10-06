<?php

namespace App\Http\Controllers\Admin;

use App\Application\Queries\RemotePostRetentionQuery;
use App\Application\Services\DatabaseMaintenanceService;
use App\Application\Services\InstanceSettings;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DatabaseMaintenanceController extends Controller
{
    public function index(Request $request, DatabaseMaintenanceService $maintenance, InstanceSettings $settings, RemotePostRetentionQuery $retention): View
    {
        $activeTab = $request->query('tab') === 'maintenance' ? 'maintenance' : 'retention';
        $tables = $activeTab === 'maintenance' ? $maintenance->snapshots() : [];
        $totalSizeBytes = $maintenance->databaseSizeBytes();
        $totalPurgeable = array_sum(array_column($tables, 'purgeable_count'));
        $preview = null;
        if ($activeTab === 'retention' && $request->boolean('preview')) {
            $asOf = now();
            $preview = [
                'nonPertinent' => $retention->nonPertinent(10, $asOf)->get(),
                'pertinent' => $retention->pertinent(10, $asOf)->get(),
            ];
        }

        return view('admin.database.index', [
            'tables' => $tables,
            'activeTab' => $activeTab,
            'preview' => $preview,
            'retentionHours' => DatabaseMaintenanceService::RETENTION_HOURS,
            'totalSizeLabel' => $totalSizeBytes === null ? __('openbook.admin.database.size_unavailable') : $this->formatBytes($totalSizeBytes),
            'maintenanceSizeLabel' => $this->formatBytes(array_sum(array_column($tables, 'size_bytes'))),
            'totalPurgeable' => $totalPurgeable,
            'nonPertinentDays' => $settings->remotePostNonPertinentRetentionDays(),
            'pertinentDays' => $settings->remotePostPertinentRetentionDays(),
        ]);
    }

    public function updateRetention(Request $request, InstanceSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'remote_post_non_pertinent_retention_days' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'remote_post_pertinent_retention_days' => [
                'required', 'integer', 'min:0', 'max:2147483647',
                Rule::when($request->integer('remote_post_non_pertinent_retention_days') > 0
                    && $request->integer('remote_post_pertinent_retention_days') > 0,
                    'gte:remote_post_non_pertinent_retention_days'),
            ],
        ], [
            'remote_post_pertinent_retention_days.gte' => __('openbook.admin.database.retention_order_error'),
        ], [
            'remote_post_non_pertinent_retention_days' => __('openbook.admin.database.retention_non_pertinent'),
            'remote_post_pertinent_retention_days' => __('openbook.admin.database.retention_pertinent'),
        ]);

        $settings->updateRemotePostRetention(
            (int) $data['remote_post_non_pertinent_retention_days'],
            (int) $data['remote_post_pertinent_retention_days'],
            $request->user(),
        );

        return redirect()->route('admin.database.index')
            ->with('status', __('openbook.admin.database.retention_saved'));
    }

    public function purge(Request $request, DatabaseMaintenanceService $maintenance): RedirectResponse
    {
        $validKeys = array_column($maintenance->snapshots(), 'key');

        $data = $request->validate([
            'table' => ['nullable', 'string', Rule::in($validKeys)],
        ]);

        if (isset($data['table'])) {
            $deleted = $maintenance->purgeKey($data['table'], $request->user());

            return redirect()->route('admin.database.index', ['tab' => 'maintenance'])->with('status', __('openbook.admin.database.purged_table', [
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

        return redirect()->route('admin.database.index', ['tab' => 'maintenance'])->with('status', __('openbook.admin.database.purged_all', [
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
