<?php
declare(strict_types=1);

// Development only: php -S 127.0.0.1:8080 -t public bin/router.php
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($path, ['/setup.php', '/branding.php'], true)) {
    $entry = __DIR__ . '/../public' . $path;
    if (is_file($entry)) {
        require $entry;
        return true;
    }
}
if (is_string($path) && preg_match('#^/v1(?:/|$)#', $path)) {
    require __DIR__ . '/../public/api.php';
    return true;
}
// Serve only known console assets, never arbitrary files or PHP source.
$assets = [
    '/' => ['index.html', 'text/html; charset=utf-8'],
    '/index.html' => ['index.html', 'text/html; charset=utf-8'],
    '/console.js' => ['console.js', 'text/javascript; charset=utf-8'],
    '/console.css' => ['console.css', 'text/css; charset=utf-8'],
    '/setup.css' => ['setup.css', 'text/css; charset=utf-8'],
];
if (isset($assets[$path ?? ''])) {
    [$file, $type] = $assets[$path];
    header('Content-Type: ' . $type);
    header('X-Content-Type-Options: nosniff');
    readfile(__DIR__ . '/../public/' . $file);
    return true;
}
http_response_code(404);
echo 'Not found';
return true;
