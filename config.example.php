<?php
// Prefer php bin/setup.php, or copy to config.php (gitignored) and fill in manually.
// Keep config.php outside the web root; point the web server at public/ only.

return [
    'organilog' => [
        'account'  => '', // Your Organilog account subdomain.
        'token'    => '',
        'base_url' => 'https://sync.organilog.com/api/v3',
        'timeout'  => 30,
        'connect_timeout' => 5,
        // Shared availability protection. Existing config.php files get these defaults too.
        'failure_threshold' => 2,
        'cooldown_seconds' => 60,
        // Optional retry of a failed read connection; writes are never retried.
        'retry_reads' => false,
    ],

    // sha256 of the admin key, which manages the per-tool keys. Generate with:
    //   php bin/keygen.php
    // Only the hash lives here; the key itself goes in your password manager.
    'admin_key_sha256' => '',

    // Server-wide write switch. While false, create/update/delete are refused for every key,
    // whatever its permissions say.
    'allow_writes' => false,

    // After an update: re-read the record, check no field was blanked, restore if one was.
    // Costs two extra upstream reads per update.
    'verify_updates' => true,

    // Keep the request body of writes in var/logs/audit-*.jsonl. That is the only way to see
    // afterwards what a tool changed, but it means customer data ends up in the log.
    'log_write_bodies' => false,
];
