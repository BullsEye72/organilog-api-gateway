<?php
declare(strict_types=1);

namespace Organilog;

/**
 * Forwards one catalog endpoint to Organilog on behalf of a key: allowlist, permission,
 * server write switch, then the call, then (for updates) a check that nothing was lost.
 */
final class Proxy
{
    // Server-owned fields: never written back during a restore.
    private const NON_WRITABLE = ['id', 'code_id', 'added_date', 'last_modif_date', 'last_update'];

    public function __construct(
        private readonly array $config,
        private readonly Catalog $catalog,
        private readonly Upstream $upstream,
    ) {
    }

    /** @return array{0: int, 1: array} HTTP status and response body */
    public function handle(Principal $principal, string $endpointId, array $payload, bool $normalize): array
    {
        $endpoint = $this->catalog->get($endpointId)
            ?? throw new ApiError(404, 'unknown_endpoint', "\"$endpointId\" is not an Organilog endpoint in the catalog.");
        $kind = $endpoint['kind'];

        if (!$principal->can($endpoint['entity'], $kind)) {
            throw new ApiError(403, 'forbidden', "Key \"{$principal->name}\" may not $kind {$endpoint['entity']}.");
        }
        if ($kind !== 'read' && empty($this->config['allow_writes'])) {
            throw new ApiError(403, 'writes_disabled', 'Writes are switched off on this server (allow_writes in config.php).');
        }
        if (($kind === 'update' || $kind === 'delete') && !isset($payload['id'])) {
            throw new ApiError(422, 'missing_id', "$endpointId needs \"id\" (the internal id).");
        }
        unset($payload['account'], $payload['token']);

        $verify = $kind === 'update'
            && !empty($this->config['verify_updates'])
            && $this->catalog->has("{$endpoint['entity']}/read_multiple");
        $before = $verify ? $this->snapshot($endpoint['entity'], (string) $payload['id']) : null;

        $started = microtime(true);
        [$status, $decoded, $raw] = $this->upstream->call(Catalog::path($endpoint), $payload, $kind === 'read');
        $ms = (int) round((microtime(true) - $started) * 1000);

        $applicationError = UpstreamResponse::isError($decoded);
        $ok = $status >= 200 && $status < 300 && !$applicationError;
        $meta = ['upstream_status' => $status, 'upstream_ms' => $ms];

        if ($kind === 'update' && $ok) {
            $meta['verification'] = $verify
                ? $this->verify($endpoint, (string) $payload['id'], $before, $payload['data'] ?? [])
                : ['status' => 'skipped', 'reason' => 'no read_multiple endpoint to compare against'];
            // Keep the acknowledged write response even if a later verification read fails.
            if ($meta['verification']['status'] === 'unknown') {
                $meta['write_outcome'] = 'acknowledged';
            }
        }

        $body = [
            'ok'       => $ok,
            'endpoint' => $endpointId,
            'kind'     => $kind,
            'data'     => $decoded === null ? null : ($normalize ? Normalize::apply($decoded, $endpoint['entity']) : $decoded),
        ];
        if ($decoded === null) {
            $body['raw'] = mb_substr((string) $raw, 0, 5000);
        }
        $body['meta'] = $meta + ['upstream_calls' => $this->upstream->calls, 'key' => $principal->name];
        if ($applicationError) {
            $body['error'] = ['code' => 'upstream_rejected', 'message' => 'Organilog rejected the request; see data for its response.'];
        }

        if (($meta['verification']['status'] ?? null) === 'fields_lost') {
            $body['ok'] = false;
            return [502, $body];
        }
        return [$ok ? 200 : 502, $body];
    }

    private function snapshot(string $entity, string $id): ?array
    {
        [$status, $decoded] = $this->upstream->call("$entity/read_multiple.php", ['ids' => $id], true);
        if ($status !== 200 || !is_array($decoded) || UpstreamResponse::isError($decoded)) {
            return null;
        }
        $rows = $decoded['results'] ?? (array_is_list($decoded) ? $decoded : []);
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === $id) {
                return $row;
            }
        }
        return null;
    }

    /**
     * organilog_manager proved issue/update merges rather than replaces, but only for tickets,
     * and the docs say nothing for any entity. So: compare every field we did not send, and if
     * one went from filled to empty, write the old values back and report the failure loudly.
     */
    private function verify(array $endpoint, string $id, ?array $before, mixed $sent): array
    {
        if ($before === null) {
            return ['status' => 'skipped', 'reason' => 'could not read the record before writing'];
        }
        try {
            $after = $this->snapshot($endpoint['entity'], $id);
        } catch (ApiError $e) {
            if (!str_starts_with($e->errorCode, 'upstream_')) {
                throw $e;
            }
            return [
                'status' => 'unknown',
                'reason' => 'Organilog acknowledged the update, but the verification read failed.',
                'error' => ['code' => $e->errorCode] + $e->details,
            ];
        }
        if ($after === null) {
            return ['status' => 'unknown', 'reason' => 'could not read the record back after writing'];
        }

        $sent = is_array($sent) ? $sent : [];
        $lost = [];
        foreach ($before as $field => $value) {
            if (in_array($field, self::NON_WRITABLE, true) || array_key_exists($field, $sent) || is_array($value)) {
                continue;
            }
            if (!Normalize::isEmpty($value) && Normalize::isEmpty($after[$field] ?? null)) {
                $lost[$field] = $value;
            }
        }
        if (!$lost) {
            return ['status' => 'verified'];
        }

        // Losing untouched fields means this entity replaces rather than merges, so writing back
        // only the lost fields would wipe the rest again. Send the whole intended record instead:
        // the snapshot with the caller's changes on top.
        $intended = $sent;
        foreach ($before as $field => $value) {
            if (!in_array($field, self::NON_WRITABLE, true) && !is_array($value) && !array_key_exists($field, $intended)) {
                $intended[$field] = $value;
            }
        }
        try {
            [$restoreStatus, $restoreBody] = $this->upstream->call(Catalog::path(['entity' => $endpoint['entity'], 'action' => 'update']), ['id' => $id, 'data' => $intended], false);
            if ($restoreStatus < 200 || $restoreStatus >= 300 || UpstreamResponse::isError($restoreBody)) {
                throw new ApiError(502, 'upstream_rejected', 'Organilog rejected the restoration.');
            }
            $restored = $this->snapshot($endpoint['entity'], $id);
        } catch (ApiError $e) {
            if (!str_starts_with($e->errorCode, 'upstream_')) {
                throw $e;
            }
            return [
                'status' => 'fields_lost',
                'lost_fields' => array_keys($lost),
                'restored' => false,
                'reason' => 'Could not confirm restoration; do not retry the update blindly.',
                'error' => ['code' => $e->errorCode] + $e->details,
            ];
        }
        $stillLost = array_keys(array_filter(
            $intended,
            fn ($v, $f) => !is_array($v) && (string) ($restored[$f] ?? '') !== (string) $v,
            ARRAY_FILTER_USE_BOTH
        ));

        return [
            'status'      => 'fields_lost',
            'lost_fields' => array_keys($lost),
            'restored'    => $restored !== null && !$stillLost,
            'still_lost'  => $stillLost,
        ];
    }
}
