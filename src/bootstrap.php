<?php
declare(strict_types=1);

// No framework, Composer packages, or external database required.

const ORGANILOG_ROOT = __DIR__ . '/..';
const ORGANILOG_VAR = ORGANILOG_ROOT . '/var';
// Docker keeps configuration in the same private persistent volume as runtime state.
define('ORGANILOG_CONFIG_FILE', getenv('ORGANILOG_CONFIG_FILE') ?: ORGANILOG_ROOT . '/config.php');

spl_autoload_register(function (string $class): void {
    $prefix = 'Organilog\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

function organilog_config(): array
{
    static $config = null;
    if ($config === null) {
        $file = ORGANILOG_CONFIG_FILE;
        if (!is_file($file)) {
            throw new Organilog\ApiError(503, 'not_configured', 'Run php bin/setup.php on the server to configure this instance.');
        }
        try {
            $config = Organilog\Configuration::validate(require $file);
        } catch (\Throwable $e) {
            error_log('organilog-api configuration: ' . $e->getMessage());
            throw new Organilog\ApiError(503, 'not_configured', 'Configuration is invalid; run php bin/setup.php --check on the server.');
        }
    }
    return $config;
}

/** var/ is created on demand so a fresh upload works without manual mkdir. */
function organilog_var(string $sub): string
{
    $dir = $sub === '' ? ORGANILOG_VAR : ORGANILOG_VAR . '/' . $sub;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new Organilog\ApiError(500, 'storage', "cannot create var/$sub");
    }
    return $dir;
}
