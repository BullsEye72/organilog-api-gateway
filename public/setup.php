<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
if (PHP_VERSION_ID < 80100) {
    http_response_code(503);
    exit('Select PHP 8.1 or newer in your hosting control panel, then reload this page.');
}
require __DIR__ . '/../src/bootstrap.php';

function escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$setup = new Organilog\WebSetup();
$secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$local = PHP_SAPI === 'cli-server' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$error = null;
$notice = null;
$secret = null;
$removed = false;
$locked = $setup->locked();
$verified = false;
$draft = [];
$requirements = [
    'PHP 8.1 or newer' => PHP_VERSION_ID >= 80100,
    'Organilog connections (cURL)' => extension_loaded('curl'),
    'Text handling (mbstring)' => extension_loaded('mbstring'),
    'JSON support' => extension_loaded('json'),
    'Secure setup sessions' => extension_loaded('session'),
];
try {
    if (!$locked) {
        if (!$secure && !$local) {
            throw new RuntimeException('Open this page using https:// before entering any credentials. Enable SSL in your hosting control panel if needed.');
        }
        if (!extension_loaded('session')) {
            throw new RuntimeException('Enable the PHP session extension in your hosting control panel.');
        }
        ini_set('session.use_strict_mode', '1');
        session_name('organilog_setup');
        session_set_cookie_params(['httponly' => true, 'secure' => $secure, 'samesite' => 'Strict', 'path' => '/']);
        if (!session_start()) {
            throw new RuntimeException('Setup could not start a secure session. Check your hosting PHP session settings.');
        }
        if (time() - ($_SESSION['last_activity'] ?? time()) > 1800) {
            $_SESSION = [];
        }
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        $code = $setup->ownershipCode();
        $verified = hash_equals(hash('sha256', $code), (string) ($_SESSION['owner'] ?? ''));
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'POST'], true)) {
            http_response_code(405);
            header('Allow: GET, POST');
            throw new RuntimeException('Use the setup form to continue.');
        }
        if ($method === 'POST') {
            if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
                http_response_code(403);
                throw new RuntimeException('This form expired. Reload the page and try again.');
            }
            $action = $_POST['action'] ?? '';
            if ($action === 'unlock') {
                if (!is_string($_POST['code'] ?? null) || !hash_equals($code, trim($_POST['code']))) {
                    http_response_code(403);
                    throw new RuntimeException('The installation code does not match. Copy it from var/setup-key.txt using your hosting file manager.');
                }
                session_regenerate_id(true);
                $_SESSION['owner'] = hash('sha256', $code);
                $verified = true;
            } elseif (!$verified) {
                http_response_code(403);
                throw new RuntimeException('Enter the installation code first.');
            } else {
                if (!in_array($action, ['test_organilog', 'install'], true)) {
                    throw new RuntimeException('Choose a setup action.');
                }
                $draft = $_SESSION['draft'] ?? [];
                foreach (['name', 'account', 'token'] as $field) {
                    if (!is_string($_POST[$field] ?? '')) {
                        throw new RuntimeException('A form value is invalid.');
                    }
                    $value = $_POST[$field] ?? '';
                    if (strlen($value) > 4096) {
                        throw new RuntimeException('A form value is too long.');
                    }
                    // Keep secrets server-side between test steps, never in HTML or hidden fields.
                    if ($field !== 'token' || $value !== '') {
                        $draft[$field] = $value;
                    }
                }
                $_SESSION['draft'] = $draft;
                if (isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $_SESSION['logo'] = $setup->logo($_FILES['logo']);
                }
                Organilog\Setup::assertRequirements();
                if (in_array(false, $requirements, true)) {
                    throw new RuntimeException('Enable the missing PHP extensions listed below before installing.');
                }
                $config = $setup->settings($draft);
                if ($action === 'test_organilog') {
                    $setup->testOrganilog($config);
                    $notice = 'Organilog answered the read-only account check successfully.';
                } else {
                    if (($_POST['confirm'] ?? '') !== 'yes') {
                        throw new RuntimeException('Confirm that you are ready to install.');
                    }
                    $secret = $setup->finish($config, $_SESSION['logo'] ?? null);
                    $_SESSION = [];
                    session_destroy();
                    // Only this public entry point deletes itself; config and data are preserved.
                    $removed = @unlink(__FILE__);
                    $locked = true;
                }
            }
        }
        $draft = $_SESSION['draft'] ?? $draft;
    }
} catch (Throwable $e) {
    if ($e instanceof RuntimeException) {
        $error = $e->getMessage();
    } else {
        $error = 'Setup could not complete. Check PHP and storage permissions in your hosting control panel, then try again.';
    }
}
$draft += ['name' => 'Organilog API', 'account' => ''];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Set up Organilog API</title>
  <link rel="stylesheet" href="setup.css">
</head>
<body><main>
  <a class="wordmark" href="/">Organilog <span>API</span></a>
  <h1><?= $secret !== null ? 'Your instance is ready' : ($locked ? 'Setup is locked' : 'Welcome. Let’s set things up.') ?></h1>
  <?php if ($error !== null): ?><p class="message error" role="alert"><?= escape($error) ?></p><?php endif ?>
  <?php if ($notice !== null): ?><p class="message success" role="status"><?= escape($notice) ?></p><?php endif ?>
  <?php if ($secret !== null): ?>
    <section>
      <h2>Save your admin key</h2>
      <p>This key opens the console and manages keys for your tools. Copy it to your password manager now; it is shown only once.</p>
      <label>Admin key<input class="secret" type="text" readonly value="<?= escape($secret) ?>" autocomplete="off" spellcheck="false"></label>
      <p>Your Organilog connection was checked and your configuration and branding were saved. Changes to Organilog remain switched off.</p>
      <p><?= $removed ? 'The installer has deleted itself.' : 'Setup is permanently locked. Your hosting did not allow automatic deletion; remove public/setup.php with your file manager.' ?></p>
      <a class="button" href="/">Open your console</a>
    </section>
  <?php elseif ($locked): ?>
    <p>This instance is already configured. Setup cannot replace its settings, admin key, or saved data.</p>
    <a class="button" href="/">Open the console</a>
  <?php elseif (!$verified && $error === null || !$verified && isset($_SESSION['csrf'])): ?>
    <p>No terminal needed. First, confirm that this hosting space belongs to you.</p>
    <section>
      <h2>1. Confirm ownership</h2>
      <p>In your hosting file manager or FTP app, open <code>var/setup-key.txt</code> beside the <code>public</code> folder. Copy the code into this form.</p>
      <p class="hint">We created this private file for you. It prevents someone else from setting up your site first.</p>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf'] ?? '') ?>">
        <label>Installation code<input name="code" type="password" required autocomplete="off" spellcheck="false"></label>
        <button name="action" value="unlock">Continue</button>
      </form>
    </section>
  <?php elseif ($verified): ?>
    <p>Enter your details, test the Organilog connection, then install. No database is needed; this app stores its keys and usage in private files.</p>
    <section><h2>Server checks</h2><ul class="checks">
      <?php foreach ($requirements as $label => $available): ?><li class="<?= $available ? 'pass' : 'fail' ?>"><?= $available ? '✓' : '✕' ?> <?= escape($label) ?></li><?php endforeach ?>
    </ul></section>
    <form method="post" enctype="multipart/form-data" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
      <section>
        <h2>2. Make it yours</h2>
        <label>Application name<input name="name" maxlength="80" value="<?= escape($draft['name']) ?>" required></label>
        <label>Logo (optional)<input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
        <p class="hint"><?= isset($_SESSION['logo']) ? 'Your selected logo is saved for this installation.' : 'PNG, JPEG or WebP, up to 2 MB. You can keep the default branding.' ?></p>
      </section>
      <section>
        <h2>3. Connect Organilog</h2>
        <label>Account name<input name="account" value="<?= escape($draft['account']) ?>" placeholder="mycompany" spellcheck="false" required></label>
        <p class="hint">For mycompany.organilog.com, enter mycompany.</p>
        <label>Master API token<input name="token" type="password" autocomplete="new-password" placeholder="<?= empty($draft['token']) ? 'Paste your Organilog API token' : 'Token saved for this setup; leave blank to keep it' ?>" spellcheck="false"></label>
        <button class="secondary" name="action" value="test_organilog">Test Organilog connection</button>
        <p class="hint">This checks the account without changing Organilog data.</p>
      </section>
      <section>
        <h2>4. Install</h2>
        <p>We will recheck the Organilog connection, check file permissions, save your configuration and logo, and generate your admin key. Then setup will lock itself and attempt to delete this file.</p>
        <label class="check"><input type="checkbox" name="confirm" value="yes"> I’m ready to install this instance.</label>
        <button name="action" value="install">Install Organilog API</button>
      </section>
    </form>
  <?php endif ?>
  <footer>Your account token is never sent to the console. Setup sessions expire after 30 minutes of inactivity.</footer>
</main></body></html>
