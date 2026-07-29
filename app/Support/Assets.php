<?php

namespace App\Support;

/**
 * Aggiunge alle URL dei file statici in "public/assets" una query string di
 * versione basata sulla data di ultima modifica del file.
 *
 * Questi asset (CSS/JS) sono serviti direttamente da "public/" senza alcuna
 * pipeline di build (niente Vite/webpack, per restare compatibili con
 * l'hosting condiviso), quindi hanno sempre lo stesso percorso: senza una
 * query string che cambi ad ogni modifica, i browser possono continuare a
 * servire dalla cache una versione vecchia del file anche dopo un
 * aggiornamento del software, con il rischio di comportamenti incoerenti
 * (es. markup nuovo abbinato a CSS/JS vecchi).
 */
final class Assets
{
    public static function url(string $relativePath): string
    {
        $absolutePath = public_path($relativePath);
        $version = is_file($absolutePath) ? filemtime($absolutePath) : false;

        $url = asset($relativePath);

        return $version !== false ? $url.'?v='.$version : $url;
    }
}
