<?php

namespace App\Http\Controllers\Admin;

use App\Application\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Infrastructure\Distribution\ReleaseManifestClient;
use App\Infrastructure\Distribution\ReleaseUpdater;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

final class UpdateController extends Controller
{
    public function __construct(
        private readonly ReleaseManifestClient $manifestClient,
        private readonly ReleaseUpdater $updater,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function show(): View
    {
        $current = (string) config('openbook.version');
        $manifest = null;
        $error = null;
        $updateAvailable = false;

        try {
            $manifest = $this->manifestClient->fetch();
            $updateAvailable = $this->manifestClient->isNewerThan($manifest['version'], $current);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return view('admin.updates.show', [
            'currentVersion' => $current,
            'manifest' => $manifest,
            'updateAvailable' => $updateAvailable,
            'fetchError' => $error,
            'manifestUrl' => (string) config('openbook.distribution.manifest_url'),
            'zipAvailable' => class_exists(\ZipArchive::class),
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $request->validate([
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => __('openbook.admin.updates.confirm_required'),
        ]);

        try {
            $manifest = $this->manifestClient->fetch();

            if (! $this->manifestClient->isNewerThan($manifest['version'])) {
                return redirect()
                    ->route('admin.updates.show')
                    ->with('status', __('openbook.admin.updates.already_latest'));
            }

            $result = $this->updater->apply($manifest, base_path());

            $this->auditLogger->log($request->user(), 'updates.apply', null, [
                'version' => $result['version'],
                'migrated' => $result['migrated'],
            ]);

            return redirect()
                ->route('admin.updates.show')
                ->with('status', __('openbook.admin.updates.applied', ['version' => $result['version']]));
        } catch (RuntimeException $e) {
            return redirect()
                ->route('admin.updates.show')
                ->withErrors(['update' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.updates.show')
                ->withErrors(['update' => __('openbook.admin.updates.failed')]);
        }
    }
}
