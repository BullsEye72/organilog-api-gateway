<?php
declare(strict_types=1);

// Run only in the disposable apache-test Compose service. No ports or volumes are shared.
require __DIR__ . '/../src/bootstrap.php';
Organilog\Setup::assertRequirements();
if (getenv('ORGANILOG_CONFIG_FILE') !== '/var/www/organilog/var/config.php'
    || !is_file('/usr/local/bin/organilog-entrypoint')
    || file_exists(ORGANILOG_CONFIG_FILE) || file_exists(ORGANILOG_VAR . '/keys.json')) {
    throw new RuntimeException('Run this check in a fresh container: docker compose --profile test run --rm apache-test');
}

$checks = 0;
function verify(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function setup_as_web_user(array $args = []): array
{
    $env = array_merge(getenv(), [
        'ORGANILOG_SETUP_ACCOUNT' => 'offline-apache-fixture',
        'ORGANILOG_SETUP_TOKEN' => 'offline-fixture-not-a-real-token',
    ]);
    // Arguments are fixed by this test, never user-provided shell text.
    $command = 'php bin/setup.php' . ($args === ['--check'] ? ' --check' : '');
    $process = proc_open(
        ['su', '--shell', '/bin/sh', '--command', $command, 'www-data'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, ORGANILOG_ROOT, $env
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start setup as the Apache user.');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out, $err];
}

function http(string $path, ?string $key = null, string $method = 'GET', ?array $body = null, bool $tls = true): array
{
    $handle = curl_init(($tls ? 'https' : 'http') . '://127.0.0.1' . $path);
    $headers = $key === null ? [] : ['Authorization: Bearer ' . $key];
    if (!$tls) {
        $headers[] = 'Host: localhost:8443';
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false, // Disposable local self-signed certificate.
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_PROXY => '',
    ]);
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $redirect = curl_getinfo($handle, CURLINFO_REDIRECT_URL);
    curl_close($handle);
    return [$status, $raw === false ? null : json_decode($raw, true), $redirect, $raw];
}

$server = proc_open(
    ['organilog-entrypoint', 'apache2-foreground'],
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, ORGANILOG_ROOT
);
if (!is_resource($server)) {
    throw new RuntimeException('Cannot start Apache.');
}
fclose($pipes[0]);
try {
    for ($attempt = 0; $attempt < 100; $attempt++) {
        [$status] = http('/');
        if ($status === 200) {
            break;
        }
        if (!proc_get_status($server)['running']) {
            throw new RuntimeException('Apache stopped before becoming ready: ' . stream_get_contents($pipes[2]));
        }
        usleep(100000);
    }
    verify($status === 200, 'Apache must serve the console over HTTPS.');
    foreach (['/index.html', '/console.js', '/console.css'] as $path) {
        [$status] = http($path);
        verify($status === 200, 'Apache must serve console asset ' . $path);
    }
    [$status, $body] = http('/v1/catalog');
    verify($status === 503 && ($body['error']['code'] ?? '') === 'not_configured', 'Apache must route fresh-instance API requests to PHP.');
    [$status, , $redirect] = http('/v1/catalog', null, 'GET', null, false);
    verify($status === 301 && $redirect === 'https://localhost:8443/v1/catalog', 'Plain HTTP must redirect to HTTPS and preserve the route.');
    foreach (['/config.php', '/var/config.php', '/var/keys.json', '/src/bootstrap.php', '/.git/config', '/bin/setup.php', '/.htaccess'] as $path) {
        [$status] = http($path);
        verify(in_array($status, [403, 404], true), 'Apache must deny private path ' . $path);
    }
    [$exit, $out, $err] = setup_as_web_user();
    verify($exit === 0, 'Setup must run as www-data: ' . $err);
    verify(preg_match('/Admin key \(shown once\): (olk_[a-f0-9]{48})/', $out, $matches) === 1, 'Setup must return an admin key.');
    $admin = $matches[1];
    [$exit] = setup_as_web_user(['--check']);
    verify($exit === 0, 'The Apache user must be able to read config and write runtime storage.');
    [$status, $body] = http('/v1/catalog', $admin);
    verify($status === 200 && ($body['key']['is_admin'] ?? false), 'Bearer authentication must work through Apache rewrites.');
    [$status, $body] = http('/v1/admin/keys', $admin, 'POST', [
        'name' => 'apache-fixture', 'notes' => 'café', 'permissions' => ['*' => ['read']],
    ]);
    verify($status === 201 && isset($body['key']), 'Apache must support key creation with mbstring and writable storage.');
    $key = $body['key'];
    [$status, $body] = http('/v1/catalog', $key);
    verify($status === 200 && !($body['key']['is_admin'] ?? true), 'A generated tool key must authenticate.');
    [$status] = http('/v1/admin/keys', $key);
    verify($status === 403, 'Tool keys must not administer the instance.');
    [$status, $body] = http('/v1/issue/update', $admin, 'POST', ['id' => '123', 'data' => ['status' => '5']]);
    verify($status === 403 && ($body['error']['code'] ?? '') === 'writes_disabled', 'Upstream writes must remain disabled.');
    foreach (['/var/config.php', '/var/keys.json'] as $path) {
        [$status] = http($path);
        verify(in_array($status, [403, 404], true), 'Generated private files must remain inaccessible: ' . $path);
    }
    $before = file_get_contents(ORGANILOG_CONFIG_FILE);
    [$exit] = setup_as_web_user();
    verify($exit !== 0 && file_get_contents(ORGANILOG_CONFIG_FILE) === $before, 'Rerunning setup must preserve the container configuration.');
    echo "Passed $checks Apache/HTTPS checks with dummy credentials. No external network access is required.\n";
} finally {
    proc_terminate($server);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($server);
}
