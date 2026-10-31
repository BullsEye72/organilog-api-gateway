<?php
declare(strict_types=1);

namespace Organilog;

/** Organilog can report an application rejection inside an HTTP 200 response. */
final class UpstreamResponse
{
    public static function isError(mixed $body): bool
    {
        if (!is_array($body) || array_is_list($body)) {
            return false;
        }
        $fields = array_change_key_case($body, CASE_LOWER);
        return !empty($fields['error']) || !empty($fields['errors'])
            || ($fields['success'] ?? true) === false || ($fields['ok'] ?? true) === false
            || (is_scalar($fields['status'] ?? null)
                && in_array(strtolower((string) $fields['status']), ['error', 'failed', 'failure', 'unauthorized'], true))
            || (is_string($fields['message'] ?? null)
                && preg_match('/^\s*(?:error\b|invalid\b|invalide\b|unauthori[sz]ed\b|incorrect\b|refus|access denied\b|erreur\b)/i', $fields['message']) === 1);
    }
}
