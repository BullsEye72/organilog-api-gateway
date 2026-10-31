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
        $fh = fopen($path, 'rb');
        flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        return $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Read-modify-write under one lock. $change gets the current document and returns
     * [newDocument, valueToReturn].
     */
    public static function update(string $path, callable $change): mixed
    {
        $fh = fopen($path, 'c+b');
        if ($fh === false) {
            throw new ApiError(500, 'storage', 'cannot open ' . basename($path));
        }
        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $doc = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            [$doc, $result] = $change($doc);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($fh);
            return $result;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
