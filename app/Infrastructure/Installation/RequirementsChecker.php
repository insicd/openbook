<?php

namespace App\Infrastructure\Installation;

/**
 * Verifica i requisiti minimi dichiarati per Openbook (PHP 8.2+, estensioni,
 * permessi di scrittura) cosi' come richiesto dallo step 1 dell'installer
 * guidato. Non tenta di correggere nulla: si limita a riportare lo stato,
 * lasciando all'amministratore la responsabilita' di intervenire sul server.
 */
final class RequirementsChecker
{
    private const REQUIRED_EXTENSIONS = ['curl', 'openssl', 'json', 'pdo', 'pdo_mysql', 'mbstring', 'fileinfo'];

    /**
     * Estensioni consigliate ma non bloccanti: la loro assenza degrada solo
     * alcune funzioni (qui, il caricamento di immagini nei post) senza
     * impedire l'installazione dell'istanza.
     */
    private const RECOMMENDED_EXTENSIONS = ['gd'];

    private const MIN_PHP_VERSION = '8.2.0';

    /**
     * @return array<int, array{label: string, ok: bool, detail: string, critical: bool}>
     */
    public function check(): array
    {
        $results = [];

        $phpOk = version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=');
        $results[] = [
            'label' => 'Versione di PHP',
            'ok' => $phpOk,
            'detail' => PHP_VERSION.' (richiesta almeno '.self::MIN_PHP_VERSION.')',
            'critical' => true,
        ];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $results[] = [
                'label' => 'Estensione PHP: '.$extension,
                'ok' => extension_loaded($extension),
                'detail' => extension_loaded($extension) ? 'presente' : 'mancante',
                'critical' => true,
            ];
        }

        foreach (self::RECOMMENDED_EXTENSIONS as $extension) {
            $results[] = [
                'label' => 'Estensione PHP: '.$extension.' (consigliata)',
                'ok' => extension_loaded($extension),
                'detail' => extension_loaded($extension)
                    ? 'presente'
                    : 'mancante: sara possibile pubblicare solo post testuali, senza immagini allegate',
                'critical' => false,
            ];
        }

        foreach ($this->writablePaths() as $label => $path) {
            $ok = is_dir($path) && is_writable($path);
            $results[] = [
                'label' => 'Permessi di scrittura: '.$label,
                'ok' => $ok,
                'detail' => $ok ? $path.' e scrivibile' : $path.' non e scrivibile dal server web',
                'critical' => true,
            ];
        }

        return $results;
    }

    public function passesCriticalChecks(): bool
    {
        foreach ($this->check() as $result) {
            if ($result['critical'] && ! $result['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function writablePaths(): array
    {
        return [
            'storage/' => storage_path(),
            'storage/framework/' => storage_path('framework'),
            'storage/framework/cache/' => storage_path('framework/cache'),
            'storage/framework/sessions/' => storage_path('framework/sessions'),
            'storage/framework/views/' => storage_path('framework/views'),
            'storage/logs/' => storage_path('logs'),
            'storage/app/public/' => storage_path('app/public'),
            'bootstrap/cache/' => base_path('bootstrap/cache'),
        ];
    }
}
