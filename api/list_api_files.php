<?php
// Temporary diagnostic script — REMOVE after use.
// Lists PHP files in this directory and shows a few helpful diagnostics.
// Use in browser: https://your-domain/health_vet/api/list_api_files.php

header('Content-Type: application/json; charset=utf-8');

// Security: restrict to local requests or session users if you wish. Here we trust you (remove after debugging).
$dir = __DIR__;
$files = array_values(array_filter(scandir($dir), function($f){
    return is_file(__DIR__ . '/' . $f) && preg_match('/\.(php|html|json)$/i', $f);
}));

// Build public URL guess (best-effort)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$baseUrl = rtrim($scheme . '://' . $host . dirname($scriptPath), '/');

// gather basic php info helpful for debugging
$php_sapi = php_sapi_name();
$document_root = $_SERVER['DOCUMENT_ROOT'] ?? null;

$output = [
    'ok' => true,
    'api_dir' => $dir,
    'document_root' => $document_root,
    'php_sapi' => $php_sapi,
    'server_host' => $host,
    'base_url_guess' => $baseUrl,
    'files' => $files,
    'example_urls' => []
];

foreach ($files as $f) {
    $output['example_urls'][] = $baseUrl . '/' . $f;
}

echo json_encode($output, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);