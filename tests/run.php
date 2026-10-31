<?php
declare(strict_types=1);

// Offline integration checks. All configuration and state belong to a temporary copy.
require __DIR__ . '/../src/bootstrap.php';
Organilog\Setup::assertRequirements();
if (!function_exists('proc_open')) {
    throw new RuntimeException('These checks require proc_open.');
}

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function php_command(): array
{
    $ini = php_ini_loaded_file();
    return $ini === false ? [PHP_BINARY] : [PHP_BINARY, '-c', $ini];
}

function cli(string $root, array $args = [], string $input = '', array $variables = []): array
{
    $env = getenv();
    unset($env['ORGANILOG_SETUP_ACCOUNT'], $env['ORGANILOG_SETUP_TOKEN'], $env['ORGANILOG_CONFIG_FILE']);
    $process = proc_open(
        array_merge(php_command(), [$root . '/bin/setup.php'], $args),
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $root, array_merge($env, $variables)
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start setup subprocess.');
    }
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out, $err];
}

function request(string $base, string $path, ?string $key = null, string $method = 'GET', ?array $body = null): array
{
    $handle = curl_init($base . $path);
    $responseHeaders = [];
    $headers = $key === null ? [] : ['Authorization: Bearer ' . $key];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_PROXY => '',
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
    ]);
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($raw === false) {
        throw new RuntimeException('Local HTTP request failed.');
    }
    return [$status, json_decode($raw, true), $raw, $responseHeaders];
}

$root = sys_get_temp_dir() . '/organilog-tests-' . bin2hex(random_bytes(8));
mkdir($root, 0750);
$server = null;
$serverPipes = [];
try {
    // Copy only distributable inputs. Never copy an existing config.php or var/.
    foreach (['bin', 'src', 'public', 'data'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ORGANILOG_ROOT . '/' . $dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        mkdir($root . '/' . $dir, 0750);
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen(ORGANILOG_ROOT . '/' . $dir) + 1);
            $target = $root . '/' . $dir . '/' . $relative;
            $item->isDir() ? mkdir($target, 0750) : copy($item->getPathname(), $target);
        }
    }
    copy(ORGANILOG_ROOT . '/config.example.php', $root . '/config.example.php');

    [$exit, $out] = cli($root, ['--help']);
    check($exit === 0 && str_contains($out, 'Usage:'), 'Setup help should work without configuration.');
    [$exit] = cli($root, ['--unknown']);
    check($exit !== 0 && !file_exists($root . '/config.php'), 'Unknown arguments must not install anything.');
    [$exit, , $err] = cli($root, ['--check']);
    check($exit !== 0 && str_contains($err, 'missing'), 'A fresh clone should report missing configuration.');
    [$exit] = cli($root, [], "\ntest-token\n");
    check($exit !== 0 && !file_exists($root . '/config.php'), 'Empty account must not produce a configuration.');
    [$exit] = cli($root);
    check($exit !== 0 && !file_exists($root . '/config.php'), 'EOF must fail cleanly without creating config.php.');

    // Reserve an available port, then start the real development router in the isolated copy.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Cannot allocate a local test port.');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $base = 'http://' . $address;
    $server = proc_open(
        array_merge(php_command(), ['-S', $address, '-t', $root . '/public', $root . '/bin/router.php']),
        [['pipe', 'r'], ['file', $root . '/server-stdout.log', 'ab'], ['file', $root . '/server-stderr.log', 'ab']], $serverPipes, $root,
        array_diff_key(getenv(), array_flip(['ORGANILOG_SETUP_ACCOUNT', 'ORGANILOG_SETUP_TOKEN', 'ORGANILOG_CONFIG_FILE']))
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Cannot start local test server.');
    }
    fclose($serverPipes[0]);
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($ready !== false) {
            fclose($ready);
            break;
        }
        usleep(100000);
    }
    [$status, $data] = request($base, '/v1/catalog');
    check($status === 503 && $data['error']['code'] === 'not_configured', 'Fresh API must explain that setup is required.');
    foreach (['/', '/index.html', '/console.js', '/console.css'] as $path) {
        [$status, , $raw] = request($base, $path);
        check($status === 200 && $raw !== '', 'Console asset must be served: ' . $path);
    }
    foreach (['/config.php', '/var/keys.json', '/src/bootstrap.php', '/.git/config', '/.htaccess', '/api.php', '/bin/setup.php'] as $path) {
        [$status] = request($base, $path);
        check($status === 404, 'Development router must not expose private files: ' . $path);
    }

    $account = "test'account";
    $token = "test-token'with\\special-characters";
    [$exit, $out, $err] = cli($root, [], '', [
        'ORGANILOG_SETUP_ACCOUNT' => $account,
        'ORGANILOG_SETUP_TOKEN' => $token,
    ]);
    check($exit === 0, 'Unattended setup should succeed: ' . $err);
    check(preg_match('/Admin key \(shown once\): (olk_[a-f0-9]{48})/', $out, $matches) === 1, 'Setup must show a generated admin key.');
    $admin = $matches[1];
    $config = require $root . '/config.php';
    check($config['organilog']['account'] === $account && $config['organilog']['token'] === $token, 'PHP config generation must safely round-trip special characters.');
    check(hash_equals($config['admin_key_sha256'], hash('sha256', $admin)), 'Stored hash must authenticate the generated key.');
    check(!str_contains(file_get_contents($root . '/config.php'), $admin), 'Raw admin key must not be saved in config.');
    check(!$config['allow_writes'] && !$config['log_write_bodies'] && $config['verify_updates'], 'Fresh installation must have the documented defaults.');
    foreach (['var', 'var/usage', 'var/logs'] as $dir) {
        check(is_dir($root . '/' . $dir), 'Setup must create ' . $dir);
    }
    [$exit, $out] = cli($root, ['--check']);
    check($exit === 0 && !str_contains($out, $token) && !str_contains($out, $admin), 'Diagnostics should succeed without disclosing secrets.');

    [$status, $data, $raw] = request($base, '/v1/catalog', $admin);
    check($status === 200 && $data['key']['is_admin'] && !$data['writes_enabled'], 'Generated admin key must connect to the console catalog.');
    check(!str_contains($raw, $token) && !str_contains($raw, $account), 'Catalog response must not expose account credentials.');
    [$status] = request($base, '/v1/catalog');
    check($status === 401, 'Anonymous catalog access must fail.');
    [$status] = request($base, '/v1/catalog', 'wrong-key');
    check($status === 401, 'Unknown keys must fail.');
    [$status, $data] = request($base, '/v1/admin/keys', $admin, 'POST', [
        'name' => 'test-integration', 'notes' => 'Accented note: café', 'permissions' => ['*' => ['read']],
    ]);
    check($status === 201 && isset($data['key']), 'Admin must be able to create integration keys with writes disabled.');
    $key = $data['key'];
    $id = $data['record']['id'];
    [$status, $data] = request($base, '/v1/catalog', $key);
    check($status === 200 && !$data['key']['is_admin'], 'New integration key must authenticate.');
    [$status] = request($base, '/v1/admin/keys', $key);
    check($status === 403, 'Integration keys must not administer keys.');
    [$status, $data] = request($base, '/v1/issue/update', $admin, 'POST', ['id' => '123', 'data' => ['status' => '5']]);
    check($status === 403 && $data['error']['code'] === 'writes_disabled', 'Fresh installations must refuse upstream writes before contacting Organilog.');

    $beforeConfig = file_get_contents($root . '/config.php');
    $beforeKeys = file_get_contents($root . '/var/keys.json');
    [$exit, , $err] = cli($root);
    check($exit !== 0 && str_contains($err, 'already exists'), 'Rerunning setup must refuse an existing installation before prompting.');
    check($beforeConfig === file_get_contents($root . '/config.php') && $beforeKeys === file_get_contents($root . '/var/keys.json'), 'Rerunning setup must preserve configuration and existing keys byte for byte.');

    [$status, $data] = request($base, '/v1/admin/keys/' . $id . '/rotate', $admin, 'POST');
    check($status === 200 && isset($data['key']), 'Admin must be able to rotate integration keys.');
    $rotated = $data['key'];
    [$status] = request($base, '/v1/catalog', $key);
    check($status === 401, 'Old integration key must stop working after rotation.');
    [$status] = request($base, '/v1/catalog', $rotated);
    check($status === 200, 'Rotated integration key must work.');
    [$status] = request($base, '/v1/admin/keys/' . $id, $admin, 'PATCH', ['enabled' => false]);
    check($status === 200, 'Admin must be able to disable an integration key.');
    [$status] = request($base, '/v1/catalog', $rotated);
    check($status === 403, 'Disabled integration key must fail.');
    [$status] = request($base, '/v1/admin/keys/' . $id, $admin, 'DELETE');
    check($status === 200, 'Admin must be able to delete an integration key.');

    file_put_contents($root . '/config.php', "<?php return ['organilog' => ['token' => 'private-value']];\n");
    [$exit, $out, $err] = cli($root, ['--check']);
    check($exit !== 0 && !str_contains($out . $err, 'private-value'), 'Invalid configuration diagnostics must not disclose secrets.');
    [$status, $data] = request($base, '/v1/catalog', $admin);
    check($status === 503 && $data['error']['code'] === 'not_configured', 'Invalid runtime configuration should return a setup error.');
    file_put_contents($root . '/config.php', $beforeConfig);
    file_put_contents($root . '/data/catalog.json', '{}');
    [$exit] = cli($root, ['--check']);
    check($exit !== 0, 'Diagnostics must reject an invalid catalog.');

    copy(ORGANILOG_ROOT . '/data/catalog.json', $root . '/data/catalog.json');
    $containerConfig = $root . '/var/docker-config.php';
    $containerEnv = [
        'ORGANILOG_CONFIG_FILE' => $containerConfig,
        'ORGANILOG_SETUP_ACCOUNT' => 'container-fixture',
        'ORGANILOG_SETUP_TOKEN' => 'container-fixture-token',
    ];
    [$exit] = cli($root, [], '', $containerEnv);
    check($exit === 0 && is_file($containerConfig), 'Setup must support a config path inside a Docker data volume.');
    check(file_get_contents($root . '/config.php') === $beforeConfig, 'Custom-path setup must not change the default config.');
    [$exit] = cli($root, ['--check'], '', $containerEnv);
    check($exit === 0, 'Diagnostics must use the custom config path.');
    $beforeContainerConfig = file_get_contents($containerConfig);
    [$exit] = cli($root, [], '', $containerEnv);
    check($exit !== 0 && file_get_contents($containerConfig) === $beforeContainerConfig, 'Custom-path setup must also refuse to overwrite an existing installation.');

    require __DIR__ . '/health-api-checks.php';
    require __DIR__ . '/installer-checks.php';
    $healthTest = proc_open(array_merge(php_command(), [__DIR__ . '/upstream_test.php']),
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $healthPipes, ORGANILOG_ROOT);
    if (!is_resource($healthTest)) throw new RuntimeException('Cannot start upstream health checks.');
    fclose($healthPipes[0]);
    $healthOutput = stream_get_contents($healthPipes[1]);
    $healthErrors = stream_get_contents($healthPipes[2]);
    fclose($healthPipes[1]);
    fclose($healthPipes[2]);
    check(proc_close($healthTest) === 0, 'Upstream health checks failed: ' . $healthOutput . $healthErrors);
    echo $healthOutput;
    echo "Passed $checks offline installation and API checks. No live Organilog requests were made.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    // Verify the resolved cleanup target is the unique fixture directory in the OS temp root.
    $resolved = realpath($root);
    $prefix = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'organilog-tests-';
    if ($resolved !== false && str_starts_with($resolved, $prefix)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($resolved);
    }
}
