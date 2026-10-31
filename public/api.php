<?php
declare(strict_types=1);

// Front controller for /v1/*. Routes:
//   GET          /v1/catalog                     endpoints, flagged with what this key may call
//   GET          /v1/status                      passive shared Organilog availability
//   GET|POST     /v1/{entity}[/{action}]         proxy to Organilog (GET only for reads; query string = body)
//   GET|POST     /v1/admin/keys                  list / create keys            (admin key only)
//   PATCH|DELETE /v1/admin/keys/{id}             edit / delete a key           (admin key only)
//   POST         /v1/admin/keys/{id}/rotate      new secret for a key          (admin key only)

use Organilog\ApiError;
use Organilog\AuditLog;
use Organilog\Catalog;
use Organilog\KeyStore;
use Organilog\Principal;
use Organilog\Proxy;
use Organilog\Upstream;
use Organilog\UpstreamHealth;
use Organilog\Usage;

require __DIR__ . '/../src/bootstrap.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Apache under PHP-FPM drops the Authorization header unless .htaccess copies it into the
 * environment, and after a rewrite it shows up as REDIRECT_HTTP_AUTHORIZATION. X-Api-Key is
 * accepted as well for clients where Bearer is awkward. Never read from the query string: it
 * would land in access logs.
 */
function presented_key(): ?string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
        return $m[1];
    }
    return $_SERVER['HTTP_X_API_KEY'] ?? null;
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $body = json_decode($raw, true);
    if (!is_array($body) || (array_is_list($body) && $body !== [])) {
        throw new ApiError(400, 'invalid_json', 'The request body must be a JSON object.');
    }
    return $body;
}

function require_admin(Principal $principal): void
{
    if (!$principal->isAdmin) {
        throw new ApiError(403, 'admin_only', 'Key management needs the admin key.');
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$path = trim(preg_replace('#^/v1#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/');
$principal = null;
$audit = ['method' => $method, 'route' => $path];

try {
    if ($path === '') {
        respond(200, ['service' => 'organilog-api', 'version' => 1, 'console' => '/', 'catalog' => '/v1/catalog']);
    }

    $config = organilog_config();
    $catalog = new Catalog();
    $keys = new KeyStore($config, $catalog);
    $usage = new Usage();

    $principal = $keys->authenticate(presented_key());
    if ($path === 'status') {
        if ($method !== 'GET') {
            throw new ApiError(405, 'method_not_allowed', 'Use GET for /v1/status.');
        }
        // Polling reads local state and never consumes upstream traffic or the tool's budget.
        respond(200, ['ok' => true, 'upstream' => (new UpstreamHealth($config['organilog']))->snapshot()]);
    }
    $usage->hit($principal);
    $audit['key'] = $principal->name;

    // --- catalog -------------------------------------------------------------
    if ($path === 'catalog' && $method === 'GET') {
        $data = $catalog->all();
        foreach ($data['endpoints'] as &$endpoint) {
            $endpoint['allowed'] = $principal->can($endpoint['entity'], $endpoint['kind']);
        }
        unset($endpoint);
        respond(200, [
            'ok'             => true,
            'key'            => [
                'name'                  => $principal->name,
                'is_admin'              => $principal->isAdmin,
                'permissions'           => $principal->permissions,
                'rate_limit_per_minute' => $principal->rateLimitPerMinute,
            ],
            'writes_enabled' => !empty($config['allow_writes']),
            'verify_updates' => !empty($config['verify_updates']),
            'catalog'        => $data,
        ]);
    }

    // --- key management ------------------------------------------------------
    if (preg_match('#^admin/keys(?:/(k_[0-9a-f]+))?(/rotate)?$#', $path, $m)) {
        require_admin($principal);
        $id = $m[1] ?? null;
        $rotate = isset($m[2]);

        if ($id === null && $method === 'GET') {
            $list = array_map(fn (array $k) => $k + ['usage' => $usage->of($k['id'])], $keys->all());
            respond(200, ['ok' => true, 'keys' => $list, 'admin_usage' => $usage->of('admin')]);
        }
        if ($id === null && $method === 'POST') {
            [$secret, $record] = $keys->create(json_body());
            AuditLog::write($audit + ['status' => 201, 'admin' => 'create', 'target' => $record['id'], 'target_name' => $record['name']]);
            respond(201, ['ok' => true, 'key' => $secret, 'record' => $record, 'notice' => 'This is the only time the key is shown.']);
        }
        if ($id !== null && $rotate && $method === 'POST') {
            [$secret, $record] = $keys->rotate($id);
            AuditLog::write($audit + ['status' => 200, 'admin' => 'rotate', 'target' => $id, 'target_name' => $record['name']]);
            respond(200, ['ok' => true, 'key' => $secret, 'record' => $record, 'notice' => 'The previous key no longer works.']);
        }
        if ($id !== null && !$rotate && $method === 'PATCH') {
            $record = $keys->update($id, json_body());
            AuditLog::write($audit + ['status' => 200, 'admin' => 'update', 'target' => $id, 'target_name' => $record['name']]);
            respond(200, ['ok' => true, 'record' => $record]);
        }
        if ($id !== null && !$rotate && $method === 'DELETE') {
            $record = $keys->delete($id);
            AuditLog::write($audit + ['status' => 200, 'admin' => 'delete', 'target' => $id, 'target_name' => $record['name']]);
            respond(200, ['ok' => true, 'deleted' => $record]);
        }
        throw new ApiError(405, 'method_not_allowed', "$method is not supported on /v1/$path.");
    }

    // --- Organilog proxy -----------------------------------------------------
    if (!preg_match('#^[a-z_]+(?:/[a-z_]+)?$#', $path)) {
        throw new ApiError(404, 'not_found', "No route /v1/$path.");
    }
    $endpoint = $catalog->get($path);
    if ($method === 'GET') {
        if ($endpoint !== null && $endpoint['kind'] !== 'read') {
            throw new ApiError(405, 'method_not_allowed', "$path changes data; use POST.");
        }
        $payload = $_GET;
    } elseif ($method === 'POST') {
        $payload = json_body();
    } else {
        throw new ApiError(405, 'method_not_allowed', 'Use GET (reads) or POST.');
    }
    $normalize = ($_GET['normalize'] ?? '') === '1';
    unset($payload['normalize']);

    $config['organilog']['base_url'] ??= 'https://sync.organilog.com/api/v3';
    $proxy = new Proxy($config, $catalog, new Upstream($config['organilog']));
    [$status, $body] = $proxy->handle($principal, $path, $payload, $normalize);
    $health = (new UpstreamHealth($config['organilog']))->snapshot();
    if (!$body['ok'] && $health['retry_after'] > 0) {
        header('Retry-After: ' . $health['retry_after']);
        $body['meta']['retry_after'] = $health['retry_after'];
    }

    $entry = $audit + [
        'status'          => $status,
        'kind'            => $body['kind'],
        'upstream_status' => $body['meta']['upstream_status'],
        'upstream_calls'  => $body['meta']['upstream_calls'],
        'ms'              => $body['meta']['upstream_ms'],
    ];
    if ($body['kind'] !== 'read') {
        $entry['verification'] = $body['meta']['verification'] ?? null;
        if (!empty($config['log_write_bodies'])) {
            $entry['body'] = $payload;
        }
    }
    AuditLog::write($entry);
    respond($status, $body);
} catch (ApiError $e) {
    if (isset($e->details['retry_after'])) {
        header('Retry-After: ' . (int) $e->details['retry_after']);
    }
    AuditLog::write($audit + ['key' => $principal?->name, 'status' => $e->status, 'error' => $e->errorCode]);
    respond($e->status, ['ok' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()] + $e->details]);
} catch (Throwable $e) {
    error_log('organilog-api: ' . $e);
    AuditLog::write($audit + ['key' => $principal?->name, 'status' => 500, 'error' => 'internal']);
    respond(500, ['ok' => false, 'error' => ['code' => 'internal', 'message' => 'Internal error; see the PHP error log.']]);
}
