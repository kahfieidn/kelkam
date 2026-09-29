<?php
/**
 * deploy-extract.php
 * Automated archive extractor for Next.js cPanel deployment
 */

declare(strict_types=1);

// Cegah caching respons
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Content-Type: application/json');

// 1. Verifikasi Keamanan Token
$expected_token = 'DEPLOY_TOKEN_PLACEHOLDER';
$received_token = $_GET['token'] ?? $_POST['token'] ?? $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';

// Fallback: baca token dari .env server jika placeholder belum di-replace
if ($expected_token === 'DEPLOY_TOKEN_PLACEHOLDER') {
    $env_file = __DIR__ . '/.env';
    if (file_exists($env_file)) {
        $env_content = file_get_contents($env_file);
        if (preg_match('/AUTH_JWT_SECRET=(.*)/', $env_content, $matches)) {
            $expected_token = trim($matches[1], " \t\n\r\0\x0B\"'");
        }
    }
}

if (empty($received_token) || !hash_equals($expected_token, $received_token)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Forbidden: Invalid or missing token.'
    ]);
    exit;
}

// Berikan waktu eksekusi cukup (300 detik)
@set_time_limit(300);

// 2. Cari file archive deploy.tar.gz
$search_paths = [
    __DIR__ . '/deploy.tar.gz',
    __DIR__ . '/keluhkampus.my.id/deploy.tar.gz',
    dirname(__DIR__) . '/keluhkampus.my.id/deploy.tar.gz',
    dirname(__DIR__) . '/deploy.tar.gz',
];

$archive_path = null;
$target_dir = __DIR__;

foreach ($search_paths as $path) {
    if (file_exists($path)) {
        $archive_path = $path;
        $target_dir = dirname($path);
        break;
    }
}

if (!$archive_path) {
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'message' => 'Archive deploy.tar.gz not found in any expected location.',
        'searched' => $search_paths
    ]);
    exit;
}

$start_time = microtime(true);
$extracted = false;
// 3. Bersihkan folder .next lama sebelum ekstrak agar file yang dihapus di proyek ikut bersih
$old_next = $target_dir . '/.next';
if (is_dir($old_next)) {
    if (function_exists('exec')) {
        @exec('rm -rf ' . escapeshellarg($old_next));
    }
}

// 4. Ekstrak archive menggunakan perintah sistem tar (Paling cepat di Linux)
if (function_exists('exec')) {
    $cmd = 'tar -xzf ' . escapeshellarg($archive_path) . ' -C ' . escapeshellarg($target_dir) . ' 2>&1';
    $output = [];
    $return_var = 0;
    exec($cmd, $output, $return_var);

    if ($return_var === 0) {
        $extracted = true;
        $method = 'system-tar';
    }
}

// Fallback jika exec gagal / dinonaktifkan di php.ini: gunakan PharData
if (!$extracted && class_exists('PharData')) {
    try {
        $phar = new PharData($archive_path);
        $phar->extractTo($target_dir, null, true); // true = overwrite
        $extracted = true;
        $method = 'PharData';
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Extraction failed with PharData: ' . $e->getMessage()
        ]);
        exit;
    }
}

if (!$extracted) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to extract archive. Neither system tar nor PharData succeeded.'
    ]);
    exit;
}

// 4. Sentuh tmp/restart.txt agar Phusion Passenger merestart aplikasi
$tmp_dir = $target_dir . '/tmp';
if (!is_dir($tmp_dir)) {
    @mkdir($tmp_dir, 0755, true);
}
@touch($tmp_dir . '/restart.txt');

// 5. Hapus file archive deploy.tar.gz untuk menghemat penyimpanan server
@unlink($archive_path);

$duration = round(microtime(true) - $start_time, 2);

http_response_code(200);
echo json_encode([
    'status' => 'success',
    'message' => 'Deployment package extracted and Passenger restarted successfully.',
    'method' => $method,
    'target_dir' => $target_dir,
    'duration_seconds' => $duration,
    'timestamp' => date('Y-m-d H:i:s')
]);
