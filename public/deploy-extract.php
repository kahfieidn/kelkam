<?php
/**
 * deploy-extract.php
 * Automated archive extractor for Next.js cPanel deployment.
 * Supports full file sync: extracts new files AND removes deleted files.
 */

declare(strict_types=1);

// Cegah caching respons
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Content-Type: application/json');

// ─── 1. Verifikasi Token Keamanan ────────────────────────────────────────────
$expected_token = 'DEPLOY_TOKEN_PLACEHOLDER';
$received_token = $_GET['token'] ?? $_POST['token'] ?? $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';

// Fallback: baca token dari .env server jika placeholder belum di-replace
if ($expected_token === 'DEPLOY_TOKEN_PLACEHOLDER') {
    $env_file = __DIR__ . '/.env';
    if (file_exists($env_file)) {
        $env_content = file_get_contents($env_file);
        if (preg_match('/AUTH_JWT_SECRET=(.+)/', $env_content, $matches)) {
            $expected_token = trim($matches[1], " \t\n\r\0\x0B\"'");
        }
    }
}

if (empty($received_token) || !hash_equals($expected_token, $received_token)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden: Invalid or missing token.']);
    exit;
}

// Beri waktu eksekusi cukup lama (600 detik)
@set_time_limit(600);
@ini_set('memory_limit', '512M');

$log     = [];
$start   = microtime(true);

// ─── 2. Tentukan target direktori & lokasi archive ───────────────────────────
$target_dir  = __DIR__;
$archive_path = $target_dir . '/deploy.tar.gz';

if (!file_exists($archive_path)) {
    http_response_code(404);
    echo json_encode([
        'status'   => 'error',
        'message'  => 'Archive deploy.tar.gz not found.',
        'searched' => $archive_path,
    ]);
    exit;
}

$log[] = "✔ Archive ditemukan: $archive_path";

// ─── 3. Hapus direktori build lama (.next) sebelum ekstraksi ─────────────────
//    Ini memastikan file lama di dalam .next terhapus sempurna
$dirs_to_clean = ['.next'];
foreach ($dirs_to_clean as $dir) {
    $full_path = $target_dir . '/' . $dir;
    if (is_dir($full_path)) {
        if (function_exists('exec')) {
            @exec('rm -rf ' . escapeshellarg($full_path), $out, $rc);
            $log[] = "→ Hapus direktori lama: $dir (exit=$rc)";
        } else {
            // Fallback: hapus rekursif via PHP
            deleteDirectory($full_path);
            $log[] = "→ Hapus direktori lama (PHP): $dir";
        }
    }
}

// ─── 4. Ekstrak archive ───────────────────────────────────────────────────────
$extracted = false;
$method    = 'unknown';

if (function_exists('exec')) {
    $cmd = 'tar -xzf ' . escapeshellarg($archive_path) . ' -C ' . escapeshellarg($target_dir) . ' 2>&1';
    $output = [];
    exec($cmd, $output, $rc);

    if ($rc === 0) {
        $extracted = true;
        $method    = 'system-tar';
        $log[]     = "✔ Ekstraksi berhasil via system tar.";
    } else {
        $log[] = "✘ system tar gagal (exit=$rc): " . implode(' | ', $output);
    }
}

// Fallback: gunakan PharData jika exec tidak tersedia
if (!$extracted && class_exists('PharData')) {
    try {
        $phar = new PharData($archive_path);
        $phar->extractTo($target_dir, null, true);
        $extracted = true;
        $method    = 'PharData';
        $log[]     = "✔ Ekstraksi berhasil via PharData.";
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Extraction failed: ' . $e->getMessage(),
            'log'     => $log,
        ]);
        exit;
    }
}

if (!$extracted) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Failed to extract: neither system tar nor PharData succeeded.',
        'log'     => $log,
    ]);
    exit;
}

// ─── 5. Full Sync: Hapus file di server yang sudah tidak ada di paket deploy ──
$manifest_path = $target_dir . '/deploy-manifest.txt';
$deleted_count = 0;

// File & direktori yang TIDAK boleh dihapus meskipun tidak ada di manifest
$protected = [
    'deploy-extract.php',   // Script ini sendiri
    'deploy-manifest.txt',  // Manifest yang baru di-extract
    'deploy.tar.gz',        // Archive (akan dihapus di step terpisah)
    '.htaccess',            // Konfigurasi web server
    'uploads',              // Folder uploads pengguna
    '.env',                 // Environment file
    'tmp',                  // Folder tmp Passenger
    '.git',                 // Git folder (jika ada)
];

if (file_exists($manifest_path)) {
    $manifest_files = array_filter(
        array_map('trim', file($manifest_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
    );
    $manifest_set = array_flip($manifest_files);

    // Scan semua file yang ada di target_dir
    $all_files = scanDirectoryRelative($target_dir, $target_dir);

    foreach ($all_files as $rel_path) {
        // Skip file yang dilindungi
        $top_level = explode('/', $rel_path)[0];
        if (in_array($top_level, $protected, true) || in_array($rel_path, $protected, true)) {
            continue;
        }

        // Jika file tidak ada di manifest → hapus
        if (!isset($manifest_set[$rel_path])) {
            $full = $target_dir . '/' . $rel_path;
            if (file_exists($full) && !is_dir($full)) {
                @unlink($full);
                $deleted_count++;
                $log[] = "→ Hapus file tidak terpakai: $rel_path";
            }
        }
    }

    // Hapus direktori kosong yang tersisa
    removeEmptyDirs($target_dir, $target_dir, $protected);

    $log[] = "✔ Full sync selesai. $deleted_count file dihapus dari server.";
} else {
    $log[] = "⚠ deploy-manifest.txt tidak ditemukan, melewati full sync.";
}

// ─── 6. Touch tmp/restart.txt agar Phusion Passenger restart ─────────────────
$tmp_dir = $target_dir . '/tmp';
if (!is_dir($tmp_dir)) {
    @mkdir($tmp_dir, 0755, true);
}
@touch($tmp_dir . '/restart.txt');
$log[] = "✔ Phusion Passenger restart.txt diperbarui.";

// ─── 7. Hapus archive untuk hemat storage ────────────────────────────────────
@unlink($archive_path);
$log[] = "✔ Archive deploy.tar.gz dihapus.";

// ─── 8. Respons sukses ────────────────────────────────────────────────────────
$duration = round(microtime(true) - $start, 2);

http_response_code(200);
echo json_encode([
    'status'           => 'success',
    'message'          => 'Deployment extracted and synced successfully.',
    'method'           => $method,
    'target_dir'       => $target_dir,
    'deleted_files'    => $deleted_count,
    'duration_seconds' => $duration,
    'timestamp'        => date('Y-m-d H:i:s'),
    'log'              => $log,
], JSON_PRETTY_PRINT);


// ─── Helper Functions ─────────────────────────────────────────────────────────

/**
 * Scan direktori secara rekursif, kembalikan daftar file relatif.
 */
function scanDirectoryRelative(string $dir, string $base): array
{
    $result = [];
    $items  = @scandir($dir);
    if (!$items) return $result;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = $dir . '/' . $item;
        $rel  = ltrim(str_replace($base, '', $full), '/');

        if (is_dir($full)) {
            $result = array_merge($result, scanDirectoryRelative($full, $base));
        } else {
            $result[] = $rel;
        }
    }
    return $result;
}

/**
 * Hapus direktori kosong secara rekursif (bottom-up).
 */
function removeEmptyDirs(string $dir, string $base, array $protected): void
{
    $items = @scandir($dir);
    if (!$items) return;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $full     = $dir . '/' . $item;
        $rel      = ltrim(str_replace($base, '', $full), '/');
        $top      = explode('/', $rel)[0];

        if (in_array($top, $protected, true)) continue;

        if (is_dir($full)) {
            removeEmptyDirs($full, $base, $protected);
            // Hapus jika kosong
            if (count(@scandir($full)) === 2) {
                @rmdir($full);
            }
        }
    }
}

/**
 * Hapus direktori beserta isinya secara rekursif (via PHP).
 */
function deleteDirectory(string $dir): void
{
    if (!is_dir($dir)) return;
    $items = @scandir($dir);
    if ($items) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $dir . '/' . $item;
            is_dir($full) ? deleteDirectory($full) : @unlink($full);
        }
    }
    @rmdir($dir);
}
