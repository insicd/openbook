<?php

namespace App\Http\Middleware;

use App\Infrastructure\Installation\InstallationLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Impedisce di rieseguire l'installer una volta che l'istanza e' gia'
 * configurata, come richiesto dalla specifica ("bloccare nuove esecuzioni
 * dell'installer").
 */
class RedirectIfInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (InstallationLock::isInstalled()) {
            return redirect()->to('/');
        }

        return $next($request);
    }
}
