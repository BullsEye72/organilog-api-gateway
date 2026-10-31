<?php
declare(strict_types=1);

namespace Organilog;

/** Who is calling: the admin key, or one of the per-tool keys. */
final class Principal
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $isAdmin,
        public readonly array $permissions = [],
        public readonly int $rateLimitPerMinute = 0,
    ) {
    }

    /**
     * Permissions only grant, never deny: an entity's own list adds to the "*" list.
     * {"*": ["read"], "issue": ["update"]} can read everything and update tickets.
     */
    public function can(string $entity, string $kind): bool
    {
        if ($this->isAdmin) {
            return true;
        }
        return in_array($kind, $this->permissions['*'] ?? [], true)
            || in_array($kind, $this->permissions[$entity] ?? [], true);
    }
}
