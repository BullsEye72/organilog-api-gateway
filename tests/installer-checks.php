<?php
declare(strict_types=1);

// Included by run.php in its unique disposable installation. All upstream replies are fixtures.
$probeConfig = ['organilog' => ['account' => 'fixture', 'token' => 'private-test-token']];
$cooldownInstaller = new Organilog\WebSetup($root, $root . '/config.php', static function (): array {
    throw new Organilog\ApiError(503, 'upstream_cooldown', 'cooldown', ['retry_after' => 45]);
});
$cooldownMessage = '';
try {
    $cooldownInstaller->testOrganilog($probeConfig);
} catch (RuntimeException $e) {
    $cooldownMessage = $e->getMessage();
}
check(str_contains($cooldownMessage, '45 seconds'), 'Setup must explain an active shared cooldown without blaming credentials.');
$rejections = [
    [[400, ['ERROR' => 'Account does not exists'], ''], 'HTTP 400'],
    [[200, ['ERROR' => 'Invalid token'], ''], 'Invalid token'],
    [[200, ['MESSAGE' => 'Invalid token'], ''], 'Invalid token'],
    [[401, ['message' => 'Unauthorized'], ''], 'HTTP 401'],
    [[429, ['ERROR' => 'Quota exceeded'], ''], 'request limit'],
    [[503, null, '<html>Maintenance</html>'], 'server problem'],
    [[200, null, '<html>Firewall</html>'], 'HTML page'],
    [[302, null, ''], 'redirected'],
    [[200, [], '[]'], 'HTTP 200'],
    [[400, ['ERROR' => 'Rejected private-test-token'], ''], '[redacted]'],
];
foreach ($rejections as [$reply, $expected]) {
    $installer = new Organilog\WebSetup($root, $root . '/config.php', fn () => $reply);
    $message = '';
    try {
        $installer->testOrganilog($probeConfig);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }
    check(str_contains($message, $expected) && !str_contains($message, 'private-test-token'), 'Connection diagnostics must explain the failure safely: ' . $expected);
}
$installer = new Organilog\WebSetup($root, $root . '/config.php', fn () => [200, ['ERROR' => false, 'company' => 'Fixture'], '']);
$installer->testOrganilog($probeConfig);
check(true, 'A successful account reply with an empty error field must be accepted.');
foreach ([6 => 'DNS', 7 => 'port 443', 28 => 'timed out', 60 => 'trusted CA', 35 => 'TLS'] as $errno => $expected) {
    $error = Organilog\Upstream::connectionError($errno);
    check($error->details['curl_errno'] === $errno && str_contains($error->getMessage(), $expected), 'Transport diagnostics must identify cURL ' . $errno);
}

try {
    Organilog\WebSetup::image('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    check(false, 'SVG logos must not be accepted as raster images.');
} catch (RuntimeException) {
    check(true, 'Executable/vector logo uploads are rejected.');
}

function browser(string $base, string $cookie, ?array $fields = null): array
{
    $handle = curl_init($base . '/setup.php');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '',
    ]);
    if ($fields !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $fields);
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if (!is_string($raw)) throw new RuntimeException('Browser setup request failed.');
    return [$status, $raw];
}

[$status, $html] = browser($base, $root . '/cookies.txt');
check($status === 200 && str_contains($html, 'Setup is locked'), 'Existing config must block browser installation.');
// Remove only our generated fixture config to exercise a fresh browser installation.
unlink($root . '/config.php');
[$status, $html] = browser($base, $root . '/cookies.txt');
check($status === 200 && str_contains($html, 'Confirm ownership'), 'A fresh browser install should ask for an ownership code.');
$code = file_get_contents($root . '/var/setup-key.txt');
check(strlen($code) === 48 && !str_contains($html, $code), 'Ownership code must never appear in the public page.');
check(preg_match('/name="csrf" value="([a-f0-9]{64})"/', $html, $match) === 1, 'The installer must issue a CSRF token.');
$csrf = $match[1];
[$status] = browser($base, $root . '/cookies.txt', ['action' => 'unlock', 'code' => $code, 'csrf' => 'invalid']);
check($status === 403, 'Browser setup must reject an invalid CSRF token.');
[$status, $html] = browser($base, $root . '/cookies.txt', ['action' => 'unlock', 'code' => 'wrong', 'csrf' => $csrf]);
check($status === 403 && !str_contains($html, 'name="token"'), 'Incorrect ownership code must not reveal the credentials form.');
[$status, $html] = browser($base, $root . '/cookies.txt', ['action' => 'unlock', 'code' => $code, 'csrf' => $csrf]);
check($status === 200 && str_contains($html, 'name="token"'), 'Valid ownership code must open the browser wizard.');
check(!str_contains($html, 'name="driver"') && !str_contains($html, 'name="db_password"'), 'The wizard must not ask for a database.');

// Replace only the isolated fixture's upstream client. No live endpoint is contacted.
$mock = <<<'PHP'
<?php
namespace Organilog;
final class Upstream {
    public function __construct(private array $settings) {}
    public function call(string $path, array $payload, bool $retry): array {
        return $this->settings['token'] === 'browser-correct-token'
            ? [200, ['company' => 'Offline fixture'], '']
            : [400, ['ERROR' => 'Invalid token: ' . $this->settings['token']], ''];
    }
}
PHP;
file_put_contents($root . '/src/Upstream.php', $mock);
$form = ['csrf' => $csrf, 'name' => 'My & Company', 'account' => 'browser-fixture', 'token' => 'browser-wrong-token'];
[$status, $html] = browser($base, $root . '/cookies.txt', $form + ['action' => 'test_organilog']);
check($status === 200 && str_contains($html, 'HTTP 400') && str_contains($html, 'Invalid token: [redacted]'), 'The browser must show the actual HTTP rejection and redact the token.');
check(!str_contains($html, 'browser-wrong-token'), 'Credential fields must not be echoed back into HTML.');
[$status, $html] = browser($base, $root . '/cookies.txt', $form + ['action' => 'install', 'confirm' => 'yes']);
check(!is_file($root . '/config.php') && !is_file($root . '/var/installed.lock'), 'Failed upstream test must not commit config or permanently lock setup.');
$form['token'] = 'browser-correct-token';
[$status, $html] = browser($base, $root . '/cookies.txt', $form + ['action' => 'test_organilog']);
check(str_contains($html, 'account check successfully'), 'The browser must report a successful read-only Organilog test.');
[$status, $html] = browser($base, $root . '/cookies.txt', $form + ['action' => 'install']);
check(!is_file($root . '/config.php') && str_contains($html, 'Confirm that'), 'Final installation requires the confirmation checkbox.');

$image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j5WQAAAAASUVORK5CYII=');
file_put_contents($root . '/logo.png', $image);
$form['token'] = ''; // Keep the tested token in the server-side session.
[$status, $html] = browser($base, $root . '/cookies.txt', $form + [
    'action' => 'install', 'confirm' => 'yes', 'logo' => new CURLFile($root . '/logo.png', 'image/png', 'logo.png'),
]);
check($status === 200 && str_contains($html, 'Your instance is ready'), 'The entire browser installation should complete.');
check(!str_contains($html, 'browser-correct-token'), 'Success page must disclose only the generated admin key.');
check(preg_match('/readonly value="(olk_[a-f0-9]{48})"/', $html, $match) === 1, 'Browser success page must show the admin key once.');
$browserAdmin = $match[1];
check(is_file($root . '/config.php') && is_file($root . '/var/installed.lock'), 'Successful setup must save config and a durable installation lock.');
check(!isset((require $root . '/config.php')['database']), 'Browser configuration must not contain database settings.');
check(!is_file($root . '/public/setup.php'), 'The installer entry point should delete itself when permissions allow.');
check(!is_file($root . '/var/setup-key.txt'), 'Successful setup must remove the one-time ownership code.');
[$status, $data] = request($base, '/v1/catalog', $browserAdmin);
check($status === 200 && $data['key']['is_admin'], 'Browser-generated admin key must authenticate to the file-backed instance.');
[$status, $brand] = request($base, '/branding.php');
check($status === 200 && $brand['name'] === 'My & Company' && $brand['logo_url'] !== null, 'Configured branding must be available without exposing credentials.');
[$status, , $logo] = request($base, '/branding.php?logo=1');
check($status === 200 && $logo === $image, 'Logo must be served as an image from private storage.');
[$status] = request($base, '/setup.php');
check($status === 404, 'The deleted installer should no longer be reachable.');
copy(ORGANILOG_ROOT . '/public/setup.php', $root . '/public/setup.php');
[$status, $html] = browser($base, $root . '/cookies.txt');
check(str_contains($html, 'Setup is locked') && !str_contains($html, $browserAdmin), 'Re-uploading setup.php must not reopen installation or reveal the admin key.');
unlink($root . '/config.php');
[$status, $html] = browser($base, $root . '/cookies.txt');
check(str_contains($html, 'Setup is locked'), 'The durable lock must still work if the config file is accidentally removed.');
