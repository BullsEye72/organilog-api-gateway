<?php
// Local test server only. Never uses an Organilog account.
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/response/')) {
    require __DIR__ . '/responses.php';
    return;
}
if ($path === '/rate_limit') {
    http_response_code(429);
    header('Retry-After: 120');
} elseif ($path === '/retry_date') {
    http_response_code(503);
    header('Retry-After: ' . gmdate('D, d M Y H:i:s', time() + 120) . ' GMT');
} elseif ($path === '/server_error') {
    http_response_code(503);
} elseif ($path === '/bad_request') {
    http_response_code(422);
} elseif ($path === '/slow') {
    sleep(2);
}
echo json_encode(['fixture' => true]);
