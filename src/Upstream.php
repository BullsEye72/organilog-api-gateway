<?php
declare(strict_types=1);

namespace Organilog;

/**
 * The only class that talks to Organilog. Credentials travel in the JSON body of a POST,
 * not in a header, and never leave this class.
 */
final class Upstream
{
    public int $calls = 0;
    private readonly UpstreamHealth $health;

    public function __construct(private readonly array $settings, ?UpstreamHealth $health = null)
    {
        $this->health = $health ?? new UpstreamHealth($settings);
    }

    /**
     * POST to "<entity>/<action>.php" and return [httpStatus, decodedBodyOrNull, rawBody].
     *
     * An optional read retry is disabled by default. Writes and invalid responses never
     * retry. Shared cooldowns reserve one recovery request when the pause expires.
     */
    public function call(string $path, array $payload, bool $retry): array
    {
        $body = json_encode(
            ['account' => $this->settings['account'], 'token' => $this->settings['token']] + $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $url = rtrim($this->settings['base_url'], '/') . '/' . ltrim($path, '/');

        for ($attempt = 1; ; $attempt++) {
            $generation = $this->health->acquire();
            $started = microtime(true);
            $retryAfter = 0;
            $this->calls++;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => (int) ($this->settings['connect_timeout'] ?? 5),
                CURLOPT_TIMEOUT        => (int) ($this->settings['timeout'] ?? 30),
                CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$retryAfter): int {
                    if (stripos($header, 'HTTP/') === 0) {
                        $retryAfter = 0;
                    } elseif (stripos($header, 'Retry-After:') === 0) {
                        $value = trim(substr($header, 12));
                        $retryAfter = ctype_digit($value) ? (int) $value : max(0, (strtotime($value) ?: time()) - time());
                    }
                    return strlen($header);
                },
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($ch);
            curl_close($ch);
            $decoded = $raw === false ? null : json_decode($raw, true);
            $validJson = $raw !== false && json_last_error() === JSON_ERROR_NONE;
            $invalidResponse = $raw !== false && $status >= 200 && $status < 300
                && (!$validJson || !is_array($decoded));
            $this->health->record($generation, $status, $raw === false,
                (int) round((microtime(true) - $started) * 1000),
                in_array($status, [429, 503], true) ? $retryAfter : 0, $invalidResponse);

            if ($invalidResponse) {
                $health = $this->health->snapshot();
                throw new ApiError(502, 'upstream_invalid_response',
                    'Organilog returned an invalid response; expected a JSON object or array.',
                    ['upstream_status' => $status]
                        + (!$retry ? ['write_outcome' => 'unknown'] : [])
                        + ($health['retry_after'] > 0 ? ['retry_after' => $health['retry_after']] : []));
            }

            if ($raw !== false) {
                return [$status, $validJson ? $decoded : null, $raw];
            }
            $health = $this->health->snapshot();
            if (!$retry || empty($this->settings['retry_reads']) || $attempt >= 2 || $health['retry_after'] > 0) {
                $error = self::connectionError($errno);
                throw new ApiError($error->status, $error->errorCode, $error->getMessage(), $error->details
                    + (!$retry ? ['write_outcome' => 'unknown'] : [])
                    + ($health['retry_after'] > 0 ? ['retry_after' => $health['retry_after']] : []));
            }
            usleep(2_000_000);
        }
    }

    /** Stable, actionable errors without credentials, request bodies, or local paths. */
    public static function connectionError(int $errno): ApiError
    {
        $message = match ($errno) {
            5, 6 => 'Your server could not resolve the Organilog API hostname. Ask your hosting provider to check DNS and outbound HTTPS access.',
            7 => 'Your server could not open an HTTPS connection to Organilog. Ask your hosting provider to check outgoing connections on port 443.',
            28 => 'The Organilog request timed out. Try again; if it continues, ask your hosting provider to check outbound HTTPS access.',
            51, 60, 77, 83, 90, 91 => 'Your server could not verify the Organilog HTTPS certificate. Ask your hosting provider to update PHP/cURL trusted CA certificates (curl.cainfo).',
            35, 58, 59 => 'The secure connection to Organilog failed. Ask your hosting provider to check PHP/cURL TLS support and certificates.',
            default => 'The connection to Organilog failed. Ask your hosting provider to check PHP/cURL and outbound HTTPS access.',
        };
        return new ApiError(502, 'upstream_unreachable', "$message (cURL $errno)", ['curl_errno' => $errno]);
    }
}
