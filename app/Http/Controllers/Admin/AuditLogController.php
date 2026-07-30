<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Moderation\AuditLog;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class AuditLogController extends Controller
{
    public function index(): View
    {
        return view('admin.audit.index', [
            'logs' => AuditLog::query()
                ->with('actor')
                ->orderByDesc('created_at')
                ->paginate(40),
        ]);
    }
}
