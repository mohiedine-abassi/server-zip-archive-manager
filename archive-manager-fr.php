<?php
declare(strict_types=1);

/**
 * Archive Manager - Vos fichiers, sur votre serveur
 *
 * @package    ServerArchiveManager
 * @version    <v1 class="0 0"></v1>
 * @author     Mohieddine ABASSI <mohiedineabassi@gmail.com>
 * @link       https://github.com/mohiedine-abassi
 * @copyright  2026 Mohieddine ABASSI
 * @license    MIT
 */

// ==================== CONFIGURATION ====================
const AM_PASSWORD_HASH = '';                 // Coller ici l'empreinte proposée.
const AM_ROOT = __DIR__;                     // Racine autorisée, jamais fournie par le navigateur.
const AM_PRIVATE_DIR = '';                   // Vide : sous-dossier privé de sys_get_temp_dir().
const AM_REQUIRE_HTTPS = true;
const AM_TRUSTED_PROXIES = [];               // IP exactes, seulement si proxy maîtrisé.
const AM_ALLOWED_IPS = [];                   // Facultatif : IP exactes de REMOTE_ADDR.
const AM_IDLE_SECONDS = 1800;
const AM_SESSION_SECONDS = 28800;
const AM_MAX_SECONDS = 120;                  // N'augmente pas la limite de l'hébergeur.
const AM_MAX_ENTRIES = 20000;
const AM_MAX_DEPTH = 32;
const AM_MAX_FILE_BYTES = 536870912;          // 512 Mio par fichier.
const AM_MAX_TOTAL_BYTES = 2147483648;        // 2 Gio décompressés / sources d'un ZIP.
const AM_MAX_ARCHIVE_BYTES = 2147483648;      // 2 Gio pour un ZIP.
const AM_MAX_RATIO = 1000;
const AM_MAX_SAVED = 20;
const AM_MAX_STORED_BYTES = 4294967296;       // 4 Gio de ZIP privés au total.
const AM_DISK_MARGIN = 16777216;              // Réserve de 16 Mio.
const AM_EXCLUDE_NAMES = ['.git', '.svn', '.hg', '.ssh', 'unzipper.php'];
const AM_DATA_EXTENSIONS = [
    'txt', 'csv', 'tsv', 'json', 'xml', 'yaml', 'yml', 'md', 'log', 'sql',
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
    'jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'bmp', 'ico', 'tif', 'tiff',
    'mp3', 'mp4', 'm4a', 'wav', 'ogg', 'webm', 'mov', 'flac',
    'zip', 'gz', 'bz2', '7z', 'rar', 'woff', 'woff2', 'ttf', 'otf',
];
// ================= FIN DE CONFIGURATION =================

final class AmError extends RuntimeException {}

function am_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function am_bytes(int|float $bytes): string
{
    $units = ['o', 'Kio', 'Mio', 'Gio', 'Tio'];
    $i = 0;
    while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; ++$i; }
    return number_format($bytes, $i ? 1 : 0, ',', ' ') . ' ' . $units[$i];
}

function am_post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    if (!is_string($value) || strlen($value) > 4096) {
        throw new AmError('Paramètre de formulaire invalide.');
    }
    return $value;
}

function am_get(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) && strlen($value) <= 1024 ? $value : $default;
}

function am_deadline(): void
{
    if (microtime(true) >= ($GLOBALS['am_deadline'] ?? PHP_FLOAT_MAX)) {
        throw new AmError('Durée maximale atteinte. Fractionnez les données en archives plus petites.');
    }
}

function am_write($handle, string $data): void
{
    $length = strlen($data);
    for ($offset = 0; $offset < $length;) {
        $written = @fwrite($handle, substr($data, $offset));
        if ($written === false || $written === 0) {
            throw new AmError('Écriture impossible : vérifiez les droits, le quota et l’espace disque.');
        }
        $offset += $written;
    }
}

function am_norm(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/') ?: '/';
}

function am_within(string $path, string $root): bool
{
    $path = am_norm($path); $root = am_norm($root);
    if (DIRECTORY_SEPARATOR === '\\') { $path = strtolower($path); $root = strtolower($root); }
    return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
}

/** Strict, portable relative path. Archive entry names are never silently fixed. */
function am_rel(string $path, bool $allowRoot = true): string
{
    if ($allowRoot && ($path === '' || $path === '.')) { return ''; }
    if ($path === '' || strlen($path) > 1024 || !preg_match('//u', $path)
        || preg_match('/[\\x00-\\x1f\\x7f<>:"|?*\\\\]/', $path)) {
        throw new AmError('Chemin invalide : utilisez un chemin relatif simple, en UTF-8.');
    }
    $parts = explode('/', $path);
    if (count($parts) > AM_MAX_DEPTH) { throw new AmError('Arborescence trop profonde.'); }
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 255
            || $part !== rtrim($part, " .")
            || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\\.|$)/i', $part)) {
            throw new AmError('Chemin refusé : composant ambigu, réservé ou remontée de dossier.');
        }
    }
    return implode('/', $parts);
}

function am_stat(string $path): array|false
{
    clearstatcache(true, $path);
    return @lstat($path);
}

function am_type(array $st): int { return $st['mode'] & 0170000; }

/** Resolve every existing component without following symlinks. */
function am_resolve(string $relative, bool $mustExist = true): string
{
    $relative = am_rel($relative);
    $root = $GLOBALS['am_root'];
    $path = $root;
    if ($relative !== '') {
        $parts = explode('/', $relative);
        foreach ($parts as $i => $part) {
            $path = rtrim($path, '/') . '/' . $part;
            $st = am_stat($path);
            if ($st === false) {
                if ($mustExist) { throw new AmError('Fichier ou dossier introuvable : ' . $relative); }
                continue;
            }
            $type = am_type($st);
            if ($type === 0120000 || !in_array($type, [0100000, 0040000], true)) {
                throw new AmError('Liens symboliques et fichiers spéciaux refusés : ' . $relative);
            }
            if ($i < count($parts) - 1 && $type !== 0040000) {
                throw new AmError('Un fichier occupe un dossier du chemin : ' . $relative);
            }
            if ($type === 0100000 && ($st['nlink'] ?? 1) > 1) {
                throw new AmError('Fichier à plusieurs liens physiques refusé : ' . $relative);
            }
        }
    }
    if (!am_within($path, $root)) { throw new AmError('Chemin hors de la racine autorisée.'); }
    return $path;
}

function am_protected(string $relative): bool
{
    foreach (explode('/', $relative) as $part) {
        if (strcasecmp($part, basename(__FILE__)) === 0 || str_starts_with(strtolower($part), '.am-')) {
            return true;
        }
    }
    return false;
}

function am_data_path(string $path): bool
{
    foreach (explode('/', $path) as $part) {
        if (str_starts_with($part, '.')) { return false; }
    }
    $name = strtolower(basename($path));
    // Refuse compound extensions such as image.php.jpg in document mode.
    if (preg_match('/(?:^|\.)(?:php\d*|phtml|pht|phar|cgi|pl|py|rb|sh|bash|exe|dll|com|bat|cmd|asp|aspx|jsp|shtml)(?:\.|$)/i', $name)) {
        return false;
    }
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), AM_DATA_EXTENSIONS, true);
}

function am_key(string $path): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($path, 'UTF-8') : strtolower($path);
}

/** Index implicit parents too, so a file cannot hide a folder or a case alias. */
function am_register_path(string $path, bool $dir, array &$seen): void
{
    $parts = explode('/', $path); $prefix = '';
    foreach ($parts as $i => $part) {
        $prefix = $prefix === '' ? $part : $prefix . '/' . $part;
        $final = $i === count($parts) - 1;
        $kind = $final && !$dir ? 'file' : 'dir';
        $key = am_key($prefix);
        if (isset($seen[$key])) {
            $previous = $seen[$key];
            if ($previous['path'] !== $prefix || $previous['kind'] !== $kind
                || ($final && ($kind === 'file' || $previous['explicit']))) {
                throw new AmError('Doublon ou conflit de chemins dans l’archive : ' . $path);
            }
            if ($final) { $seen[$key]['explicit'] = true; }
        } else {
            $seen[$key] = ['path' => $prefix, 'kind' => $kind, 'explicit' => $final];
            if (count($seen) > AM_MAX_ENTRIES) {
                throw new AmError('Trop de fichiers/dossiers, y compris les dossiers implicites.');
            }
        }
    }
}

function am_disk(string $path, int $required): void
{
    $free = @disk_free_space($path);
    if ($free !== false && $free < $required + AM_DISK_MARGIN) {
        throw new AmError('Espace disque insuffisant pour cette opération et sa réserve de sécurité.');
    }
}

function am_private_directory(): string
{
    $candidate = AM_PRIVATE_DIR !== '' ? AM_PRIVATE_DIR
        : rtrim(sys_get_temp_dir(), '/\\') . '/archive-manager-' . substr(hash('sha256', __FILE__), 0, 24);
    $parent = realpath(dirname($candidate));
    if ($parent === false || basename($candidate) === '.' || basename($candidate) === '..') {
        throw new AmError('AM_PRIVATE_DIR doit désigner un dossier dont le parent existe et est accessible.');
    }
    $path = am_norm($parent) . '/' . basename($candidate);
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    foreach (array_filter([$GLOBALS['am_root'], $documentRoot]) as $public) {
        if (am_within($path, $public) || am_within($public, $path)) {
            throw new AmError('Configurez AM_PRIVATE_DIR hors de la racine gérée et des dossiers web publics.');
        }
    }
    $st = am_stat($path);
    if ($st === false) {
        if (!@mkdir($path, 0700)) { throw new AmError('Impossible de créer le dossier privé. Configurez AM_PRIVATE_DIR.'); }
        $st = am_stat($path);
    }
    if (!$st || am_type($st) !== 0040000 || !is_writable($path)) {
        throw new AmError('Le dossier privé doit être un dossier local accessible en écriture, sans lien symbolique.');
    }
    if (DIRECTORY_SEPARATOR !== '\\' && ($st['mode'] & 0077) !== 0) {
        throw new AmError('Le dossier privé doit avoir les permissions 0700. Corrigez-le chez votre hébergeur.');
    }
    return $path;
}

function am_private_path(string $basename): string
{
    if (!preg_match('/^[A-Za-z0-9._-]+$/D', $basename)) { throw new AmError('Nom interne invalide.'); }
    $path = $GLOBALS['am_private'] . '/' . $basename;
    $st = am_stat($path);
    if ($st && (am_type($st) !== 0100000 || ($st['nlink'] ?? 1) > 1)) {
        throw new AmError('Fichier privé inattendu. Vérifiez le dossier privé.');
    }
    return $path;
}

function am_throttle(): void
{
    $path = am_private_path('auth-rate.json');
    $handle = @fopen($path, 'c+b');
    if (!$handle || !flock($handle, LOCK_EX)) { throw new AmError('Protection de connexion indisponible.'); }
    try {
        $raw = stream_get_contents($handle, 131073);
        if ($raw === false || strlen($raw) > 131072) { throw new AmError('État de connexion invalide.'); }
        $data = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($data)) { throw new AmError('État de connexion illisible.'); }
        $window = intdiv(time(), 900);
        if (($data['window'] ?? 0) !== $window) { $data = ['window' => $window, 'total' => 0, 'ips' => []]; }
        $key = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        if (($data['total'] ?? 0) >= 80 || ($data['ips'][$key] ?? 0) >= 10) {
            http_response_code(429);
            header('Retry-After: ' . (900 - time() % 900));
            throw new AmError('Trop de tentatives. Réessayez après la prochaine fenêtre de 15 minutes.');
        }
        ++$data['total']; $data['ips'][$key] = ($data['ips'][$key] ?? 0) + 1;
        rewind($handle);
        if (!ftruncate($handle, 0)) { throw new AmError('Protection de connexion indisponible.'); }
        am_write($handle, json_encode($data, JSON_THROW_ON_ERROR));
        if (!fflush($handle)) { throw new AmError('Protection de connexion indisponible.'); }
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}

function am_audit(string $action, bool $ok, array $details = []): void
{
    try {
        $path = am_private_path('audit.log');
        if (is_file($path) && filesize($path) > 1048576) {
            $old = am_private_path('audit.previous.log');
            @rename($path, $old);
        }
        $line = json_encode(['time' => gmdate('c'), 'action' => $action, 'ok' => $ok,
            'client' => substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 16),
            'details' => $details], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $ignored) { error_log('Archive Manager: journal privé indisponible.'); }
}

function am_lock(bool $shared = false)
{
    $handle = @fopen(am_private_path('operation.lock'), 'c+b');
    if (!$handle || !flock($handle, ($shared ? LOCK_SH : LOCK_EX) | LOCK_NB)) {
        if (is_resource($handle)) { fclose($handle); }
        throw new AmError('Une autre opération est en cours. Attendez sa fin puis réessayez.');
    }
    return $handle;
}

function am_artifacts(): array
{
    $result = [];
    foreach (new DirectoryIterator($GLOBALS['am_private']) as $item) {
        // Enumerate actual ZIP bytes, not only their metadata. After a process is
        // killed, an orphan ZIP must remain visible, deletable and counted in quota.
        if (!preg_match('/^archive-([a-f0-9]{32})\.zip$/D', $item->getFilename(), $m)) { continue; }
        $zip = am_private_path($item->getFilename());
        $st = am_stat($zip);
        if (!$st) { continue; }
        $metadata = $GLOBALS['am_private'] . '/artifact-' . $m[1] . '.json';
        $metaStat = am_stat($metadata); $data = null;
        if ($metaStat && am_type($metaStat) === 0100000 && ($metaStat['nlink'] ?? 1) === 1 && $metaStat['size'] <= 4096) {
            $data = json_decode((string)@file_get_contents($metadata), true);
        }
        $valid = is_array($data) && isset($data['name'], $data['created'])
            && is_string($data['name']) && is_int($data['created']) && $data['created'] > 0
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,110}\.zip$/D', $data['name']);
        $result[] = ['id' => $m[1], 'name' => $valid ? $data['name'] : 'recuperation-' . $m[1] . '.zip',
            'created' => $valid ? $data['created'] : $st['mtime'], 'size' => (int)$st['size'], 'recovered' => !$valid];
    }
    usort($result, static fn(array $a, array $b): int => $b['created'] <=> $a['created']);
    return $result;
}

function am_quota(int $additional, bool $needsDiskSpace = true): void
{
    $items = am_artifacts();
    if (count($items) >= AM_MAX_SAVED) { throw new AmError('Limite d’archives privées atteinte : téléchargez puis supprimez-en une.'); }
    $used = array_sum(array_column($items, 'size'));
    if ($used + $additional > AM_MAX_STORED_BYTES) {
        throw new AmError('Quota de ZIP privés insuffisant. Téléchargez et supprimez les anciennes archives.');
    }
    if ($needsDiskSpace) { am_disk($GLOBALS['am_private'], $additional); }
}

function am_artifact(string $id): array
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new AmError('Identifiant d’archive invalide.'); }
    foreach (am_artifacts() as $item) { if ($item['id'] === $id) { return $item; } }
    throw new AmError('Archive privée introuvable.');
}

function am_zip_source(string $reference): string
{
    if (str_starts_with($reference, '@')) {
        $item = am_artifact(substr($reference, 1));
        return am_private_path('archive-' . $item['id'] . '.zip');
    }
    $relative = am_rel(trim($reference), false);
    if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'zip' || am_protected($relative)) {
        throw new AmError('Sélectionnez un fichier .zip de la racine autorisée.');
    }
    $path = am_resolve($relative);
    if (!is_file($path) || !is_readable($path)) { throw new AmError('ZIP non lisible.'); }
    return $path;
}

/** Returns an OPEN ZipArchive; caller must close it in finally. */
function am_plan(string $path): array
{
    $size = @filesize($path);
    if ($size === false || $size > AM_MAX_ARCHIVE_BYTES || $size < 22) {
        throw new AmError('ZIP vide, illisible ou trop volumineux.');
    }
    $zip = new ZipArchive();
    $opened = @$zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS);
    if ($opened !== true) { throw new AmError('ZIP invalide ou endommagé (code ' . (int)$opened . ').'); }
    try {
        if ($zip->numFiles < 1 || $zip->numFiles > AM_MAX_ENTRIES) {
            throw new AmError('Archive vide ou nombre d’entrées supérieur à la limite.');
        }
        $entries = []; $seen = []; $total = 0; $compressed = 0; $files = 0; $dirs = 0; $code = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            am_deadline();
            $s = $zip->statIndex($i, ZipArchive::FL_UNCHANGED);
            $raw = $zip->getNameIndex($i, ZipArchive::FL_ENC_RAW);
            if (!$s || $raw === false || preg_match('/[\\x00-\\x1f\\x7f]/', $raw)) {
                throw new AmError('Entrée ZIP illisible ou nom contenant un caractère de contrôle.');
            }
            $name = $s['name'];
            $opsys = 0; $attr = 0;
            if (!$zip->getExternalAttributesIndex($i, $opsys, $attr)) { throw new AmError('Attributs ZIP illisibles.'); }
            $type = ($attr >> 16) & 0170000;
            if (!in_array($type, [0, 0100000, 0040000], true)) {
                throw new AmError('Lien symbolique ou type spécial dans le ZIP : ' . $name);
            }
            $dir = str_ends_with($name, '/') || $type === 0040000;
            if (str_ends_with($name, '/') && $type === 0100000) { throw new AmError('Type de fichier ZIP incohérent.'); }
            $relative = am_rel($dir && str_ends_with($name, '/') ? substr($name, 0, -1) : $name, false);
            if (am_protected($relative)) { throw new AmError('L’archive contient le gestionnaire ou un nom réservé : ' . $relative); }
            am_register_path($relative, $dir, $seen);
            if (($s['encryption_method'] ?? 0) !== 0) { throw new AmError('Les ZIP chiffrés ne sont pas pris en charge.'); }
            $method = (int)$s['comp_method'];
            $supported = method_exists(ZipArchive::class, 'isCompressionMethodSupported')
                ? ZipArchive::isCompressionMethodSupported($method, false)
                : in_array($method, [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true);
            if (!$supported) {
                throw new AmError('Méthode de compression non prise en charge.');
            }
            $bytes = $s['size']; $packed = $s['comp_size'];
            if (!is_int($bytes) || !is_int($packed) || $bytes < 0 || $packed < 0
                || $bytes > AM_MAX_FILE_BYTES || ($dir && $bytes !== 0)) {
                throw new AmError('Taille d’entrée invalide ou supérieure à la limite : ' . $relative);
            }
            $total += $bytes; $compressed += $packed;
            if ($total > AM_MAX_TOTAL_BYTES || $bytes / max(1, $packed) > AM_MAX_RATIO) {
                throw new AmError('Limite de décompression dépassée (taille ou taux de compression).');
            }
            $data = $dir || am_data_path($relative);
            $dir ? ++$dirs : ++$files;
            if (!$data) { ++$code; }
            $entries[] = ['index' => $i, 'path' => $relative, 'dir' => $dir, 'size' => $bytes,
                'crc' => sprintf('%08x', ((int)$s['crc']) & 0xffffffff), 'data' => $data];
        }
        return [$zip, ['entries' => $entries, 'count' => count($entries), 'files' => $files,
            'dirs' => $dirs, 'bytes' => $total, 'code_count' => $code,
            'ratio' => $total / max(1, $compressed)]];
    } catch (Throwable $e) { @$zip->close(); throw $e; }
}

function am_inspect(string $reference): array
{
    [$zip, $plan] = am_plan(am_zip_source($reference));
    if (!$zip->close()) { throw new AmError('Fermeture du ZIP impossible.'); }
    $plan['archive'] = $reference;
    $plan['entries'] = array_slice($plan['entries'], 0, 120);
    return $plan;
}

function am_new_job(): string
{
    $path = $GLOBALS['am_private'] . '/job-' . bin2hex(random_bytes(16));
    if (!@mkdir($path, 0700)) { throw new AmError('Impossible de créer le dossier temporaire privé.'); }
    return $path;
}

/** Only called on random, locally created job directories. Never follows links. */
function am_remove_job(string $path): bool
{
    if (dirname($path) !== $GLOBALS['am_private'] || !preg_match('/^job-[a-f0-9]{32}$/D', basename($path))) { return false; }
    $st = am_stat($path);
    if (!$st) { return true; }
    if (am_type($st) !== 0040000) { return false; }
    $ok = true;
    try {
        foreach (new DirectoryIterator($path) as $item) {
            if ($item->isDot()) { continue; }
            // Jobs are intentionally flat and contain no subdirectories.
            $child = $item->getPathname(); $c = am_stat($child);
            if (!$c || am_type($c) === 0040000 || !@unlink($child)) { $ok = false; }
        }
        return @rmdir($path) && $ok;
    } catch (Throwable $ignored) { return false; }
}

function am_save_artifact(string $temporary, string $name): array
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($name)) ?? '';
    $name = trim($name, '.-');
    if ($name === '') { $name = 'archive-' . gmdate('Ymd-His') . '.zip'; }
    if (!str_ends_with(strtolower($name), '.zip')) { $name .= '.zip'; }
    $name = substr(pathinfo($name, PATHINFO_FILENAME), 0, 100) . '.zip';
    if (!preg_match('/^[A-Za-z0-9]/', $name)) { $name = 'archive-' . $name; }
    $size = filesize($temporary);
    if ($size === false || $size > AM_MAX_ARCHIVE_BYTES) { throw new AmError('Archive créée trop volumineuse.'); }
    am_quota((int)$size, false); // Bytes already exist in the private temporary file.
    $id = bin2hex(random_bytes(16));
    $target = am_private_path('archive-' . $id . '.zip');
    $meta = am_private_path('artifact-' . $id . '.json');
    $handle = null;
    try {
        if (!@rename($temporary, $target)) { throw new AmError('Impossible de finaliser l’archive privée.'); }
        @chmod($target, 0600);
        $item = ['id' => $id, 'name' => $name, 'size' => (int)$size, 'created' => time()];
        $handle = @fopen($meta, 'xb');
        if (!$handle) { throw new AmError('Impossible d’enregistrer les informations de l’archive.'); }
        am_write($handle, json_encode($item, JSON_THROW_ON_ERROR));
        if (!fflush($handle)) { throw new AmError('Écriture des informations de l’archive incomplète.'); }
        fclose($handle); $handle = null;
        return $item;
    } catch (Throwable $e) {
        if (is_resource($handle)) { fclose($handle); }
        @unlink($target); @unlink($meta); throw $e;
    }
}

/** Bounded streaming; an optional CRC checks uncompressed ZIP data. */
function am_transfer($input, $output, int $expected, ?string $expectedCrc = null): void
{
    $count = 0; $crc = hash_init('crc32b');
    while (!feof($input)) {
        am_deadline();
        $chunk = @fread($input, 65536);
        if ($chunk === false || ($chunk === '' && !feof($input))) {
            throw new AmError('Lecture interrompue ou données corrompues.');
        }
        $count += strlen($chunk);
        if ($count > $expected || $count > AM_MAX_FILE_BYTES) {
            throw new AmError('La taille réellement décompressée dépasse la taille autorisée.');
        }
        hash_update($crc, $chunk);
        am_write($output, $chunk);
    }
    $actualCrc = hash_final($crc);
    if ($count !== $expected || ($expectedCrc !== null && !hash_equals($expectedCrc, $actualCrc))) {
        throw new AmError('Contrôle d’intégrité échoué : taille ou CRC incorrect.');
    }
    if (!fflush($output)) { throw new AmError('Écriture incomplète : vérifiez le quota disque.'); }
}

function am_same_file(array $a, array $b): bool
{
    return $a['dev'] === $b['dev'] && $a['ino'] === $b['ino'];
}

function am_copy_new(string $source, string $target, int $bytes, ?string $crc = null): void
{
    $before = am_stat($source);
    if (!$before || am_type($before) !== 0100000 || ($before['nlink'] ?? 1) !== 1 || $before['size'] !== $bytes) {
        throw new AmError('Le fichier source a changé ou n’est pas un fichier ordinaire.');
    }
    $input = @fopen($source, 'rb'); $output = null;
    if (!$input) { throw new AmError('Impossible de lire un fichier source.'); }
    try {
        $opened = fstat($input);
        if (!$opened || !am_same_file($before, $opened) || am_type($opened) !== 0100000) {
            throw new AmError('Le fichier source a été remplacé pendant l’opération.');
        }
        $output = @fopen($target, 'xb');
        if (!$output) { throw new AmError('Impossible de créer un fichier temporaire exclusif.'); }
        am_transfer($input, $output, $bytes, $crc);
        $after = fstat($input); $current = am_stat($source);
        if (!$after || !$current || !am_same_file($before, $current)
            || $after['size'] !== $before['size'] || $after['mtime'] !== $before['mtime']
            || $after['ctime'] !== $before['ctime']) {
            throw new AmError('Un fichier source a changé. Recommencez quand les écritures sont arrêtées.');
        }
        if (!fclose($output)) { $output = null; throw new AmError('Fermeture d’un fichier écrit impossible.'); }
        $output = null;
    } finally {
        fclose($input);
        if (is_resource($output)) { fclose($output); }
    }
}

function am_preflight_destination(string $destination, array $plan, bool $merge): string
{
    $target = am_resolve($destination, false);
    $existing = am_stat($target);
    if ($existing && am_type($existing) !== 0040000) { throw new AmError('La destination est déjà occupée par un fichier.'); }
    if ($existing && !$merge) {
        throw new AmError('Le dossier de destination existe. Choisissez un nouveau nom ou cochez la fusion sans écrasement.');
    }
    if (am_protected($destination)) { throw new AmError('Destination réservée au gestionnaire.'); }
    foreach ($plan['entries'] as $entry) {
        am_deadline();
        $relative = ($destination === '' ? '' : $destination . '/') . $entry['path'];
        if (am_protected($relative)) { throw new AmError('Écriture du gestionnaire interdite.'); }
        $path = am_resolve($relative, false);
        $st = am_stat($path);
        if ($st && (!$entry['dir'] || am_type($st) !== 0040000)) {
            throw new AmError('Aucun écrasement autorisé. Conflit détecté avant extraction : ' . $relative);
        }
    }
    $parent = $target;
    while (!is_dir($parent)) { $parent = dirname($parent); }
    if (!is_writable($parent)) { throw new AmError('Le dossier de destination n’est pas accessible en écriture.'); }
    am_disk($parent, $plan['bytes']);
    return $target;
}

function am_mkdir_public(string $relative, array &$created): string
{
    $relative = am_rel($relative);
    $path = $GLOBALS['am_root'];
    if ($relative === '') { return $path; }
    foreach (explode('/', $relative) as $part) {
        $path = rtrim($path, '/') . '/' . $part;
        $st = am_stat($path);
        if ($st) {
            if (am_type($st) !== 0040000) { throw new AmError('Le chemin de destination a changé pendant l’opération.'); }
            continue;
        }
        if (!@mkdir($path, 0700)) { throw new AmError('Impossible de créer un dossier de destination.'); }
        $st = am_stat($path);
        if (!$st) { throw new AmError('Impossible de vérifier un dossier créé.'); }
        $created[] = ['path' => $path, 'stat' => $st];
        if (!@chmod($path, 0755)) { throw new AmError('Impossible de régler les permissions d’un dossier créé.'); }
    }
    return $path;
}

/** Remove only objects created by this operation and still having their inode. */
function am_rollback(array $files, array $dirs, string $temporary = ''): bool
{
    $ok = true;
    if ($temporary !== '' && am_stat($temporary) && !@unlink($temporary)) { $ok = false; }
    foreach (array_reverse($files) as $entry) {
        $st = am_stat($entry['path']);
        if (!$st) { continue; }
        if (!am_same_file($st, $entry['stat']) || am_type($st) !== 0100000 || !@unlink($entry['path'])) { $ok = false; }
    }
    foreach (array_reverse($dirs) as $entry) {
        $st = am_stat($entry['path']);
        if (!$st) { continue; }
        if (!am_same_file($st, $entry['stat']) || am_type($st) !== 0040000 || !@rmdir($entry['path'])) { $ok = false; }
    }
    return $ok;
}

function am_extract(string $reference, string $destination, bool $allowCode, bool $merge): array
{
    $destination = am_rel(trim($destination));
    [$zip, $plan] = am_plan(am_zip_source($reference));
    $job = ''; $publicTemp = ''; $createdFiles = []; $createdDirs = []; $done = false;
    $cleanupOk = true;
    $GLOBALS['am_cleanup'] = static function () use (&$job, &$publicTemp, &$createdFiles, &$createdDirs, &$done): void {
        if (!$done) { am_rollback($createdFiles, $createdDirs, $publicTemp); }
        if ($job !== '') { am_remove_job($job); }
    };
    try {
        if ($plan['code_count'] > 0 && !$allowCode) {
            throw new AmError('L’archive contient ' . $plan['code_count'] . ' fichier(s) hors mode documents. '
                . 'Pour un site de confiance, cochez l’autorisation des fichiers de site et de configuration.');
        }
        if (!function_exists('link')) {
            throw new AmError('La fonction PHP link() est requise pour publier sans écraser un fichier. Contactez votre hébergeur.');
        }
        am_preflight_destination($destination, $plan, $merge);
        am_disk($GLOBALS['am_private'], $plan['bytes']);
        $job = am_new_job();
        foreach ($plan['entries'] as $entry) {
            if ($entry['dir']) { continue; }
            am_deadline();
            $input = @$zip->getStreamIndex($entry['index'], ZipArchive::FL_UNCHANGED);
            if (!$input) { throw new AmError('Impossible de décompresser : ' . $entry['path']); }
            $output = @fopen($job . '/' . $entry['index'] . '.data', 'xb');
            if (!$output) { fclose($input); throw new AmError('Impossible de préparer un fichier extrait.'); }
            try {
                am_transfer($input, $output, $entry['size'], $entry['crc']);
            } finally { fclose($input); fclose($output); }
        }
        if (!$zip->close()) { throw new AmError('Fermeture du ZIP impossible après vérification.'); }
        $zip = null;
        // Repeat conflict checks after the private stage, before publishing anything.
        am_preflight_destination($destination, $plan, $merge);
        am_mkdir_public($destination, $createdDirs);
        foreach ($plan['entries'] as $entry) {
            am_deadline();
            $relative = ($destination === '' ? '' : $destination . '/') . $entry['path'];
            if ($entry['dir']) { am_mkdir_public($relative, $createdDirs); continue; }
            $parentRelative = dirname($relative);
            $parent = am_mkdir_public($parentRelative === '.' ? '' : $parentRelative, $createdDirs);
            $parentStat = am_stat($parent);
            $target = am_resolve($relative, false);
            if (am_stat($target)) { throw new AmError('Un fichier vient d’apparaître à destination : ' . $relative); }
            $publicTemp = $parent . '/.am-' . bin2hex(random_bytes(16)) . '.part';
            am_copy_new($job . '/' . $entry['index'] . '.data', $publicTemp, $entry['size'], $entry['crc']);
            if (!@chmod($publicTemp, 0644)) { throw new AmError('Impossible de régler les permissions d’un fichier extrait.'); }
            am_resolve($parentRelative === '.' ? '' : $parentRelative);
            $now = am_stat($parent);
            if (!$parentStat || !$now || !am_same_file($parentStat, $now)) {
                throw new AmError('Le dossier de destination a changé pendant la publication.');
            }
            $tempStat = am_stat($publicTemp);
            // link() creates a name exclusively: unlike rename(), it never replaces an existing target.
            if (!$tempStat || !@link($publicTemp, $target)) {
                throw new AmError('Publication impossible sans écrasement. Vérifiez les conflits et la prise en charge de link().');
            }
            $createdFiles[] = ['path' => $target, 'stat' => $tempStat];
            if (!@unlink($publicTemp)) { throw new AmError('Impossible de nettoyer un fichier temporaire de publication.'); }
            $publicTemp = '';
        }
        $done = true;
    } catch (Throwable $e) {
        $clean = am_rollback($createdFiles, $createdDirs, $publicTemp);
        $createdFiles = []; $createdDirs = []; $publicTemp = '';
        if (!$clean) {
            throw new AmError('Opération interrompue. Nettoyage incomplet : vérifiez le dossier de destination. Cause : ' . $e->getMessage(), 0, $e);
        }
        throw $e;
    } finally {
        if ($zip instanceof ZipArchive) { try { @$zip->close(); } catch (Throwable $ignored) {} }
        if ($job !== '') { $cleanupOk = am_remove_job($job); }
        $GLOBALS['am_cleanup'] = null;
    }
    return ['files' => $plan['files'], 'bytes' => $plan['bytes'], 'destination' => $destination ?: '.', 'cleanup' => $cleanupOk];
}

function am_sources(string $relative): array
{
    $relative = am_rel(trim($relative));
    if (am_protected($relative)) { throw new AmError('Le gestionnaire lui-même est exclu des sauvegardes.'); }
    $source = am_resolve($relative);
    $list = []; $seen = []; $skipped = []; $skippedCount = 0; $bytes = 0;
    $skip = static function (string $name) use (&$skipped, &$skippedCount): void {
        ++$skippedCount; if (count($skipped) < 12) { $skipped[] = $name; }
    };
    $visit = function (string $path, string $name, int $depth) use (&$visit, &$list, &$seen, &$bytes, $skip): void {
        am_deadline();
        if ($depth > AM_MAX_DEPTH) { throw new AmError('Arborescence source trop profonde.'); }
        $base = basename($path);
        if ($path === am_norm(__FILE__) || am_protected($name) || in_array($base, AM_EXCLUDE_NAMES, true)) { $skip($name); return; }
        $st = am_stat($path);
        if (!$st) { throw new AmError('Un élément source a disparu : ' . $name); }
        $kind = am_type($st);
        if (!in_array($kind, [0100000, 0040000], true) || ($kind === 0100000 && ($st['nlink'] ?? 1) > 1)) { $skip($name); return; }
        if (!is_readable($path)) { throw new AmError('Un élément source est illisible : ' . $name); }
        $name = am_rel($name, false);
        $dir = $kind === 0040000;
        am_register_path($name, $dir, $seen);
        $size = $dir ? 0 : $st['size'];
        $bytes += $size;
        if ($size > AM_MAX_FILE_BYTES || $bytes > AM_MAX_TOTAL_BYTES || count($list) >= AM_MAX_ENTRIES) {
            throw new AmError('Les sources dépassent les limites de taille ou de nombre de fichiers.');
        }
        $list[] = ['source' => $path, 'path' => $name, 'dir' => $dir, 'size' => $size, 'mtime' => $st['mtime']];
        if ($dir) {
            foreach (new DirectoryIterator($path) as $item) {
                if (!$item->isDot()) { $visit($item->getPathname(), $name . '/' . $item->getFilename(), $depth + 1); }
            }
        }
    };
    if (is_dir($source)) {
        foreach (new DirectoryIterator($source) as $item) {
            if (!$item->isDot()) { $visit($item->getPathname(), $item->getFilename(), 1); }
        }
    } else { $visit($source, basename($source), 1); }
    if (!$list) { throw new AmError('Aucun fichier ou sous-dossier à archiver après les exclusions.'); }
    return ['entries' => $list, 'bytes' => $bytes, 'skipped' => $skipped, 'skipped_count' => $skippedCount];
}

function am_publish_zip(string $output, string $name, string $directory): array
{
    $directory = am_rel(trim($directory));
    if (am_protected($directory)) { throw new AmError('Destination protégée.'); }
    $folder = am_resolve($directory);
    if (!is_dir($folder) || !is_writable($folder)) { throw new AmError('Le dossier de destination doit exister et être accessible en écriture.'); }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,140}$/D', $name)) { throw new AmError('Nom de ZIP invalide.'); }
    if (!str_ends_with(strtolower($name), '.zip')) { $name .= '.zip'; }
    $relative = ($directory === '' ? '' : $directory . '/') . $name;
    am_rel($relative, false);
    if (am_protected($relative)) { throw new AmError('Destination protégée.'); }
    $target = $folder . '/' . $name;
    if (am_stat($target)) { throw new AmError('Ce ZIP existe déjà. Aucun écrasement autorisé.'); }
    $size = filesize($output);
    if ($size === false || $size > AM_MAX_ARCHIVE_BYTES) { throw new AmError('ZIP trop volumineux.'); }
    am_disk($folder, $size);
    $tmp = $folder . '/.am-' . bin2hex(random_bytes(16)) . '.tmp';
    $in = @fopen($output, 'rb');
    if (!$in) { throw new AmError('ZIP temporaire illisible.'); }
    $out = null; $owned = false;
    try {
        $out = @fopen($tmp, 'xb');
        if (!$out) { throw new AmError('Impossible de créer le fichier temporaire de destination.'); }
        $owned = true;
        if (!@chmod($tmp, 0600)) { throw new AmError('Impossible de protéger le fichier temporaire.'); }
        $count = 0;
        while (!feof($in)) {
            am_deadline();
            $chunk = fread($in, 65536);
            if ($chunk === false || ($chunk === '' && !feof($in))) { throw new AmError('Lecture du ZIP interrompue.'); }
            $count += strlen($chunk);
            if ($count > $size) { throw new AmError('Taille du ZIP modifiée.'); }
            am_write($out, $chunk);
        }
        if ($count !== $size || !fflush($out)) { throw new AmError('Copie du ZIP incomplète.'); }
        $ok = fclose($out); $out = null;
        if (!$ok || am_resolve($directory) !== $folder || !@link($tmp, $target)) { throw new AmError('Publication impossible : vérifiez les droits, les liens physiques et les conflits de noms.'); }
    } finally {
        fclose($in);
        if (is_resource($out)) { fclose($out); }
        if ($owned) { @unlink($tmp); }
    }
    return ['id' => null, 'name' => $name, 'size' => $size, 'relative' => $relative];
}

function am_create(string $source, string $name, int $compression, string $storage = 'private', string $directory = '.', bool $confirmed = false): array
{
    if (!in_array($compression, [0, 6, 9], true)) { throw new AmError('Niveau de compression invalide.'); }
    if (!in_array($storage, ['private', 'server'], true) || ($storage === 'server' && !$confirmed)) { throw new AmError('Confirmez le risque d’accès public avant de créer un ZIP sur le serveur.'); }
    $plan = am_sources($source);
    // Sources are snapshotted individually into private files. ZipArchive only reads these copies.
    $overhead = count($plan['entries']) * 2048 + 1048576;
    am_quota($plan['bytes'] + $overhead);
    am_disk($GLOBALS['am_private'], 2 * $plan['bytes'] + $overhead);
    $job = am_new_job(); $zip = null; $closed = false;
    $GLOBALS['am_cleanup'] = static function () use ($job): void { am_remove_job($job); };
    try {
        $output = $job . '/result.zip';
        $zip = new ZipArchive();
        if ($zip->open($output, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new AmError('Impossible de créer le ZIP.'); }
        if (method_exists($zip, 'registerCancelCallback')) {
            $deadline = $GLOBALS['am_deadline'];
            $zip->registerCancelCallback(static fn(): int => microtime(true) >= $deadline ? 1 : 0);
        }
        foreach ($plan['entries'] as $i => $entry) {
            am_deadline();
            $entryName = $entry['path'] . ($entry['dir'] ? '/' : '');
            if ($entry['dir']) {
                $ok = $zip->addEmptyDir($entry['path']);
            } else {
                $staged = $job . '/' . $i . '.data';
                am_copy_new($entry['source'], $staged, $entry['size']);
                $ok = $zip->addFile($staged, $entry['path'], 0, 0, 0);
                if ($ok) {
                    $ok = $zip->setCompressionName($entry['path'], $compression === 0 ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE, $compression);
                }
            }
            if (!$ok || !$zip->setExternalAttributesName($entryName, ZipArchive::OPSYS_UNIX, ($entry['dir'] ? 0040755 : 0100644) << 16)) {
                throw new AmError('Ajout au ZIP impossible : ' . $entry['path']);
            }
            if (method_exists($zip, 'setMtimeName') && !$zip->setMtimeName($entryName, max(315532800, $entry['mtime']))) {
                throw new AmError('Impossible d’enregistrer la date d’un fichier dans le ZIP.');
            }
        }
        am_deadline();
        $closed = @$zip->close();
        $zip = null;
        if (!$closed) { throw new AmError('La création du ZIP a échoué ou dépassé le délai. Aucun succès n’a été enregistré.'); }
        am_deadline();
        [$check, $verified] = am_plan($output);
        $check->close();
        if ($verified['count'] !== count($plan['entries']) || $verified['bytes'] !== $plan['bytes']) {
            throw new AmError('Le ZIP final ne contient pas toutes les données attendues.');
        }
        $result = $storage === 'server' ? am_publish_zip($output, $name, $directory) : am_save_artifact($output, $name);
        $result['skipped'] = $plan['skipped']; $result['skipped_count'] = $plan['skipped_count'];
        $result['entries'] = count($plan['entries']);
    } finally {
        if ($zip instanceof ZipArchive) {
            try { $zip->unchangeAll(); @$zip->close(); } catch (Throwable $ignored) {}
        }
        $cleanup = am_remove_job($job);
        $GLOBALS['am_cleanup'] = null;
    }
    $result['cleanup'] = $cleanup;
    return $result;
}

function am_upload(): array
{
    $file = $_FILES['archive_file'] ?? null;
    if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['name'])
        || !is_int($file['error']) || !is_string($file['tmp_name']) || !is_string($file['name'])) {
        throw new AmError('Aucun fichier ZIP reçu. Vérifiez les limites d’import de votre hébergeur.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new AmError('Import refusé par PHP (code ' . $file['error'] . '). Vérifiez upload_max_filesize et post_max_size.');
    }
    if (!is_uploaded_file($file['tmp_name']) || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'zip') {
        throw new AmError('L’import doit contenir un fichier .zip local.');
    }
    $size = filesize($file['tmp_name']);
    if ($size === false || $size > AM_MAX_ARCHIVE_BYTES) { throw new AmError('ZIP importé trop volumineux.'); }
    am_quota((int)$size);
    $job = am_new_job();
    $GLOBALS['am_cleanup'] = static function () use ($job): void { am_remove_job($job); };
    try {
        $target = $job . '/upload.zip';
        if (!move_uploaded_file($file['tmp_name'], $target)) { throw new AmError('Impossible de placer le ZIP dans le dossier privé.'); }
        @chmod($target, 0600);
        [$zip, $plan] = am_plan($target);
        if (!$zip->close()) { throw new AmError('Vérification du ZIP importé impossible.'); }
        $result = am_save_artifact($target, $file['name']);
    } finally { am_remove_job($job); $GLOBALS['am_cleanup'] = null; }
    return $result;
}

function am_download(string $id): never
{
    // A transparent PHP output compressor would invalidate Content-Length.
    @ini_set('zlib.output_compression', '0');
    if (filter_var(ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN)) {
        throw new AmError('Désactivez zlib.output_compression pour télécharger une archive sans transformation.');
    }
    $item = am_artifact($id);
    $path = am_private_path('archive-' . $id . '.zip');
    $handle = @fopen($path, 'rb');
    if (!$handle) { throw new AmError('Archive privée illisible.'); }
    $st = fstat($handle);
    if (!$st || am_type($st) !== 0100000 || $st['size'] !== $item['size']) { fclose($handle); throw new AmError('Archive privée modifiée.'); }
    am_audit('download_started', true, ['id' => $id, 'bytes' => $item['size']]);
    session_write_close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $item['name'] . '"');
    header('Content-Length: ' . $item['size']);
    header('X-Content-Type-Options: nosniff');
    $sent = 0; $complete = true;
    while (!feof($handle)) {
        $chunk = @fread($handle, 1048576);
        if ($chunk === false || ($chunk === '' && !feof($handle))) { $complete = false; break; }
        echo $chunk;
        $sent += strlen($chunk);
        if (connection_aborted()) { $complete = false; break; }
    }
    fclose($handle);
    am_audit('download_streamed', $complete && $sent === $item['size'], ['id' => $id, 'bytes' => $sent]);
    exit;
}

function am_delete(string $id): void
{
    am_artifact($id);
    $zip = am_private_path('archive-' . $id . '.zip');
    $meta = am_private_path('artifact-' . $id . '.json');
    if (!@unlink($zip)) { throw new AmError('Suppression du ZIP privé impossible. Vérifiez les droits du dossier privé.'); }
    if (am_stat($meta) && !@unlink($meta)) {
        throw new AmError('Le ZIP a été supprimé, mais ses informations résiduelles n’ont pas pu être nettoyées.');
    }
}

function am_browse(string $relative): array
{
    $relative = am_rel($relative);
    $path = am_resolve($relative);
    if (!is_dir($path) || !is_readable($path)) { throw new AmError('Dossier non lisible.'); }
    $entries = [];
    foreach (new DirectoryIterator($path) as $item) {
        am_deadline();
        if ($item->isDot()) { continue; }
        $name = $item->getFilename();
        $full = ($relative === '' ? '' : $relative . '/') . $name;
        $st = am_stat($item->getPathname());
        if (!$st || !in_array(am_type($st), [0100000, 0040000], true) || am_protected($full)) { continue; }
        try { am_rel($full, false); } catch (AmError $ignored) { continue; }
        $entries[] = ['name' => $name, 'path' => $full, 'dir' => am_type($st) === 0040000,
            'zip' => am_type($st) === 0100000 && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip', 'size' => (int)$st['size']];
        if (count($entries) >= 250) { break; }
    }
    usort($entries, static fn(array $a, array $b): int => ($b['dir'] <=> $a['dir']) ?: strnatcasecmp($a['name'], $b['name']));
    return $entries;
}

function am_https(): bool
{
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off' && $https !== '0') { return true; }
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return in_array($remote, AM_TRUSTED_PROXIES, true)
        && strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
}

function am_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '0' || $value === '-1') { return PHP_INT_MAX; }
    if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([KMGTP]?)$/i', $value, $m)) { return 0; }
    $factor = $m[2] === '' ? 0 : strpos('KMGTP', strtoupper($m[2])) + 1;
    return (int)min(PHP_INT_MAX, (float)$m[1] * (1024 ** $factor));
}

function am_session(bool $https, string $url): void
{
    $path = $GLOBALS['am_private'] . '/sessions';
    $st = am_stat($path);
    if (!$st) {
        if (!@mkdir($path, 0700)) { throw new AmError('Impossible de créer le stockage privé des sessions.'); }
        $st = am_stat($path);
    }
    if (!$st || am_type($st) !== 0040000 || (DIRECTORY_SEPARATOR !== '\\' && ($st['mode'] & 0077) !== 0)) {
        throw new AmError('Le stockage des sessions doit être un dossier privé de permissions 0700.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) { throw new AmError('Désactivez session.auto_start pour ce gestionnaire autonome.'); }
    session_name('AM' . substr(hash('sha256', __FILE__), 0, 16));
    session_module_name('files');
    session_save_path($path);
    if (!@session_start([
        'use_strict_mode' => 1, 'use_only_cookies' => 1, 'use_trans_sid' => 0,
        'cookie_lifetime' => 0, 'cookie_path' => rtrim(str_replace('\\', '/', dirname($url)), '/') . '/',
        'cookie_httponly' => 1, 'cookie_secure' => $https, 'cookie_samesite' => 'Strict',
        'gc_maxlifetime' => AM_SESSION_SECONDS, 'gc_probability' => 1, 'gc_divisor' => 100,
    ])) { throw new AmError('Sessions PHP indisponibles. Vérifiez les droits du stockage privé.'); }
    if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
}

function am_redirect(string $url): never
{
    session_write_close();
    header('Location: ' . $url, true, 303);
    exit;
}

function am_password_input(): string
{
    $password = am_post('password');
    if (strlen($password) < 14 || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new AmError('Le mot de passe doit contenir entre 14 et 72 octets, sans caractère nul.');
    }
    return $password;
}

function am_main(): void
{
    @ini_set('display_errors', '0');
    umask(0077);
    $nonce = base64_encode(random_bytes(24));
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? ('/' . basename(__FILE__)));
    if (preg_match('/[\\x00-\\x20\\x7f"\'<>?#\\\\]/', $script)) { $script = '/' . basename(__FILE__); }
    $url = '/' . ltrim($script, '/');
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

    $v = ['view' => 'error', 'error' => '', 'success' => '', 'hash' => '', 'nonce' => $nonce,
        'url' => $url, 'csrf' => '', 'root' => '', 'runtime' => '', 'budget_seconds' => 0,
        'zip_available' => false, 'upload_limit' => '', 'browse' => '', 'parent' => null,
        'entries' => [], 'artifacts' => [], 'inspection' => null,
        'defaults' => ['source' => '.', 'archive' => '', 'destination' => 'extraction-' . gmdate('Ymd-His')],
        'limit_entries' => AM_MAX_ENTRIES, 'limit_total' => AM_MAX_TOTAL_BYTES,
        'limit_file' => AM_MAX_FILE_BYTES, 'self' => basename(__FILE__)];
    $lock = null; $action = ''; $authenticated = false;
    $GLOBALS['am_cleanup'] = null;
    $reserve = str_repeat('x', 262144);
    register_shutdown_function(static function () use (&$reserve): void {
        $reserve = '';
        $cleanup = $GLOBALS['am_cleanup'] ?? null;
        if (is_callable($cleanup)) {
            try { $cleanup(); } catch (Throwable $ignored) { error_log('Archive Manager: nettoyage après interruption incomplet.'); }
        }
    });
    try {
        if (PHP_VERSION_ID < 80200 || PHP_INT_SIZE < 8) { throw new AmError('Ce fichier nécessite PHP 8.2 ou plus récent en 64 bits.'); }
        if (AM_MAX_ENTRIES < 1 || AM_MAX_SECONDS < 5 || AM_MAX_DEPTH < 1) { throw new AmError('Limites de configuration invalides.'); }
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (AM_ALLOWED_IPS && !in_array($remote, AM_ALLOWED_IPS, true)) {
            http_response_code(403); throw new AmError('Cette adresse IP n’est pas autorisée.');
        }
        $https = am_https();
        if (AM_REQUIRE_HTTPS && !$https) {
            http_response_code(403);
            throw new AmError('Ouvrez cette page en HTTPS. Si un proxy termine HTTPS, configurez uniquement son IP dans AM_TRUSTED_PROXIES.');
        }
        $root = realpath(AM_ROOT);
        if ($root === false || !is_dir($root)) { throw new AmError('La racine AM_ROOT n’est pas un dossier accessible.'); }
        $GLOBALS['am_root'] = am_norm($root);
        $GLOBALS['am_private'] = am_private_directory();
        $hostTime = (int)ini_get('max_execution_time');
        $budget = $hostTime > 0 ? min(AM_MAX_SECONDS, max(1, $hostTime - 3)) : AM_MAX_SECONDS;
        $GLOBALS['am_deadline'] = microtime(true) + $budget;
        $v['budget_seconds'] = $budget;
        am_session($https, $url);
        $v['csrf'] = $_SESSION['csrf'];
        $environmentHash = getenv('ARCHIVE_MANAGER_PASSWORD_HASH');
        $passwordHash = is_string($environmentHash) && $environmentHash !== '' ? $environmentHash : AM_PASSWORD_HASH;
        if ($passwordHash !== '' && (password_get_info($passwordHash)['algoName'] ?? 'unknown') === 'unknown') {
            throw new AmError('AM_PASSWORD_HASH ne contient pas une empreinte password_hash() valide.');
        }
        $fingerprint = hash('sha256', $passwordHash);
        $authenticated = $passwordHash !== '' && isset($_SESSION['auth']) && is_string($_SESSION['auth'])
            && hash_equals($fingerprint, $_SESSION['auth'])
            && time() - (int)($_SESSION['last'] ?? 0) < AM_IDLE_SECONDS
            && time() - (int)($_SESSION['started'] ?? 0) < AM_SESSION_SECONDS;
        if (!$authenticated) { unset($_SESSION['auth'], $_SESSION['last'], $_SESSION['started']); }
        else { $_SESSION['last'] = time(); }
        $v['view'] = $passwordHash === '' ? 'setup' : ($authenticated ? 'dashboard' : 'login');
        if (isset($_SESSION['flash']) && is_array($_SESSION['flash'])) {
            $v['success'] = (string)($_SESSION['flash']['success'] ?? '');
            $v['defaults']['archive'] = (string)($_SESSION['flash']['archive'] ?? '');
            unset($_SESSION['flash']);
        }
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) {
            header('Allow: GET, HEAD, POST'); http_response_code(405);
            throw new AmError('Méthode HTTP non autorisée.');
        }
        if ($method === 'POST') {
            if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
                http_response_code(413);
                throw new AmError('Formulaire non reçu : l’envoi dépasse peut-être post_max_size. Utilisez SFTP pour un gros ZIP.');
            }
            $action = am_post('action');
            if (!hash_equals($_SESSION['csrf'], am_post('csrf'))) {
                http_response_code(403); throw new AmError('Formulaire expiré ou jeton de sécurité invalide. Rechargez la page.');
            }
            if ($action === 'hash_password' && $passwordHash === '') {
                am_throttle();
                $password = am_password_input();
                $v['hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                unset($password);
            } elseif ($action === 'login' && $passwordHash !== '') {
                am_throttle();
                $password = am_password_input();
                $valid = password_verify($password, $passwordHash);
                unset($password);
                if (!$valid) { http_response_code(401); throw new AmError('Mot de passe incorrect.'); }
                if (!session_regenerate_id(true)) { throw new AmError('Renouvellement de session impossible.'); }
                $_SESSION = ['auth' => $fingerprint, 'last' => time(), 'started' => time(), 'csrf' => bin2hex(random_bytes(32))];
                am_audit('login', true);
                am_redirect($url);
            } else {
                if (!$authenticated) { http_response_code(401); throw new AmError('Authentification requise.'); }
                if ($action === 'logout') {
                    am_audit('logout', true);
                    $_SESSION = []; session_regenerate_id(true);
                    $_SESSION['csrf'] = bin2hex(random_bytes(32));
                    am_redirect($url);
                }
                if (!in_array($action, ['inspect', 'extract', 'create', 'upload', 'download', 'delete'], true)) {
                    throw new AmError('Action inconnue.');
                }
                if (!in_array($action, ['download', 'delete'], true)
                    && (!class_exists('ZipArchive') || !method_exists('ZipArchive', 'getStreamIndex'))) {
                    throw new AmError('Activez l’extension zip pour PHP 8.2 ou plus récent.');
                }
                $lock = am_lock(in_array($action, ['inspect', 'download'], true));
                $message = ''; $archive = '';
                switch ($action) {
                    case 'inspect':
                        $reference = trim(am_post('archive'));
                        $v['defaults']['archive'] = $reference;
                        $v['inspection'] = am_inspect($reference);
                        am_audit('inspect', true, ['entries' => $v['inspection']['count']]);
                        break;
                    case 'extract':
                        $reference = trim(am_post('archive'));
                        $destination = trim(am_post('destination'));
                        if ($destination === '') { throw new AmError('Indiquez une destination. Pour la racine, saisissez . et autorisez la fusion.'); }
                        $v['defaults']['archive'] = $reference;
                        $v['defaults']['destination'] = $destination;
                        $r = am_extract($reference, $destination, am_post('allow_code') === '1', am_post('merge') === '1');
                        $message = $r['files'] . ' fichier(s), ' . am_bytes($r['bytes']) . ', extraits vers « ' . $r['destination'] . ' ». Aucun fichier existant écrasé.';
                        if (!$r['cleanup']) { $message .= ' Le nettoyage temporaire privé reste à vérifier.'; }
                        am_audit('extract', true, $r);
                        break;
                    case 'create':
                        $source = trim(am_post('source', '.'));
                        $v['defaults']['source'] = $source;
                        $r = am_create($source, am_post('name', 'sauvegarde-' . gmdate('Ymd-His') . '.zip'), (int)am_post('compression', '6'), am_post('storage', 'private'), am_post('zip_destination', '.'), am_post('public_confirm') === '1');
                        $archive = $r['relative'] ?? ('@' . $r['id']);
                        $message = $r['name'] . ' créé : ' . am_bytes($r['size']) . ', ' . $r['entries'] . ' entrée(s). ' . (isset($r['relative']) ? 'Enregistré sur le serveur : ' . $r['relative'] : 'Téléchargez-le dans « Archives privées ».');
                        if ($r['skipped_count']) { $message .= ' Éléments exclus : ' . $r['skipped_count'] . ' (' . implode(', ', $r['skipped']) . ').'; }
                        if (!$r['cleanup']) { $message .= ' Le nettoyage temporaire privé reste à vérifier.'; }
                        am_audit('create', true, ['id' => $r['id'], 'destination' => $r['relative'] ?? 'private', 'size' => $r['size'], 'skipped' => $r['skipped_count']]);
                        break;
                    case 'upload':
                        $r = am_upload(); $archive = '@' . $r['id'];
                        $message = $r['name'] . ' importé dans l’espace privé. Structure vérifiée ; le CRC des fichiers sera vérifié avant extraction.';
                        am_audit('upload', true, ['id' => $r['id'], 'size' => $r['size']]);
                        break;
                    case 'download': am_download(am_post('id'));
                    case 'delete':
                        $id = am_post('id'); am_delete($id);
                        $message = 'L’archive privée a été supprimée. Les fichiers sources et extraits sont conservés.';
                        am_audit('delete', true, ['id' => $id]);
                        break;
                }
                if ($message !== '') {
                    $_SESSION['flash'] = ['success' => $message, 'archive' => $archive];
                    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); $lock = null; }
                    am_redirect($url);
                }
            }
        }
    } catch (AmError $e) {
        $v['error'] = $e->getMessage();
        if (http_response_code() < 400) { http_response_code(400); }
        if ($action !== '' && isset($GLOBALS['am_private'])) { am_audit($action, false, ['reason' => $v['error']]); }
    } catch (Throwable $e) {
        $id = bin2hex(random_bytes(4));
        error_log('Archive Manager [' . $id . ']: ' . get_class($e) . ': ' . $e->getMessage());
        http_response_code(500);
        $v['error'] = 'Erreur interne. Consultez le journal PHP de votre hébergeur avec la référence ' . $id . '.';
    } finally {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
    if ($authenticated && $v['view'] === 'dashboard') {
        $v['root'] = $GLOBALS['am_root'];
        $v['runtime'] = 'PHP ' . PHP_VERSION;
        $v['zip_available'] = class_exists('ZipArchive') && method_exists('ZipArchive', 'getStreamIndex');
        $v['upload_limit'] = am_bytes(min(AM_MAX_ARCHIVE_BYTES, am_ini_bytes((string)ini_get('upload_max_filesize')), am_ini_bytes((string)ini_get('post_max_size'))));
        $v['defaults']['source'] = am_get('source', $v['defaults']['source']);
        $v['defaults']['archive'] = am_get('archive', $v['defaults']['archive']);
        try {
            // Listing has its own short budget after a finished/failed operation.
            $GLOBALS['am_deadline'] = microtime(true) + 5;
            $browse = am_rel(am_get('dir'));
            $v['browse'] = $browse;
            $v['entries'] = am_browse($browse);
            $v['parent'] = $browse === '' ? null : (dirname($browse) === '.' ? '' : dirname($browse));
            $v['artifacts'] = am_artifacts();
        } catch (Throwable $e) {
            if ($v['error'] === '') { $v['error'] = $e instanceof AmError ? $e->getMessage() : 'Impossible de lister ce dossier.'; }
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    am_render($v);
}

// No administrative operation runs from the command line. This helper only hashes a password.
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') === '--hash') {
        fwrite(STDOUT, "Mot de passe (14 à 72 octets ; saisie visible, pas dans l’historique des commandes) : ");
        $password = rtrim((string)fgets(STDIN, 4096), "\r\n");
        if (strlen($password) < 14 || strlen($password) > 72 || str_contains($password, "\0")) {
            fwrite(STDERR, "Longueur de mot de passe invalide.\n"); exit(1);
        }
        fwrite(STDOUT, "const AM_PASSWORD_HASH = '" . password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]) . "';\n");
        unset($password); exit(0);
    }
    fwrite(STDOUT, "Ouvrez ce fichier par HTTPS. Générer une empreinte en CLI : php archive-manager.php --hash\n");
    exit(0);
}

/** Interface autonome, sans ressource externe. Les valeurs affichées sont échappées. */
function am_render(array $v): void
{
    $view = $v['view'] ?? 'error';
    $dashboard = $view === 'dashboard';
    $defaults = $v['defaults'] ?? [];
    $inspection = $v['inspection'] ?? null;
    $token = static function (string $action) use ($v): void {
        echo '<input type="hidden" name="csrf" value="' . am_h((string) ($v['csrf'] ?? '')) . '">';
        echo '<input type="hidden" name="action" value="' . am_h($action) . '">';
    };
    $link = static function (array $query) use ($v): string {
        return (string) $v['url'] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    };
    $selectedArchive = (string) ($inspection['archive'] ?? $defaults['archive'] ?? '');
    $newName = 'sauvegarde-' . date('Y-m-d-His') . '.zip';
    ?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <meta name="color-scheme" content="light">
  <title>Archive Manager · ZIP sur votre serveur</title>
  <style nonce="<?= am_h((string) $v['nonce']) ?>">
    :root{--ink:#14283e;--text:#334a60;--muted:#63778a;--blue:#315f87;--blue-dark:#244b6e;--pale:#edf3f8;--line:#dce5ec;--paper:#fff;--bg:#f4f7fa;--green:#17654e;--green-pale:#eaf5ef;--amber:#83530d;--amber-pale:#fff7e6;--red:#9f303b;--red-pale:#fff0f1;--radius:14px}
    *{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:14px;line-height:1.55}a{color:var(--blue);text-decoration-thickness:1px;text-underline-offset:3px}a:hover{color:var(--blue-dark)}button,input,select{font:inherit}button,a,input,select,summary{touch-action:manipulation}button{cursor:pointer}button:disabled{cursor:wait;opacity:.7}button:focus-visible,a:focus-visible,summary:focus-visible{outline:3px solid #82add0;outline-offset:4px}input:focus,select:focus{outline:2px solid #8bb4d5;outline-offset:1px;border-color:var(--blue)}[hidden]{display:none!important}
    .topbar{background:var(--paper);border-bottom:1px solid var(--line)}.top-inner{max-width:1280px;margin:auto;padding:20px 32px;display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{display:flex;gap:13px;align-items:center;text-decoration:none;color:var(--ink)}.brand-mark{display:grid;place-items:center;width:43px;height:43px;border-radius:11px;background:var(--ink);color:#fff;font-size:12px;font-weight:800;letter-spacing:.06em}.brand-name{font-size:17px;font-weight:750;letter-spacing:-.025em}.brand-sub{display:block;color:var(--muted);font-size:12px;font-weight:450;letter-spacing:0}.top-actions{display:flex;align-items:center;gap:16px}.badge{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);border-radius:999px;padding:4px 10px;font-size:11px;font-weight:650;color:var(--muted);background:#fff;white-space:nowrap}.badge.safe{color:var(--green);border-color:#c7e2d4;background:var(--green-pale)}.badge.small{padding:1px 7px;font-size:10px}.badge.code{color:var(--amber);background:var(--amber-pale);border-color:#efdbb4}
    main{max-width:1280px;margin:auto;padding:38px 32px 56px}.intro{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;margin-bottom:25px}.eyebrow{color:var(--blue);font-size:11px;font-weight:750;letter-spacing:.14em;text-transform:uppercase;margin:0 0 7px}h1,h2,h3,p{margin-top:0}h1{font-size:32px;line-height:1.18;letter-spacing:-.035em;color:var(--ink);margin-bottom:10px;font-weight:750}h2{font-size:20px;line-height:1.3;letter-spacing:-.025em;color:var(--ink);margin-bottom:8px}h3{font-size:14px;color:var(--ink);margin-bottom:8px}.intro p{margin-bottom:0;max-width:720px;color:var(--muted)}.quick-links{display:flex;gap:16px;font-size:12px;white-space:nowrap}.status-grid{display:grid;grid-template-columns:1.6fr 1fr 1fr;gap:1px;background:var(--line);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;margin-bottom:28px}.stat{padding:15px 20px;background:#fff;min-width:0}.stat-label{font-size:10px;letter-spacing:.08em;text-transform:uppercase;font-weight:700;color:var(--muted);display:block;margin-bottom:5px}.stat-value{font-size:13px;font-weight:650;color:var(--ink);overflow-wrap:anywhere}.stat-detail{font-size:11px;color:var(--muted);display:block;margin-top:2px}.path{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;word-break:break-word}
    .notice{padding:15px 18px;margin:0 0 22px;border:1px solid var(--line);border-radius:10px;background:#fff;overflow-wrap:anywhere}.notice.error{color:var(--red);background:var(--red-pale);border-color:#f1cbd0}.notice.success{color:var(--green);background:var(--green-pale);border-color:#c7e2d4}.notice.info{background:var(--pale);border-color:#d5e2ed}.notice.warning{color:var(--amber);background:var(--amber-pale);border-color:#efdbb4}.notice strong{display:block;margin-bottom:3px}.notice p:last-child{margin-bottom:0}.notice.compact{font-size:12px;padding:12px 14px;margin-top:16px;margin-bottom:0}
    .grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px;align-items:start}.stack{display:grid;gap:24px;min-width:0}.card{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;min-width:0;box-shadow:0 4px 20px #14283e03}.card-pad{padding:25px}.card-top{display:flex;justify-content:space-between;align-items:start;gap:16px;margin-bottom:20px}.card-top h2{margin-bottom:5px}.card-top p{font-size:12px;color:var(--muted);margin:0}.step{font-size:11px;color:var(--blue);font-weight:750;background:var(--pale);width:29px;height:29px;display:grid;place-items:center;border-radius:8px;flex:none}.card-heading{display:flex;align-items:start;gap:12px}.card-bottom{padding:14px 25px;background:#fbfcfd;border-top:1px solid var(--line);font-size:11px;color:var(--muted)}.field{margin-bottom:16px}.field:last-child{margin-bottom:0}label{display:block;font-size:12px;font-weight:650;color:var(--ink);margin-bottom:7px}.input{width:100%;border:1px solid #cbd8e2;background:#fff;border-radius:8px;color:var(--ink);padding:10px 12px;min-height:43px}.input::placeholder{color:#91a1af}.input.path{font-size:12px}.field-row{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:14px}.help{display:block;color:var(--muted);font-size:11px;line-height:1.6;margin-top:6px}.check{display:flex;gap:10px;align-items:start;margin:12px 0;font-size:12px;font-weight:450;line-height:1.5}.check input{width:16px;height:16px;accent-color:var(--blue);margin:2px 0 0;flex:none}.check strong{font-weight:650}.check span{display:block}.check small{display:block;color:var(--muted);font-size:11px;margin-top:3px}.check-box{padding:3px 14px;background:#f8fafc;border:1px solid var(--line);border-radius:9px;margin:17px 0}.button{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;padding:10px 17px;border:1px solid var(--blue);border-radius:8px;background:var(--blue);color:#fff;font-size:12px;font-weight:650;text-decoration:none}.button:hover{background:var(--blue-dark);border-color:var(--blue-dark);color:#fff}.button.secondary{color:var(--blue);background:#fff;border-color:#bdcedd}.button.secondary:hover{background:var(--pale)}.button.ghost{border-color:transparent;background:transparent;color:var(--muted);padding:7px 10px;min-height:34px}.button.ghost:hover{color:var(--ink);background:var(--pale)}.button.danger{color:var(--red);border-color:#eccbd0;background:#fff}.button.danger:hover{background:var(--red-pale)}.button.small{font-size:11px;padding:6px 10px;min-height:32px}.button.full{width:100%}.actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.actions .help{margin:0}.upload-zone{padding:18px;background:#f8fafc;border:1px dashed #b9cbd9;border-radius:9px;margin-bottom:16px}.upload-zone .input{background:#fff;font-size:12px}.upload-zone label{margin-bottom:9px}.section{margin-top:28px}.section-head{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:16px}.section-head h2{margin-bottom:3px}.section-head p{color:var(--muted);font-size:12px;margin:0}.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}table{width:100%;border-collapse:collapse;font-size:12px}th{text-align:left;font-size:10px;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);background:#f8fafc;font-weight:700;padding:11px 19px;border-bottom:1px solid var(--line)}td{padding:11px 19px;border-bottom:1px solid #edf1f5;vertical-align:middle}tr:last-child td{border-bottom:0}tbody tr:hover{background:#fbfcfd}.name-cell{max-width:520px;overflow-wrap:anywhere}.name-cell a{text-decoration:none;font-weight:600}.name-cell a:hover{text-decoration:underline}.kind{display:inline-block;width:36px;font-size:9px;font-weight:750;color:var(--muted);letter-spacing:.03em;vertical-align:middle}.right{text-align:right;white-space:nowrap}.muted{color:var(--muted)}.inline-form{display:inline}.table-actions{display:flex;gap:7px;justify-content:flex-end;flex-wrap:wrap}.empty{text-align:center;color:var(--muted);padding:30px 20px;font-size:12px}.file-meta{font-size:10px;display:block;color:var(--muted);margin-top:3px}.browse-header{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:18px 22px;border-bottom:1px solid var(--line)}.browse-header .path{font-size:12px;color:var(--ink)}.summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;padding:18px 22px;border-bottom:1px solid var(--line)}.summary strong{font-size:18px;display:block;letter-spacing:-.03em;color:var(--ink)}.summary span{font-size:10px;color:var(--muted)}.inspection-scroll{max-height:390px;overflow:auto}.inspection-scroll th{position:sticky;top:0}.inspection-file{word-break:break-word;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:11px}.details{margin-top:28px;padding:22px 25px}.details summary{cursor:pointer;font-size:13px;color:var(--ink);font-weight:650}.details-body{display:grid;grid-template-columns:1fr 1fr;gap:28px;padding-top:20px}.details-body p{font-size:12px;color:var(--muted);margin-bottom:12px}.details-body ul{padding-left:18px;color:var(--muted);font-size:12px;margin:0}.details-body li{margin:6px 0}.footer{color:var(--muted);font-size:11px;text-align:center;padding:25px 0 0}.footer p{margin:0}.busy{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:10;max-width:calc(100% - 32px);padding:15px 20px;border-radius:12px;background:var(--ink);color:#fff;box-shadow:0 8px 28px #12233626;display:flex;align-items:center;gap:14px;font-size:12px}.busy strong{display:block}.busy span{display:block;color:#cad9e5;font-size:11px}.spinner{width:19px;height:19px;border:2px solid #ffffff40;border-top-color:#fff;border-radius:50%;animation:turn 1s linear infinite;flex:none}@keyframes turn{to{transform:rotate(360deg)}}
    .auth-shell{max-width:540px;margin:25px auto 45px}.auth-intro{text-align:center;margin-bottom:25px}.auth-intro h1{font-size:29px;margin-bottom:12px}.auth-intro p{font-size:13px;color:var(--muted);margin-bottom:0}.auth-card{padding:28px}.auth-foot{font-size:11px;color:var(--muted);text-align:center;margin:19px 0 0}.setup-steps{margin:0 0 22px;padding-left:20px;font-size:12px;color:var(--muted)}.setup-steps li{padding:4px 0 4px 3px}.code-block{display:block;padding:15px;background:var(--ink);color:#e5f0fa;border-radius:8px;overflow-wrap:anywhere;white-space:pre-wrap;font:11px/1.7 ui-monospace,SFMono-Regular,Consolas,monospace;user-select:all}.no-margin{margin:0}.break{overflow-wrap:anywhere}.sr-only{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
    @media(max-width:900px){.top-inner{padding:17px 23px}main{padding:28px 23px 42px}.grid{gap:18px}.stack{gap:18px}.card-pad{padding:20px}.quick-links{display:none}.card-bottom{padding:13px 20px}.field-row{grid-template-columns:1fr;margin-bottom:16px}.field-row .field:first-child{margin-bottom:0}.top-actions>.badge{display:none}.status-grid{grid-template-columns:1.4fr 1fr 1fr}.stat{padding:13px 15px}}
    @media(max-width:680px){main{padding:26px 16px 35px}.top-inner{padding:15px 16px}.brand-mark{width:37px;height:37px}.brand-name{font-size:15px}.brand-sub{font-size:10px}.top-actions{gap:0}.grid{grid-template-columns:1fr}.intro{margin-bottom:22px}h1{font-size:27px}.status-grid{grid-template-columns:1fr 1fr}.stat:first-child{grid-column:1/-1}.stat-value{font-size:12px}.card-pad{padding:20px}.summary{gap:10px;padding:15px}.summary strong{font-size:16px}.summary span{font-size:9px}.details-body{grid-template-columns:1fr;gap:6px}.details{padding:20px}.browse-header{padding:15px;flex-wrap:wrap}th,td{padding:11px 13px}.hide-mobile{display:none}.section-head{align-items:start}.auth-shell{margin:14px auto 30px}.auth-card{padding:23px}.auth-intro h1{font-size:26px}.name-cell{min-width:135px}.artifact-table .table-actions{min-width:110px}.busy{bottom:16px;width:calc(100% - 32px)}.table-actions .button{font-size:10px;padding:5px 8px}}
    @media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}.spinner{animation:none}}
  </style>
</head>
<body>
<header class="topbar">
  <div class="top-inner">
    <a class="brand" href="<?= am_h((string) $v['url']) ?>" aria-label="Archive Manager, accueil">
      <span class="brand-mark" aria-hidden="true">ZIP</span>
      <span class="brand-name">Archive Manager<span class="brand-sub">Vos fichiers, sur votre serveur</span></span>
    </a>
    <div class="top-actions">
      <?php if ($dashboard): ?>
        <span class="badge safe">Session authentifiée</span>
        <form action="<?= am_h((string) $v['url']) ?>" method="post">
          <?php $token('logout'); ?><button class="button ghost" type="submit">Déconnexion</button>
        </form>
      <?php else: ?>
        <span class="badge">Administration privée</span>
      <?php endif; ?>
    </div>
  </div>
</header>
<main>
<?php if (!empty($v['error'])): ?>
  <div class="notice error" role="alert"><strong>Opération non terminée</strong><?= am_h((string) $v['error']) ?></div>
<?php endif; ?>
<?php if (!empty($v['success'])): ?>
  <div class="notice success" role="status"><strong>Opération terminée</strong><?= am_h((string) $v['success']) ?></div>
<?php endif; ?>

<?php if ($view === 'setup'): ?>
  <div class="auth-shell">
    <div class="auth-intro">
      <p class="eyebrow">Première installation</p>
      <h1>Protégez votre accès.</h1>
      <p>L’outil est verrouillé tant qu’aucune empreinte de mot de passe n’est configurée dans le fichier PHP.</p>
    </div>
    <section class="card auth-card" aria-labelledby="setup-title">
      <h2 id="setup-title">Configurer le mot de passe</h2>
      <ol class="setup-steps">
        <li>Choisissez un mot de passe personnel de 14 à 72 octets.</li>
        <li>Générez son empreinte avec le formulaire ci-dessous.</li>
        <li>Copiez la ligne obtenue à la place de <code>AM_PASSWORD_HASH</code>, en haut du fichier PHP, puis renvoyez ce fichier sur votre serveur.</li>
      </ol>
      <form action="<?= am_h((string) $v['url']) ?>" method="post" data-busy="Génération de l’empreinte">
        <?php $token('hash_password'); ?>
        <div class="field">
          <label for="new-password">Votre nouveau mot de passe</label>
          <input class="input" type="password" id="new-password" name="password" minlength="14" maxlength="72" autocomplete="new-password" required>
          <span class="help">Choisissez un mot de passe unique. La longueur est contrôlée en octets côté serveur.</span>
        </div>
        <button class="button full" type="submit">Générer l’empreinte</button>
      </form>
      <?php if (!empty($v['hash'])): ?>
        <div class="notice info compact">
          <strong>Copiez cette ligne dans le fichier PHP</strong>
          <code class="code-block"><?= am_h("const AM_PASSWORD_HASH = '" . (string) $v['hash'] . "';") ?></code>
          <p>Conservez votre mot de passe pour vous connecter. Il n’est pas enregistré en clair dans le fichier PHP.</p>
        </div>
      <?php endif; ?>
      <div class="notice compact info">Ce formulaire calcule une empreinte. Il ne crée aucun compte et ne modifie pas la configuration de votre serveur.</div>
    </section>
    <p class="auth-foot">Votre mot de passe reste sur ce serveur. Aucun service externe n’est utilisé.</p>
  </div>
<?php elseif ($view === 'login'): ?>
  <div class="auth-shell">
    <div class="auth-intro">
      <p class="eyebrow">Accès administrateur</p>
      <h1>Retrouvez vos archives.</h1>
      <p>Connectez-vous pour créer, inspecter et extraire des ZIP dans le répertoire autorisé.</p>
    </div>
    <section class="card auth-card" aria-labelledby="login-title">
      <h2 id="login-title">Connexion</h2>
      <form action="<?= am_h((string) $v['url']) ?>" method="post" data-busy="Vérification de votre accès">
        <?php $token('login'); ?>
        <div class="field">
          <label for="password">Mot de passe administrateur</label>
          <input class="input" type="password" id="password" name="password" maxlength="72" autocomplete="current-password" required autofocus>
        </div>
        <button class="button full" type="submit">Se connecter</button>
      </form>
      <p class="help">Les essais de connexion sont limités. La session expire automatiquement après une période d’inactivité.</p>
    </section>
    <p class="auth-foot">Aucun fichier du serveur n’est accessible depuis cette page avant authentification.</p>
  </div>
<?php elseif ($dashboard): ?>
  <div class="intro">
    <div><p class="eyebrow">Espace de travail</p><h1>Vos archives, sous contrôle.</h1><p>Créez un ZIP, vérifiez son contenu et extrayez vos fichiers dans un chemin autorisé.</p></div>
    <nav class="quick-links" aria-label="Accès rapide"><a href="#operations">Opérations</a><a href="#files">Fichiers</a><a href="#archives">Archives privées</a></nav>
  </div>
  <div class="status-grid" aria-label="Configuration active">
    <div class="stat"><span class="stat-label">Répertoire autorisé</span><span class="stat-value path"><?= am_h((string) $v['root']) ?></span><span class="stat-detail">Les chemins saisis sont relatifs à ce répertoire.</span></div>
    <div class="stat"><span class="stat-label">Limite par opération</span><span class="stat-value"><?= am_h(am_bytes((int) $v['limit_total'])) ?> · <?= am_h(number_format((int) $v['limit_entries'], 0, ',', ' ')) ?> entrées</span><span class="stat-detail"><?= am_h(am_bytes((int) $v['limit_file'])) ?> maximum par fichier.</span></div>
    <div class="stat"><span class="stat-label">Environnement</span><span class="stat-value"><?= am_h((string) $v['runtime']) ?></span><span class="stat-detail">Extension ZIP <?= !empty($v['zip_available']) ? 'disponible' : 'indisponible' ?>.</span></div>
  </div>

  <?php if (empty($v['zip_available'])): ?>
    <div class="notice error"><strong>L’extension PHP ZIP est nécessaire</strong>Demandez à votre hébergeur d’activer l’extension <code>zip</code> pour votre version de PHP.</div>
  <?php endif; ?>

  <div class="grid" id="operations">
    <div class="stack">
      <section class="card" aria-labelledby="create-title">
        <div class="card-pad">
          <div class="card-top"><div class="card-heading"><span class="step">01</span><div><h2 id="create-title">Créer un ZIP</h2><p>Archivez un fichier ou un dossier, avec ses sous-dossiers.</p></div></div></div>
          <form action="<?= am_h((string) $v['url']) ?>" method="post" data-busy="Création du ZIP en cours">
            <?php $token('create'); ?>
            <div class="field"><label for="source">Fichier ou dossier source</label><input class="input path" id="source" name="source" value="<?= am_h((string) ($defaults['source'] ?? '.')) ?>" placeholder=". ou wp-content/uploads" required><span class="help"><code>.</code> désigne tout le répertoire autorisé. Vous pouvez aussi sélectionner une source dans la liste des fichiers.</span></div>
            <div class="field-row">
              <div class="field"><label for="zip-name">Nom du ZIP</label><input class="input path" id="zip-name" name="name" value="<?= am_h($newName) ?>" maxlength="150" placeholder="sauvegarde.zip"><span class="help">Nom du fichier téléchargé, sans chemin.</span></div>
              <div class="field"><label for="compression">Compression</label><select class="input" id="compression" name="compression"><option value="6" selected>Équilibrée</option><option value="0">Sans compression</option><option value="9">Maximale</option></select></div>
            </div>
            <div class="field"><label for="storage">Emplacement du ZIP</label><select class="input" id="storage" name="storage"><option value="private">Espace privé (recommandé, téléchargement)</option><option value="server">Dossier du serveur</option></select></div>
            <div class="field"><label for="zip-destination">Dossier de destination sur le serveur</label><input class="input path" id="zip-destination" name="zip_destination" value="." placeholder=". ou sauvegardes"><span class="help">Pour le mode serveur uniquement : . = racine autorisée ; sinon chemin relatif vers un dossier existant.</span></div>
            <div class="field"><label><input type="checkbox" name="public_confirm" value="1"> Je comprends que le ZIP enregistré dans un dossier web peut être accessible publiquement, y compris ses fichiers de configuration.</label></div>
            <button class="button" type="submit" <?= empty($v['zip_available']) ? 'disabled' : '' ?>>Créer l’archive</button>
          </form>
        </div>
        <div class="card-bottom">Le mode privé conserve le ZIP hors du dossier public. Le mode serveur enregistre le ZIP dans le dossier choisi, sans écrasement. Le gestionnaire lui-même, les dossiers Git et les liens symboliques sont exclus.</div>
      </section>

      <section class="card" aria-labelledby="upload-title">
        <div class="card-pad">
          <div class="card-top"><div><h2 id="upload-title">Importer un ZIP</h2><p>Envoyez une archive depuis votre ordinateur.</p></div><span class="badge">Facultatif</span></div>
          <form action="<?= am_h((string) $v['url']) ?>" method="post" enctype="multipart/form-data" data-busy="Envoi et vérification du ZIP">
            <?php $token('upload'); ?>
            <div class="upload-zone"><label for="archive-file">Archive à importer</label><input class="input" type="file" id="archive-file" name="archive_file" accept=".zip,application/zip" required><span class="help">Limite d’envoi annoncée par le serveur : <?= am_h((string) $v['upload_limit']) ?>. Le serveur peut appliquer des limites supplémentaires.</span></div>
            <button class="button secondary" type="submit" <?= empty($v['zip_available']) ? 'disabled' : '' ?>>Importer et vérifier</button>
          </form>
        </div>
        <div class="card-bottom">L’import conserve l’archive en zone privée. Aucun fichier n’est extrait automatiquement.</div>
      </section>
    </div>
    <div class="stack">
      <section class="card" aria-labelledby="inspect-title">
        <div class="card-pad">
          <div class="card-top"><div class="card-heading"><span class="step">02</span><div><h2 id="inspect-title">Inspecter un ZIP</h2><p>Vérifiez les chemins, les tailles et les types de fichiers.</p></div></div></div>
          <form action="<?= am_h((string) $v['url']) ?>" method="post" data-busy="Inspection de l’archive">
            <?php $token('inspect'); ?>
            <div class="field"><label for="inspect-archive">Archive à vérifier</label><input class="input path" id="inspect-archive" name="archive" value="<?= am_h($selectedArchive) ?>" placeholder="mon-site.zip" required><span class="help">Chemin relatif sur le serveur, ou référence <code>@identifiant</code> d’une archive privée. Les boutons ci-dessous remplissent ce champ.</span></div>
            <button class="button secondary" type="submit" <?= empty($v['zip_available']) ? 'disabled' : '' ?>>Voir le contenu</button>
          </form>
        </div>
      </section>

      <section class="card" aria-labelledby="extract-title">
        <div class="card-pad">
          <div class="card-top"><div class="card-heading"><span class="step">03</span><div><h2 id="extract-title">Extraire un ZIP</h2><p>Les fichiers existants sont toujours préservés.</p></div></div></div>
          <form action="<?= am_h((string) $v['url']) ?>" method="post" data-busy="Vérification puis extraction du ZIP">
            <?php $token('extract'); ?>
            <div class="field"><label for="extract-archive">Archive source</label><input class="input path" id="extract-archive" name="archive" value="<?= am_h($selectedArchive) ?>" placeholder="mon-site.zip" required></div>
            <div class="field"><label for="destination">Dossier de destination</label><input class="input path" id="destination" name="destination" value="<?= am_h((string) ($defaults['destination'] ?? 'extraction-' . date('Y-m-d-His'))) ?>" placeholder="extraction" required><span class="help">Un nouveau dossier est recommandé. Aucun chemin absolu ni <code>../</code> n’est accepté.</span></div>
            <div class="check-box">
              <label class="check"><input type="checkbox" name="allow_code" value="1"><span><strong>Autoriser les fichiers de site et de configuration</strong><small>Nécessaire pour WordPress, PHP ou <code>.htaccess</code>. À cocher uniquement pour une archive dont vous connaissez et acceptez le contenu.</small></span></label>
              <label class="check"><input type="checkbox" name="merge" value="1"><span><strong>Utiliser un dossier déjà existant</strong><small>Sans écrasement : si un fichier de destination existe déjà, l’opération est refusée avant la copie.</small></span></label>
            </div>
            <button class="button" type="submit" <?= empty($v['zip_available']) ? 'disabled' : '' ?>>Vérifier et extraire</button>
            <span class="help">L’archive est vérifiée à nouveau au moment de l’extraction.</span>
          </form>
        </div>
        <div class="card-bottom">Sans autorisation de code, seuls les formats de données admis sont extraits. Ce filtre d’extensions n’est pas un antivirus.</div>
      </section>
    </div>
  </div>

  <?php if (is_array($inspection)): ?>
    <section class="section" id="inspection" aria-labelledby="inspection-title">
      <div class="section-head"><div><h2 id="inspection-title">Contenu de l’archive</h2><p class="path"><?= am_h((string) $inspection['archive']) ?></p></div><span class="badge">Inspection</span></div>
      <div class="card">
        <div class="summary"><div><strong><?= am_h(number_format((int) $inspection['files'], 0, ',', ' ')) ?></strong><span>Fichiers</span></div><div><strong><?= am_h(number_format((int) $inspection['dirs'], 0, ',', ' ')) ?></strong><span>Dossiers déclarés</span></div><div><strong><?= am_h(am_bytes((int) $inspection['bytes'])) ?></strong><span>Après extraction</span></div><div><strong><?= am_h(number_format((float) ($inspection['ratio'] ?? 0), 1, ',', ' ')) ?>×</strong><span>Ratio de compression</span></div></div>
        <?php if ((int) ($inspection['code_count'] ?? 0) > 0): ?>
          <div class="card-pad"><div class="notice warning no-margin"><strong><?= am_h((string) $inspection['code_count']) ?> fichier(s) hors du mode données</strong>Pour extraire cette archive de site, cochez l’autorisation des fichiers de site et de configuration. Cette autorisation permet aussi l’installation de code exécutable par votre serveur.</div></div>
        <?php endif; ?>
        <div class="table-wrap inspection-scroll"><table><thead><tr><th>Chemin dans le ZIP</th><th>Type</th><th class="right">Taille</th></tr></thead><tbody>
          <?php foreach (($inspection['entries'] ?? []) as $entry): ?>
            <tr><td class="inspection-file"><?= am_h((string) $entry['path']) ?></td><td><?php if (!empty($entry['dir'])): ?><span class="badge small">Dossier</span><?php elseif (empty($entry['data'])): ?><span class="badge small code">Code / autre</span><?php else: ?><span class="badge small">Données</span><?php endif; ?></td><td class="right muted"><?= !empty($entry['dir']) ? '—' : am_h(am_bytes((int) $entry['size'])) ?></td></tr>
          <?php endforeach; ?>
          <?php if (empty($inspection['entries'])): ?><tr><td class="empty" colspan="3">Cette archive ne contient aucune entrée.</td></tr><?php endif; ?>
        </tbody></table></div>
        <div class="card-bottom">Aperçu limité aux 120 premières entrées. L’inspection des métadonnées porte sur l’archive entière ; l’intégrité des contenus est vérifiée pendant l’extraction.</div>
      </div>
    </section>
  <?php endif; ?>

  <section class="section" id="files" aria-labelledby="files-title">
    <div class="section-head"><div><h2 id="files-title">Fichiers du serveur</h2><p>Parcourez les dossiers pour choisir une source ou une archive ZIP.</p></div></div>
    <div class="card">
      <div class="browse-header"><span class="path">/<?= am_h(($v['browse'] ?? '.') === '.' ? '' : (string) $v['browse']) ?></span><div class="actions"><?php if (($v['parent'] ?? null) !== null): ?><a class="button secondary small" href="<?= am_h($link(['dir' => (string) $v['parent']])) ?>#files">Dossier parent</a><?php endif; ?><a class="button secondary small" href="<?= am_h($link(['dir' => (string) ($v['browse'] ?? '.'), 'source' => (string) (($v['browse'] ?? '') ?: '.')])) ?>#operations">Zipper ce dossier</a></div></div>
      <div class="table-wrap"><table><thead><tr><th>Nom</th><th class="right hide-mobile">Taille</th><th class="right">Utiliser</th></tr></thead><tbody>
        <?php foreach (($v['entries'] ?? []) as $entry): ?>
          <tr><td class="name-cell"><span class="kind" aria-hidden="true"><?= !empty($entry['dir']) ? 'DIR' : (!empty($entry['zip']) ? 'ZIP' : 'FILE') ?></span><?php if (!empty($entry['dir'])): ?><a href="<?= am_h($link(['dir' => (string) $entry['path']])) ?>#files"><?= am_h((string) $entry['name']) ?>/</a><?php else: ?><span><?= am_h((string) $entry['name']) ?></span><?php endif; ?></td><td class="right muted hide-mobile"><?= !empty($entry['dir']) ? '—' : am_h(am_bytes((int) ($entry['size'] ?? 0))) ?></td><td class="right"><div class="table-actions"><a class="button secondary small" href="<?= am_h($link(['dir' => (string) ($v['browse'] ?? '.'), 'source' => (string) $entry['path']])) ?>#operations">Zipper</a><?php if (!empty($entry['zip'])): ?><a class="button secondary small" href="<?= am_h($link(['dir' => (string) ($v['browse'] ?? '.'), 'archive' => (string) $entry['path']])) ?>#operations">Choisir le ZIP</a><?php endif; ?></div></td></tr>
        <?php endforeach; ?>
        <?php if (empty($v['entries'])): ?><tr><td class="empty" colspan="3">Aucun élément accessible dans ce dossier.</td></tr><?php endif; ?>
      </tbody></table></div>
      <div class="card-bottom">Affichage limité à 250 éléments par dossier. Les liens symboliques et les éléments réservés au gestionnaire ne sont pas proposés.</div>
    </div>
  </section>

  <section class="section" id="archives" aria-labelledby="archives-title">
    <div class="section-head"><div><h2 id="archives-title">Archives privées</h2><p>Les ZIP créés ou importés sont accessibles uniquement après connexion.</p></div><span class="badge"><?= am_h((string) count($v['artifacts'] ?? [])) ?> archive(s)</span></div>
    <div class="card"><div class="table-wrap"><table class="artifact-table"><thead><tr><th>Archive</th><th class="right hide-mobile">Taille</th><th class="right">Actions</th></tr></thead><tbody>
      <?php foreach (($v['artifacts'] ?? []) as $artifact): ?>
        <tr><td class="name-cell"><strong><?= am_h((string) $artifact['name']) ?></strong><?php if (!empty($artifact['recovered'])): ?> <span class="badge small code">Archive récupérée</span><?php endif; ?><span class="file-meta"><?php $created = $artifact['created'] ?? ''; ?><?= am_h(is_numeric($created) ? date('d/m/Y à H:i', (int) $created) : (string) $created) ?></span></td><td class="right muted hide-mobile"><?= am_h(am_bytes((int) $artifact['size'])) ?></td><td class="right"><div class="table-actions">
          <form class="inline-form" action="<?= am_h((string) $v['url']) ?>" method="post"><?php $token('download'); ?><input type="hidden" name="id" value="<?= am_h((string) $artifact['id']) ?>"><button class="button secondary small" type="submit">Télécharger</button></form>
          <form class="inline-form" action="<?= am_h((string) $v['url']) ?>" method="post" data-busy="Inspection de l’archive"><?php $token('inspect'); ?><input type="hidden" name="archive" value="<?= am_h('@' . (string) $artifact['id']) ?>"><button class="button secondary small" type="submit">Inspecter</button></form>
          <form class="inline-form" action="<?= am_h((string) $v['url']) ?>" method="post" data-confirm="Supprimer définitivement cette archive privée ? Les fichiers source du serveur seront conservés."><?php $token('delete'); ?><input type="hidden" name="id" value="<?= am_h((string) $artifact['id']) ?>"><button class="button danger small" type="submit">Supprimer</button></form>
        </div></td></tr>
      <?php endforeach; ?>
      <?php if (empty($v['artifacts'])): ?><tr><td class="empty" colspan="3">Vos prochaines archives apparaîtront ici après création ou import.</td></tr><?php endif; ?>
    </tbody></table></div><div class="card-bottom">« Supprimer » efface uniquement le ZIP privé sélectionné. Téléchargez les sauvegardes à conserver durablement : le nettoyage des dossiers temporaires dépend de l’hébergement.</div></div>
  </section>

  <details class="card details">
    <summary>Fonctionnement, limites et bonnes pratiques</summary>
    <div class="details-body">
      <div><h3>Des opérations délimitées</h3><ul><li>Format ZIP uniquement, sans commande système ni service externe.</li><li>Contrôle des chemins, des liens, des tailles et du nombre d’entrées.</li><li>Fichiers existants préservés : aucune option d’écrasement.</li><li>Le gestionnaire ne lance pas les scripts contenus dans vos archives.</li><li>Le filtre de formats ne constitue pas une analyse antivirus.</li></ul></div>
      <div><h3>À garder en tête</h3><p>Budget applicatif : <?= am_h((string) $v['budget_seconds']) ?> secondes par opération. Les limites PHP, du serveur web ou de l’hébergeur peuvent interrompre un traitement plus tôt. Une archive volumineuse nécessite de la place pour les fichiers temporaires et la destination.</p><p>Une archive de fichiers ne remplace pas une sauvegarde de base de données. Pour un site actif, prévoyez séparément une sauvegarde cohérente de la base.</p><p>Après votre intervention, déconnectez-vous et retirez le gestionnaire du serveur si vous n’en avez plus besoin.</p></div>
    </div>
  </details>
<?php else: ?>
  <div class="auth-shell"><section class="card auth-card"><p class="eyebrow">Configuration requise</p><h1>L’outil reste verrouillé.</h1><p class="muted">Corrigez le point indiqué ci-dessus, puis rechargez la page. Aucune opération d’archivage n’a été lancée.</p><a class="button secondary" href="<?= am_h((string) $v['url']) ?>">Recharger</a></section></div>
<?php endif; ?>

<footer class="footer"><p>Archive Manager · Outil d’administration local · ZIP uniquement</p><br>© 2026 <a href="https://portfolio.mohiedineabassi.workers.dev/" target="_blank">ABASSI Mohieddine</a></footer>
</main>
<div id="busy-indicator" class="busy" role="status" aria-live="polite" hidden><div class="spinner" aria-hidden="true"></div><div><strong id="busy-title">Traitement en cours</strong><span>Patientez jusqu’au résultat. Ne fermez pas cette page.</span></div></div>
<script nonce="<?= am_h((string) $v['nonce']) ?>">
(() => {
  'use strict';
  const indicator = document.getElementById('busy-indicator');
  const title = document.getElementById('busy-title');
  document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', event => {
      if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
        return;
      }
      if (!form.dataset.busy) return;
      if (form.dataset.submitting === '1') {
        event.preventDefault();
        return;
      }
      form.dataset.submitting = '1';
      title.textContent = form.dataset.busy;
      indicator.hidden = false;
      form.querySelectorAll('button[type="submit"]').forEach(button => {
        button.disabled = true;
      });
    });
  });
  window.addEventListener('pageshow', () => {
    indicator.hidden = true;
    document.querySelectorAll('form[data-submitting="1"]').forEach(form => {
      delete form.dataset.submitting;
      form.querySelectorAll('button[type="submit"]').forEach(button => {
        button.disabled = false;
      });
    });
  });
})();
</script>
</body>
</html>
<?php
}

am_main();
