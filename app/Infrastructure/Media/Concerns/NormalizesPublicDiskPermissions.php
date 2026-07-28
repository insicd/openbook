<?php

namespace App\Infrastructure\Media\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Corregge esplicitamente i permessi di cio' che viene scritto sul disco
 * "public": mkdir() applica sempre la umask del processo PHP al modo
 * richiesto, quindi su hosting con una umask restrittiva (es. 0077, non
 * insolita su alcuni pool PHP-FPM condivisi) una cartella nuova puo'
 * finire "0700" anche se il codice chiede esplicitamente "0755", diventando
 * illeggibile per l'utente con cui il web server serve i file statici
 * quando e' diverso da quello con cui gira PHP (comune su hosting con
 * suPHP/LSAPI). chmod(), a differenza di mkdir(), applica sempre il valore
 * esatto richiesto indipendentemente dalla umask: e' l'unico modo per
 * garantire che le cartelle create al primo upload restino attraversabili
 * a prescindere dalla configurazione del server.
 */
trait NormalizesPublicDiskPermissions
{
    /**
     * Da chiamare con il percorso della cartella (senza il nome del file)
     * subito dopo aver scritto un file sul disco "public": corregge ogni
     * livello della cartella appena attraversato/creato, non l'intero
     * albero, per restare economico anche quando la cartella contiene gia'
     * molti altri file (es. "media/2026/07").
     */
    private function ensurePublicDirectoryIsTraversable(string $directory): void
    {
        $disk = Storage::disk('public');
        $path = '';

        foreach (array_filter(explode('/', trim($directory, '/'))) as $segment) {
            $path = $path === '' ? $segment : $path.'/'.$segment;
            @chmod($disk->path($path), 0755);
        }
    }

    private function ensurePublicFileIsReadable(string $path): void
    {
        @chmod(Storage::disk('public')->path($path), 0644);
    }
}
