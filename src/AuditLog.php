<?php
declare(strict_types=1);

namespace Organilog;

/** One JSON line per request in var/logs/audit-YYYY-MM.jsonl. Never contains a key or the Organilog token. */
final class AuditLog
{
    public static function write(array $entry): void
    {
        $entry = ['ts' => date(DATE_ATOM), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null] + $entry;
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents(organilog_var('logs') . '/audit-' . date('Y-m') . '.jsonl', $line, FILE_APPEND | LOCK_EX);
    }
}
