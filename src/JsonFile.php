<?php
declare(strict_types=1);

namespace Organilog;

/**
 * A JSON document on disk, changed under an exclusive lock. Shared hosting gives us no
 * database we own here and no shared memory, so this is the state store.
 */
final class JsonFile
{
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $lock = self::lock($path, LOCK_SH);
        try {
            return self::document($path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Read-modify-write under one lock. $change gets the current document and returns
     * [newDocument, valueToReturn].
     */
    public static function update(string $path, callable $change): mixed
    {
        $lock = self::lock($path, LOCK_EX);
        $temporary = null;
        $fh = null;
        try {
            [$doc, $result] = $change(self::document($path));
            $encoded = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            // Keep the replacement on the same filesystem so rename commits it atomically.
            $candidate = $path . '.tmp-' . bin2hex(random_bytes(8));
            $mask = umask(0077);
            try {
                $fh = @fopen($candidate, 'x+b');
            } finally {
                umask($mask);
            }
            if ($fh === false) {
                throw new ApiError(500, 'storage', 'cannot create replacement for ' . basename($path));
            }
            $temporary = $candidate;
            $offset = 0;
            $length = strlen($encoded);
            while ($offset < $length) {
                $written = @fwrite($fh, substr($encoded, $offset));
                if ($written === false || $written === 0) {
                    throw new ApiError(500, 'storage', 'cannot write ' . basename($path));
                }
                $offset += $written;
            }
            if (!fflush($fh)) {
                throw new ApiError(500, 'storage', 'cannot flush ' . basename($path));
            }
            // Preserve permissions for installations that share access through a group.
            if (is_file($path) && !chmod($temporary, fileperms($path) & 0777)) {
                throw new ApiError(500, 'storage', 'cannot preserve permissions for ' . basename($path));
            }
            fclose($fh);
            $fh = null;
            if (!@rename($temporary, $path)) {
                throw new ApiError(500, 'storage', 'cannot replace ' . basename($path));
            }
            $temporary = null;
            return $result;
        } finally {
            if (is_resource($fh)) fclose($fh);
            if ($temporary !== null) @unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** The lock must survive replacing the document's inode. Never unlink it. */
    private static function lock(string $path, int $mode): mixed
    {
        $lock = @fopen($path . '.lock', 'c+b');
        if ($lock === false) {
            throw new ApiError(500, 'storage', 'cannot open lock for ' . basename($path));
        }
        if (!flock($lock, $mode)) {
            fclose($lock);
            throw new ApiError(500, 'storage', 'cannot lock ' . basename($path));
        }
        return $lock;
    }

    private static function document(string $path): array
    {
        if (!is_file($path)) return [];
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ApiError(500, 'storage', 'cannot read ' . basename($path));
        }
        return $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
