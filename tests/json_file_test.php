<?php
declare(strict_types=1);

namespace Organilog;

// Simulate storage failures without filling a disk or changing filesystem permissions.
function fwrite($handle, string $bytes): int|false
{
    if (($GLOBALS['storage_failure'] ?? '') === 'write') {
        if ($GLOBALS['storage_write_started'] ?? false) return false;
        $GLOBALS['storage_write_started'] = true;
    }
    return \fwrite($handle, substr($bytes, 0, 7)); // Also exercise successful short writes.
}
function fflush($handle): bool
{
    return ($GLOBALS['storage_failure'] ?? '') !== 'flush' && \fflush($handle);
}
function rename(string $source, string $destination): bool
{
    return ($GLOBALS['storage_failure'] ?? '') !== 'rename' && \rename($source, $destination);
}

require __DIR__ . '/../src/bootstrap.php';
if (($argv[1] ?? '') === '--worker') {
    for ($i = 0; $i < 20; $i++) {
        JsonFile::update($argv[2], static function ($doc) {
            usleep(1000);
            return [['version' => $doc['version'] + 1], null];
        });
    }
    exit;
}
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new \RuntimeException($message);
    $checks++;
}

$directory = sys_get_temp_dir() . '/organilog-storage-' . bin2hex(random_bytes(8));
mkdir($directory, 0750);
$file = $directory . '/keys.json';
try {
    check(JsonFile::read($file) === [], 'Missing storage must read as an empty document.');
    check(JsonFile::update($file, fn ($doc) => [['version' => 1], 'created']) === 'created', 'A new document must return its result.');
    check(JsonFile::read($file) === ['version' => 1], 'Short writes must persist the complete document.');
    $original = file_get_contents($file);
    foreach (['write', 'flush', 'rename', 'encoding', 'callback'] as $failure) {
        $GLOBALS['storage_failure'] = $failure;
        $GLOBALS['storage_write_started'] = false;
        $caught = false;
        try {
            JsonFile::update($file, static function ($doc) use ($failure) {
                if ($failure === 'callback') throw new \RuntimeException('Rejected change.');
                return [['value' => $failure === 'encoding' ? "\xB1" : str_repeat('x', 100)], null];
            });
        } catch (\Throwable) {
            $caught = true;
        }
        check($caught, "A $failure failure must not report success.");
        check(file_get_contents($file) === $original, "A $failure failure must preserve the original bytes.");
        check(glob($file . '.tmp-*') === [], "A $failure failure must remove temporary files.");
    }
    $GLOBALS['storage_failure'] = '';
    JsonFile::update($file, fn ($doc) => [['version' => $doc['version'] + 1], null]);
    check(JsonFile::read($file) === ['version' => 2], 'A failed update must release its lock for a later update.');
    if (function_exists('proc_open')) {
        $workers = [];
        try {
            $ini = php_ini_loaded_file();
            $command = $ini === false ? [PHP_BINARY] : [PHP_BINARY, '-c', $ini];
            for ($i = 0; $i < 3; $i++) {
                $worker = proc_open(array_merge($command, [__FILE__, '--worker', $file]),
                    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
                if (!is_resource($worker)) throw new \RuntimeException('Cannot start storage worker.');
                fclose($pipes[0]);
                $workers[] = [$worker, $pipes];
            }
            while ($workers) {
                [$worker, $pipes] = array_shift($workers);
                $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                check(proc_close($worker) === 0, 'Storage worker must finish: ' . $output);
            }
            check(JsonFile::read($file) === ['version' => 62], 'Concurrent workers must not lose updates across file replacements.');
        } finally {
            foreach ($workers as [$worker, $pipes]) {
                proc_terminate($worker);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($worker);
            }
        }
    }
    echo "Passed $checks JSON storage checks.\n";
} finally {
    foreach (glob($directory . '/*') as $path) unlink($path);
    rmdir($directory);
}
