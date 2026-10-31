<?php
declare(strict_types=1);

namespace Organilog;

/**
 * Per-key rate limit and usage counters, one small file per key.
 *
 * The limit is a guard against a runaway tool (a Discord bot stuck in a loop) eating the
 * account's daily Organilog quota, which the docs put at 40,000 requests.
 */
final class Usage
{
    private string $dir;

    public function __construct()
    {
        $this->dir = organilog_var('usage');
    }

    public function hit(Principal $principal): void
    {
        $minute = date('Y-m-d H:i');
        $day = date('Y-m-d');
        $limit = $principal->rateLimitPerMinute;

        JsonFile::update($this->file($principal->id), function (array $u) use ($minute, $day, $limit, $principal) {
            $u['minute_count'] = ($u['minute'] ?? '') === $minute ? ($u['minute_count'] ?? 0) + 1 : 1;
            $u['minute'] = $minute;
            if ($limit > 0 && $u['minute_count'] > $limit) {
                throw new ApiError(429, 'rate_limited', "Key \"{$principal->name}\" is limited to $limit requests per minute.");
            }
            $u['day_count'] = ($u['day'] ?? '') === $day ? ($u['day_count'] ?? 0) + 1 : 1;
            $u['day'] = $day;
            $u['total'] = ($u['total'] ?? 0) + 1;
            $u['last_used_at'] = date(DATE_ATOM);
            return [$u, null];
        });
    }

    public function of(string $keyId): array
    {
        $u = JsonFile::read($this->file($keyId));
        return [
            'last_used_at' => $u['last_used_at'] ?? null,
            'today'        => ($u['day'] ?? '') === date('Y-m-d') ? ($u['day_count'] ?? 0) : 0,
            'total'        => $u['total'] ?? 0,
        ];
    }

    private function file(string $keyId): string
    {
        return $this->dir . '/' . preg_replace('/[^a-z0-9_]/i', '', $keyId) . '.json';
    }
}
