<?php

/**
 * Openbook — bootstrap di installazione per shared hosting.
 *
 * 1. Scarica questo file da https://about.openb.app/setup-openbook.php
 * 2. Caricalo nella cartella in cui vuoi installare Openbook
 * 3. Aprilo nel browser e segui il wizard
 *
 * Lo script scarica l'ultima release firmata (zip + SHA-256) da about.openb.app,
 * prepara .env / .htaccess e reindirizza all'installer Laravel (/install).
 *
 * Dopo il successo si autodistrugge.
 */

declare(strict_types=1);

const OB_SETUP_MANIFEST_URL = 'https://about.openb.app/releases/latest.json';
const OB_SETUP_MIN_PHP = '8.2.0';
const OB_SETUP_TIMEOUT = 180;

@set_time_limit(0);
@ini_set('memory_limit', '512M');

$baseDir = __DIR__;
$step = $_GET['step'] ?? 'welcome';
$errors = [];
$messages = [];

if (is_file($baseDir.'/storage/installed.lock')) {
    http_response_code(403);
    exit('Openbook risulta gia installato. Rimuovi setup-openbook.php.');
}

if (is_file($baseDir.'/artisan') && is_file($baseDir.'/public/index.php') && is_file($baseDir.'/.env')) {
    // Codice gia presente: salta al wizard di conferma redirect.
    if ($step === 'welcome') {
        $step = 'already';
    }
}

function ob_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ob_fetch(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => OB_SETUP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_USERAGENT => 'OpenbookSetup/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new RuntimeException('Download fallito'.($err !== '' ? ": {$err}" : " (HTTP {$status})"));
        }

        return $body;
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => OB_SETUP_TIMEOUT,
            'header' => "User-Agent: OpenbookSetup/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('Download fallito (stream). Abilita curl o allow_url_fopen.');
    }

    return $body;
}

function ob_download_file(string $url, string $destination): void
{
    $body = ob_fetch($url);
    if (file_put_contents($destination, $body) === false) {
        throw new RuntimeException('Impossibile scrivere il file scaricato.');
    }
}

/**
 * @return array<string, mixed>
 */
function ob_fetch_manifest(): array
{
    $json = ob_fetch(OB_SETUP_MANIFEST_URL);
    $data = json_decode($json, true);
    if (! is_array($data)) {
        throw new RuntimeException('Manifesto release non valido.');
    }
    foreach (['version', 'min_php', 'download_url', 'sha256'] as $key) {
        if (empty($data[$key])) {
            throw new RuntimeException("Manifesto incompleto (manca {$key}).");
        }
    }
    if (! str_starts_with((string) $data['download_url'], 'https://')) {
        throw new RuntimeException('URL download non HTTPS.');
    }
    if (! preg_match('/^[a-fA-F0-9]{64}$/', (string) $data['sha256'])) {
        throw new RuntimeException('SHA-256 manifesto non valido.');
    }

    return $data;
}

function ob_rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $items = scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir.DIRECTORY_SEPARATOR.$item;
        if (is_dir($path)) {
            ob_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * @return list<string>
 */
function ob_requirement_errors(): array
{
    $errors = [];
    if (version_compare(PHP_VERSION, OB_SETUP_MIN_PHP, '<')) {
        $errors[] = 'Serve PHP '.OB_SETUP_MIN_PHP.' o superiore (attuale: '.PHP_VERSION.').';
    }
    foreach (['curl', 'openssl', 'json', 'mbstring', 'pdo', 'pdo_mysql', 'fileinfo', 'zip'] as $ext) {
        if ($ext === 'curl' && ! function_exists('curl_init') && ! ini_get('allow_url_fopen')) {
            $errors[] = 'Serve estensione curl oppure allow_url_fopen.';

            continue;
        }
        if ($ext === 'curl') {
            continue;
        }
        if ($ext === 'zip' && ! class_exists('ZipArchive')) {
            $errors[] = 'Estensione zip (ZipArchive) mancante.';

            continue;
        }
        if ($ext !== 'zip' && ! extension_loaded($ext)) {
            $errors[] = "Estensione PHP mancante: {$ext}.";
        }
    }
    if (! is_writable(__DIR__)) {
        $errors[] = 'La cartella corrente non e scrivibile dal server web.';
    }

    return $errors;
}

function ob_write_env(string $baseDir): void
{
    $example = $baseDir.'/.env.example';
    $env = $baseDir.'/.env';
    if (is_file($env)) {
        return;
    }
    if (! is_file($example)) {
        throw new RuntimeException('Manca .env.example nell archivio.');
    }
    if (! @copy($example, $env)) {
        throw new RuntimeException('Impossibile creare .env');
    }
}

function ob_ensure_writable_dirs(string $baseDir): void
{
    $dirs = [
        'storage',
        'storage/app',
        'storage/app/public',
        'storage/framework',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
        'bootstrap/cache',
    ];
    foreach ($dirs as $dir) {
        $path = $baseDir.'/'.$dir;
        if (! is_dir($path) && ! @mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException("Impossibile creare {$dir}");
        }
        @chmod($path, 0755);
    }
}

function ob_copy_root_htaccess(string $baseDir): void
{
    $src = $baseDir.'/distribution/htaccess.root';
    $dest = $baseDir.'/.htaccess';
    if (is_file($dest)) {
        return;
    }
    if (is_file($src)) {
        @copy($src, $dest);
    }
}

function ob_self_delete(): void
{
    $file = __FILE__;
    @unlink($file);
}

function ob_install_release(string $baseDir, array $manifest): void
{
    $tmp = $baseDir.'/.ob-setup-tmp';
    ob_rrmdir($tmp);
    if (! @mkdir($tmp, 0755, true) && ! is_dir($tmp)) {
        throw new RuntimeException('Impossibile creare cartella temporanea.');
    }

    $zipPath = $tmp.'/release.zip';
    $extract = $tmp.'/extract';
    @mkdir($extract, 0755, true);

    ob_download_file((string) $manifest['download_url'], $zipPath);

    $actual = hash_file('sha256', $zipPath);
    if (! is_string($actual) || ! hash_equals(strtolower((string) $manifest['sha256']), strtolower($actual))) {
        throw new RuntimeException('Checksum SHA-256 non corrispondente. Installazione annullata.');
    }

    $zip = new ZipArchive;
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('ZIP non apribile.');
    }
    if (! $zip->extractTo($extract)) {
        $zip->close();
        throw new RuntimeException('Estrazione ZIP fallita.');
    }
    $zip->close();

    $payload = $extract;
    if (! is_file($payload.'/artisan')) {
        $entries = array_values(array_filter(scandir($extract) ?: [], fn ($e) => $e !== '.' && $e !== '..'));
        if (count($entries) === 1 && is_dir($extract.'/'.$entries[0]) && is_file($extract.'/'.$entries[0].'/artisan')) {
            $payload = $extract.'/'.$entries[0];
        }
    }
    if (! is_file($payload.'/artisan') || ! is_file($payload.'/public/index.php')) {
        throw new RuntimeException('Archivio release non riconosciuto.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($payload, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $rel = substr($item->getPathname(), strlen($payload) + 1);
        $rel = str_replace('\\', '/', $rel);
        if ($rel === 'setup-openbook.php') {
            continue;
        }
        $target = $baseDir.'/'.$rel;
        if ($item->isDir()) {
            if (! is_dir($target)) {
                @mkdir($target, 0755, true);
            }

            continue;
        }
        $parent = dirname($target);
        if (! is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }
        if (! @copy($item->getPathname(), $target)) {
            throw new RuntimeException("Copia fallita: {$rel}");
        }
    }

    ob_write_env($baseDir);
    ob_ensure_writable_dirs($baseDir);
    if (! empty($_POST['root_htaccess'])) {
        ob_copy_root_htaccess($baseDir);
    }

    ob_rrmdir($tmp);
}

// --- Azioni POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'install') {
            $reqErrors = ob_requirement_errors();
            if ($reqErrors !== []) {
                throw new RuntimeException(implode(' ', $reqErrors));
            }
            $manifest = ob_fetch_manifest();
            if (version_compare(PHP_VERSION, (string) $manifest['min_php'], '<')) {
                throw new RuntimeException('Questa release richiede PHP '.$manifest['min_php']);
            }
            ob_install_release($baseDir, $manifest);
            ob_self_delete();
            $installUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
            // Se il progetto e in public_html con rewrite, /install funziona via root htaccess.
            $target = ($installUrl === '' || $installUrl === '/') ? '/install' : $installUrl.'/install';
            // Preferisci public/install se accessibile direttamente.
            if (is_dir($baseDir.'/public') && empty($_POST['root_htaccess'])) {
                $target = ($installUrl === '' || $installUrl === '/') ? '/public/install' : $installUrl.'/public/install';
            }
            header('Location: '.$target);
            exit;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        $step = 'confirm';
    }
}

$reqErrors = ob_requirement_errors();
$manifest = null;
$manifestError = null;
try {
    $manifest = ob_fetch_manifest();
} catch (Throwable $e) {
    $manifestError = $e->getMessage();
}

?><!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup Openbook</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; }
        body { margin: 0; background: #f0f2f5; color: #1c1e21; }
        main { max-width: 640px; margin: 2rem auto; padding: 0 1rem; }
        .card { background: #fff; border-radius: 10px; padding: 1.25rem 1.5rem; box-shadow: 0 1px 8px rgba(0,0,0,.06); }
        h1 { margin-top: 0; font-size: 1.4rem; }
        .muted { color: #5b6270; font-size: .95rem; }
        .err { background: #fdecea; color: #b3261e; padding: .75rem 1rem; border-radius: 8px; margin: 1rem 0; }
        .ok { background: #e7f6ec; color: #1e6b3e; padding: .75rem 1rem; border-radius: 8px; margin: 1rem 0; }
        label { display: flex; gap: .5rem; align-items: flex-start; margin: 1rem 0; }
        button, .btn { display: inline-block; background: #1877f2; color: #fff; border: 0; border-radius: 8px; padding: .7rem 1rem; font-weight: 600; cursor: pointer; text-decoration: none; }
        button[disabled] { opacity: .5; cursor: not-allowed; }
        code { background: #f0f2f5; padding: .1rem .35rem; border-radius: 4px; }
        ul { padding-left: 1.2rem; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <h1>Setup Openbook</h1>
        <p class="muted">Installazione guidata per shared hosting. Fonte release: <code>about.openb.app</code></p>

        <?php foreach ($errors as $error) { ?>
            <div class="err"><?= ob_h($error) ?></div>
        <?php } ?>

        <?php if ($step === 'already') { ?>
            <div class="ok">I file di Openbook sono gia presenti in questa cartella.</div>
            <p><a class="btn" href="public/install">Vai all installer</a></p>
            <p class="muted">Quando hai finito, elimina <code>setup-openbook.php</code>.</p>

        <?php } elseif ($step === 'welcome') { ?>
            <p>Questo wizard scarichera l ultima release ufficiale (con <code>vendor/</code> incluso), preparera <code>.env</code> e ti portera all installer web.</p>
            <?php if ($reqErrors !== []) { ?>
                <div class="err">
                    <strong>Requisiti mancanti</strong>
                    <ul><?php foreach ($reqErrors as $e) { ?><li><?= ob_h($e) ?></li><?php } ?></ul>
                </div>
            <?php } ?>
            <?php if ($manifestError) { ?>
                <div class="err">Manifesto non raggiungibile: <?= ob_h($manifestError) ?></div>
                <p class="muted">Pubblica <code>releases/latest.json</code> su about.openb.app oppure riprova piu tardi.</p>
            <?php } elseif ($manifest) { ?>
                <p><strong>Release:</strong> <?= ob_h((string) $manifest['version']) ?></p>
                <?php if (! empty($manifest['notes'])) { ?>
                    <p class="muted"><?= ob_h((string) $manifest['notes']) ?></p>
                <?php } ?>
                <p><a class="btn" href="?step=confirm">Continua</a></p>
            <?php } ?>

        <?php } else { /* confirm */ ?>
            <?php if ($reqErrors !== []) { ?>
                <div class="err"><ul><?php foreach ($reqErrors as $e) { ?><li><?= ob_h($e) ?></li><?php } ?></ul></div>
            <?php } elseif (! $manifest) { ?>
                <div class="err"><?= ob_h($manifestError ?: 'Manifesto non disponibile.') ?></div>
            <?php } else { ?>
                <p>Stai per installare Openbook <strong><?= ob_h((string) $manifest['version']) ?></strong> in:</p>
                <p><code><?= ob_h($baseDir) ?></code></p>
                <form method="post">
                    <input type="hidden" name="action" value="install">
                    <label>
                        <input type="checkbox" name="root_htaccess" value="1">
                        <span>Il progetto sta tutto in <code>public_html</code> (document root non punta a <code>public/</code>): crea anche il <code>.htaccess</code> di root.</span>
                    </label>
                    <label>
                        <input type="checkbox" name="confirm" value="1" required>
                        <span>Confermo di voler scaricare e scompattare i file in questa cartella.</span>
                    </label>
                    <button type="submit">Scarica e installa</button>
                </form>
                <p class="muted">Al termine verrai portato a <code>/install</code> per database e account admin. Questo file si eliminera da solo.</p>
            <?php } ?>
        <?php } ?>
    </div>
</main>
</body>
</html>
