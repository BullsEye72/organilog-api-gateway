<?php
// Disposable local server. Only fabricated data; never loads application credentials.
header('Content-Type: application/json');
$parts = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
if ($parts[0] === 'response') array_shift($parts);
$scenario = $parts[0];
$stateFile = getenv('ORGANILOG_RESPONSE_FIXTURE_STATE');
$state = json_decode(file_get_contents($stateFile), true) ?: [];
$state[$scenario]['calls'] = ($state[$scenario]['calls'] ?? 0) + 1;
$action = basename(end($parts), '.php');
if ($action === 'read_multiple') $state[$scenario]['reads'] = ($state[$scenario]['reads'] ?? 0) + 1;
if ($action === 'update') $state[$scenario]['writes'] = ($state[$scenario]['writes'] ?? 0) + 1;
file_put_contents($stateFile, json_encode($state));

if (str_starts_with($scenario, 'verify_')) {
    $reads = $state[$scenario]['reads'] ?? 0;
    if ($action === 'read_multiple') {
        if ($scenario === 'verify_initial_invalid') { echo '{broken'; return; }
        if ($reads >= 2 && $scenario === 'verify_invalid') { echo '<html>not JSON</html>'; return; }
        if ($reads >= 2 && $scenario === 'verify_http_error') { http_response_code(503); echo '{}'; return; }
        if ($reads >= 2 && $scenario === 'verify_transport') { header('Content-Length: 4096'); echo '{'; return; }
        $row = ['id' => '123', 'name' => $reads === 1 ? 'before' : 'after', 'description' => 'keep'];
        if ($reads >= 2 && in_array($scenario, ['verify_restore_invalid', 'verify_restore_rejected'], true)) $row['description'] = '';
        echo json_encode(['results' => [$row]]);
        return;
    }
    if ($action === 'update' && $scenario === 'verify_write_invalid') { echo '{broken'; return; }
    if ($action === 'update' && $scenario === 'verify_write_rejected') { echo '{"ERROR":"Update rejected"}'; return; }
    if ($action === 'update' && ($state[$scenario]['writes'] ?? 0) === 2) {
        if ($scenario === 'verify_restore_rejected') { echo '{"ERROR":"Restoration rejected"}'; return; }
        echo '<html>unknown restoration outcome</html>';
        return;
    }
    echo '{"updated":true}';
    return;
}

switch ($scenario) {
    case 'object': echo '{"fixture":true}'; break;
    case 'list': echo '[{"id":"123"}]'; break;
    case 'empty_object': echo '{}'; break;
    case 'empty_list': echo '[]'; break;
    case 'application_error': echo '{"ERROR":"Invalid token"}'; break;
    case 'html': header('Content-Type: text/html'); echo '<html>not JSON</html>'; break;
    case 'malformed': echo '{broken'; break;
    case 'empty': break;
    case 'null': echo 'null'; break;
    case 'false': echo 'false'; break;
    case 'number': echo '42'; break;
    case 'string': echo '"not a document"'; break;
    case 'http_error': http_response_code(422); echo '{"error":"bad request"}'; break;
    case 'html_rate_limit': http_response_code(429); header('Retry-After: 120'); echo '<html>limited</html>'; break;
    case 'html_server_error': http_response_code(503); header('Retry-After: 90'); echo '<html>unavailable</html>'; break;
    default: http_response_code(404); echo '{}';
}
