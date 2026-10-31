<?php
declare(strict_types=1);

namespace Organilog;

/**
 * Opt-in clean-up of Organilog's sentinels (?normalize=1). Raw output is the default, so a
 * tool always sees what Organilog actually sent.
 *
 *   "0000-00-00" / "0000-00-00 00:00:00" -> null   (empty date)
 *   "None"                               -> null   (estimated_hours, among others)
 *   fk_* = "0"                           -> null   (no relation)
 *   tickets also get status_label
 */
final class Normalize
{
    // Read off the Organilog UI, 2026-09-11. The API docs carry a status table too, but it
    // belongs to the Absence module and contradicts this one: never apply it to tickets.
    public const TICKET_STATUS = [
        '1' => 'Nouveau',
        '2' => 'En cours',
        '3' => 'Résolu',
        '4' => 'Attente de réponse',
        '5' => 'Fermé',
        '6' => 'Rejeté',
    ];

    public static function apply(mixed $value, string $entity): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = is_array($item) ? self::apply($item, $entity) : self::scalar((string) $key, $item);
        }
        if ($entity === 'issue' && isset($out['code_id']) && array_key_exists('status', $out)) {
            $out['status_label'] = self::TICKET_STATUS[(string) $out['status']] ?? null;
        }
        return $out;
    }

    public static function isEmpty(mixed $value): bool
    {
        return $value === null || in_array($value, ['', '0', 'None', '0000-00-00', '0000-00-00 00:00:00'], true);
    }

    private static function scalar(string $key, mixed $value): mixed
    {
        if ($value === '0000-00-00' || $value === '0000-00-00 00:00:00' || $value === 'None') {
            return null;
        }
        if ($value === '0' && str_starts_with($key, 'fk_')) {
            return null;
        }
        return $value;
    }
}
