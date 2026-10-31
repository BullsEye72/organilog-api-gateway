<?php
declare(strict_types=1);

namespace Organilog;

/** Shared circuit breaker. Locks cover state changes only, never network calls. */
final class UpstreamHealth
{
    private string $file;

    public function __construct(private readonly array $settings, ?string $file = null)
    {
        $this->file = $file ?? organilog_var('') . '/upstream-health.json';
    }

    /** Reserve the single recovery attempt after a cooldown. */
    public function acquire(): int
    {
        return JsonFile::update($this->file, function (array $s) {
            $now = time();
            $until = max($s['open_until'] ?? 0, $s['probe_until'] ?? 0);
            if ($until > $now) {
                throw new ApiError(503, 'upstream_cooldown',
                    'Organilog is temporarily unavailable; retry after the cooldown.',
                    ['retry_after' => $until - $now]);
            }
            if (!empty($s['open_until'])) {
                // A lease also recovers if a PHP worker dies during the probe.
                $s['generation'] = ($s['generation'] ?? 0) + 1;
                $s['probe_until'] = $now + max(1, (int) ($this->settings['timeout'] ?? 30)) + 5;
            }
            return [$s, (int) ($s['generation'] ?? 0)];
        });
    }

    public function record(int $generation, int $status, bool $transportFailed, int $ms, int $retryAfter = 0, bool $invalidResponse = false): void
    {
        JsonFile::update($this->file, function (array $s) use ($generation, $status, $transportFailed, $ms, $retryAfter, $invalidResponse) {
            // An older in-flight request must not undo a cooldown or a newer probe.
            if ($generation !== ($s['generation'] ?? 0)) {
                return [$s, null];
            }
            $now = time();
            $s['last_observed_at'] = $now;
            $s['last_ms'] = $ms;
            $s['last_http_status'] = $status ?: null;
            $failed = $transportFailed || $invalidResponse || $status === 429 || $status >= 500;
            if (!$failed) {
                if (!empty($s['open_until'])) {
                    $s['generation'] = $generation + 1;
                }
                $s['last_success_at'] = $now;
                $s['failures'] = 0;
                $s['open_until'] = 0;
                $s['probe_until'] = 0;
            } else {
                $previous = $s['last_failure']['at'] ?? 0;
                $s['failures'] = ($now - $previous <= 60 ? ($s['failures'] ?? 0) : 0) + 1;
                $s['last_failure'] = [
                    'at' => $now,
                    'code' => $transportFailed ? 'upstream_unreachable'
                        : ($invalidResponse ? 'upstream_invalid_response'
                        : ($status === 429 ? 'upstream_rate_limited' : 'upstream_server_error')),
                    'upstream_status' => $status ?: null,
                ];
                if (!empty($s['open_until']) || $status === 429 || $retryAfter > 0
                    || $s['failures'] >= max(1, (int) ($this->settings['failure_threshold'] ?? 2))) {
                    $s['open_until'] = $now + max(1, (int) ($this->settings['cooldown_seconds'] ?? 60), $retryAfter);
                    $s['probe_until'] = 0;
                    $s['generation'] = $generation + 1;
                }
            }
            return [$s, null];
        });
    }

    /** Passive status: polling this never contacts Organilog or reserves a probe. */
    public function snapshot(): array
    {
        $s = JsonFile::read($this->file);
        $now = time();
        $until = max($s['open_until'] ?? 0, $s['probe_until'] ?? 0);
        $state = 'unknown';
        if (($s['probe_until'] ?? 0) > $now) {
            $state = 'recovering';
        } elseif (($s['open_until'] ?? 0) > $now) {
            $state = 'unavailable';
        } elseif (!empty($s['open_until'])) {
            $state = 'retry_ready';
        } elseif ($now - ($s['last_observed_at'] ?? 0) <= 300) {
            $state = empty($s['failures']) ? 'healthy' : 'degraded';
        }
        return [
            'state' => $state,
            'retry_after' => max(0, $until - $now),
            'retry_at' => $until > $now ? gmdate(DATE_ATOM, $until) : null,
            'consecutive_failures' => $s['failures'] ?? 0,
            'last_observed_at' => isset($s['last_observed_at']) ? gmdate(DATE_ATOM, $s['last_observed_at']) : null,
            'last_success_at' => isset($s['last_success_at']) ? gmdate(DATE_ATOM, $s['last_success_at']) : null,
            'last_http_status' => $s['last_http_status'] ?? null,
            'last_ms' => $s['last_ms'] ?? null,
            'last_failure' => isset($s['last_failure']) ? [
                'at' => gmdate(DATE_ATOM, $s['last_failure']['at']),
                'code' => $s['last_failure']['code'],
                'upstream_status' => $s['last_failure']['upstream_status'],
            ] : null,
        ];
    }
}
