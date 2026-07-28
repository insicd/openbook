<?php

namespace App\Http\Middleware;

use App\Infrastructure\Installation\InstallationLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finche' l'installer non e' stato completato, reindirizza qualunque
 * richiesta (tranne quelle dirette all'installer stesso e all'health check)
 * verso la procedura guidata.
 */
class EnsureApplicationIsInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (InstallationLock::isInstalled()) {
            return $next($request);
        }

        if ($request->is('install*') || $request->is('up')) {
            return $next($request);
        }

        return redirect()->to('/install');
    }
}
