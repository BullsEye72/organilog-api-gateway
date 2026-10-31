<?php
declare(strict_types=1);

namespace Organilog;

/**
 * Per-tool API keys in var/keys.json. Only a sha256 of each key is stored: the keys are
 * 192 random bits, so a fast hash is enough (bcrypt is for guessable passwords) and lets us
 * look a key up without trying every hash.
 */
final class KeyStore
{
    private const PREFIX = 'olk_';

    private string $file;

    public function __construct(private readonly array $config, private readonly Catalog $catalog)
    {
        $this->file = organilog_var('') . '/keys.json';
    }

    public static function generate(): string
    {
        return self::PREFIX . bin2hex(random_bytes(24));
    }

    public function authenticate(?string $secret): Principal
    {
        if ($secret === null || $secret === '') {
            throw new ApiError(401, 'missing_key', 'Send your key as "Authorization: Bearer <key>" or "X-Api-Key: <key>".');
        }
        $hash = hash('sha256', $secret);

        $adminHash = (string) ($this->config['admin_key_sha256'] ?? '');
        if ($adminHash !== '' && hash_equals($adminHash, $hash)) {
            return new Principal('admin', 'admin', true);
        }

        foreach (JsonFile::read($this->file) as $key) {
            if (hash_equals($key['hash'], $hash)) {
                if (!$key['enabled']) {
                    throw new ApiError(403, 'key_disabled', "Key \"{$key['name']}\" is disabled.");
                }
                return new Principal($key['id'], $key['name'], false, $key['permissions'], $key['rate_limit_per_minute']);
            }
        }
        throw new ApiError(401, 'invalid_key', 'Unknown API key.');
    }

    /** Every key, without its hash. */
    public function all(): array
    {
        return array_map(fn (array $key) => $this->public($key), array_values(JsonFile::read($this->file)));
    }

    /** @return array{0: string, 1: array} the new secret (shown once) and the record */
    public function create(array $input): array
    {
        $secret = self::generate();
        $record = $this->validated($input, [
            'id'                    => 'k_' . bin2hex(random_bytes(4)),
            'name'                  => '',
            'notes'                 => '',
            'enabled'               => true,
            'permissions'           => [],
            'rate_limit_per_minute' => 60,
            'prefix'                => substr($secret, 0, 10),
            'hash'                  => hash('sha256', $secret),
            'created_at'            => date(DATE_ATOM),
            'rotated_at'            => null,
        ]);
        if ($record['name'] === '') {
            throw new ApiError(422, 'invalid_key_definition', '"name" is required.');
        }
        JsonFile::update($this->file, function (array $keys) use ($record) {
            foreach ($keys as $key) {
                if (strcasecmp($key['name'], $record['name']) === 0) {
                    throw new ApiError(409, 'duplicate_name', "A key named \"{$record['name']}\" already exists.");
                }
            }
            $keys[$record['id']] = $record;
            return [$keys, null];
        });
        return [$secret, $this->public($record)];
    }

    public function update(string $id, array $input): array
    {
        return JsonFile::update($this->file, function (array $keys) use ($id, $input) {
            $keys[$id] = $this->validated($input, $this->find($keys, $id));
            return [$keys, $this->public($keys[$id])];
        });
    }

    /** New secret for an existing key; the old one stops working immediately. */
    public function rotate(string $id): array
    {
        $secret = self::generate();
        $record = JsonFile::update($this->file, function (array $keys) use ($id, $secret) {
            $key = $this->find($keys, $id);
            $key['prefix'] = substr($secret, 0, 10);
            $key['hash'] = hash('sha256', $secret);
            $key['rotated_at'] = date(DATE_ATOM);
            $keys[$id] = $key;
            return [$keys, $this->public($key)];
        });
        return [$secret, $record];
    }

    public function delete(string $id): array
    {
        return JsonFile::update($this->file, function (array $keys) use ($id) {
            $key = $this->find($keys, $id);
            unset($keys[$id]);
            return [$keys, $this->public($key)];
        });
    }

    private function find(array $keys, string $id): array
    {
        return $keys[$id] ?? throw new ApiError(404, 'unknown_key', "No key with id $id.");
    }

    private function public(array $key): array
    {
        unset($key['hash']);
        return $key;
    }

    /** Apply the editable fields of $input onto $record, rejecting anything malformed. */
    private function validated(array $input, array $record): array
    {
        $bad = fn (string $why) => new ApiError(422, 'invalid_key_definition', $why);

        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '' || strlen($name) > 64) {
                throw $bad('"name" must be 1-64 characters.');
            }
            $record['name'] = $name;
        }
        if (array_key_exists('notes', $input)) {
            $record['notes'] = mb_substr((string) $input['notes'], 0, 500);
        }
        if (array_key_exists('enabled', $input)) {
            $record['enabled'] = (bool) $input['enabled'];
        }
        if (array_key_exists('rate_limit_per_minute', $input)) {
            $limit = $input['rate_limit_per_minute'];
            if (!is_int($limit) || $limit < 0 || $limit > 10000) {
                throw $bad('"rate_limit_per_minute" must be an integer from 0 (unlimited) to 10000.');
            }
            $record['rate_limit_per_minute'] = $limit;
        }
        if (array_key_exists('permissions', $input)) {
            if (!is_array($input['permissions'])) {
                throw $bad('"permissions" must be an object like {"*": ["read"], "issue": ["read", "update"]}.');
            }
            $permissions = [];
            foreach ($input['permissions'] as $entity => $kinds) {
                $entity = (string) $entity;
                if ($entity !== '*' && !$this->catalog->isEntity($entity)) {
                    throw $bad("Unknown entity \"$entity\".");
                }
                if (!is_array($kinds) || array_diff($kinds, Catalog::KINDS)) {
                    throw $bad("Permissions for \"$entity\" must be a list drawn from: " . implode(', ', Catalog::KINDS) . '.');
                }
                if ($kinds) {
                    $permissions[$entity] = array_values(array_intersect(Catalog::KINDS, $kinds));
                }
            }
            ksort($permissions);
            $record['permissions'] = $permissions;
        }
        return $record;
    }
}
