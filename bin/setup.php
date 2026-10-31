<?php
declare(strict_types=1);

// Run from a terminal, never through the web server.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "PHP 8.1 or newer is required.\n");
    exit(1);
}
require __DIR__ . '/../src/bootstrap.php';

function setup_prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $line = fgets(STDIN);
    if ($line === false) {
        throw new RuntimeException('Input ended before setup completed. Supply account and token interactively or through the setup environment variables.');
    }
    return trim($line);
}

try {
    $args = array_slice($argv, 1);
    if ($args === ['--help'] || $args === ['-h']) {
        echo "Usage: php bin/setup.php [--check|--help]\n\n";
        echo "No options: create config.php and runtime directories, and show a new admin key once.\n";
        echo "Existing config.php and API keys are never overwritten.\n";
        echo "--check: validate local requirements, config, catalog, and writable storage.\n";
        echo "For automation, set ORGANILOG_SETUP_ACCOUNT and ORGANILOG_SETUP_TOKEN.\n";
        echo "Interactive token input is visible; environment variables avoid echoing it.\n";
        exit(0);
    }
    if ($args === ['--check']) {
        foreach (Organilog\Setup::check(ORGANILOG_ROOT, ORGANILOG_CONFIG_FILE) as $message) {
            echo "$message\n";
        }
        exit(0);
    }
    if ($args !== []) {
        throw new RuntimeException('Unknown arguments. Use php bin/setup.php --help.');
    }
    Organilog\Setup::assertRequirements();
    if (file_exists(ORGANILOG_CONFIG_FILE) || is_link(ORGANILOG_CONFIG_FILE)) {
        throw new RuntimeException('config.php already exists; setup will not overwrite it. Use --check to inspect the installation.');
    }
    $account = getenv('ORGANILOG_SETUP_ACCOUNT');
    if ($account === false || trim($account) === '') {
        $account = setup_prompt('Organilog account (your account subdomain): ');
    }
    $token = getenv('ORGANILOG_SETUP_TOKEN');
    if ($token === false || trim($token) === '') {
        $token = setup_prompt('Organilog API token (input is visible): ');
    }
    $secret = Organilog\Setup::install(ORGANILOG_ROOT, $account, $token, ORGANILOG_CONFIG_FILE);
    echo "\nInstallation created. Upstream writes are disabled.\n";
    echo "Admin key (shown once): $secret\n";
    echo "Store it in your password manager; only its SHA-256 hash is saved.\n\n";
    echo "Check: php bin/setup.php --check\n";
    echo "Local server: php -S 127.0.0.1:8080 -t public bin/router.php\n";
    echo "Open http://127.0.0.1:8080 and connect with the admin key to manage tool keys.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Setup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
