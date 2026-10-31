<?php
declare(strict_types=1);

namespace Organilog;

/** data/catalog.json, generated from Organilog's docs by bin/build-catalog.mjs. Also the allowlist. */
final class Catalog
{
    public const KINDS = ['read', 'create', 'update', 'delete'];

    private array $data;
    private array $byId = [];

    public function __construct(string $file = ORGANILOG_ROOT . '/data/catalog.json')
    {
        $this->data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        foreach ($this->data['endpoints'] as $endpoint) {
            $this->byId[$endpoint['id']] = $endpoint;
        }
    }

    public function get(string $id): ?array
    {
        return $this->byId[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->byId[$id]);
    }

    public function isEntity(string $entity): bool
    {
        return isset($this->data['entities'][$entity]);
    }

    /** Upstream path for an endpoint id: "issue/read_all" -> "issue/read_all.php", "account" -> "account/". */
    public static function path(array $endpoint): string
    {
        return $endpoint['action'] === '' ? $endpoint['entity'] . '/' : "{$endpoint['entity']}/{$endpoint['action']}.php";
    }

    public function all(): array
    {
        return $this->data;
    }
}
