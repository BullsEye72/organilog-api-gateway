<?php
declare(strict_types=1);

// Included by run.php; all files and credentials belong to its disposable installation.
[$status] = request($base, '/v1/status');
check($status === 401, 'Status must require authentication.');
[$status] = request($base, '/v1/status', 'unknown-key');
check($status === 401, 'Unknown keys must not read status.');
$usageBefore = file_get_contents($root . '/var/usage/admin.json');
[$status, $data] = request($base, '/v1/status', $admin);
check($status === 200 && $data['upstream']['state'] === 'unknown', 'Missing health state should report unknown.');
check(file_get_contents($root . '/var/usage/admin.json') === $usageBefore, 'Passive polling must not change usage counters.');
check(!is_file($root . '/var/upstream-health.json'), 'Passive polling must not create a health observation or reserve a probe.');

// This is the state format used by the older installation.
$healthFile = $root . '/var/upstream-health.json';
$state = json_encode([
    'generation' => 3, 'open_until' => time() + 60, 'probe_until' => 0,
    'failures' => 2, 'last_observed_at' => time(), 'last_ms' => 20, 'last_http_status' => 503,
    'last_failure' => ['at' => time(), 'code' => 'upstream_server_error', 'upstream_status' => 503],
]);
file_put_contents($healthFile, $state);
[$status, $data] = request($base, '/v1/status', $admin);
check($status === 200 && $data['upstream']['state'] === 'unavailable', 'Copied legacy health state must be recognized.');
check(file_get_contents($healthFile) === $state, 'Reading imported health state must preserve it byte for byte.');
[$status, $data, , $headers] = request($base, '/v1/account', $admin);
check($status === 503 && $data['error']['code'] === 'upstream_cooldown', 'An imported cooldown must stop proxy calls before cURL.');
check((int) ($headers['retry-after'] ?? 0) > 0 && $data['error']['retry_after'] > 0, 'Cooldown responses must include HTTP and JSON retry delays.');
[$status] = request($base, '/v1/status', $admin, 'POST');
check($status === 405, 'Status endpoint must reject writes.');
[$status, $data] = request($base, '/v1/admin/keys', $admin, 'POST', [
    'name' => 'passive-status', 'permissions' => [], 'rate_limit_per_minute' => 1,
]);
check($status === 201, 'Key administration must remain available during an upstream cooldown.');
$statusKey = $data['key'];
$statusId = $data['record']['id'];
for ($i = 0; $i < 3; $i++) {
    [$status] = request($base, '/v1/status', $statusKey);
    check($status === 200, 'A limited tool may poll local status without spending its request budget.');
}
[$status] = request($base, '/v1/catalog', $statusKey);
check($status === 200, 'Status polling must leave the tool request budget intact.');
request($base, '/v1/admin/keys/' . $statusId, $admin, 'DELETE');
unlink($healthFile);
