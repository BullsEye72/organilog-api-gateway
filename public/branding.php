<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    exit;
}
try {
    if (!is_file(ORGANILOG_CONFIG_FILE)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['configured' => false, 'setup_available' => is_file(__DIR__ . '/setup.php')]);
        exit;
    }
    $branding = organilog_config()['branding'];
    if (isset($_GET['logo'])) {
        $name = $branding['logo'] ?? null;
        if ($name === null || !is_file(ORGANILOG_VAR . '/' . $name)) {
            http_response_code(404);
            exit;
        }
        $type = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][pathinfo($name, PATHINFO_EXTENSION)];
        header('Content-Type: ' . $type);
        readfile(ORGANILOG_VAR . '/' . $name);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['configured' => true, 'name' => $branding['name'], 'logo_url' => empty($branding['logo']) ? null : '/branding.php?logo=1']);
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"configured":true,"error":"Configuration needs attention."}';
}
