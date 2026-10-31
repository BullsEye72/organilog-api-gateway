<?php
declare(strict_types=1);

namespace Organilog;

/** Browser installation logic, separated from HTTP so failures can be tested offline. */
final class WebSetup
{
    public function __construct(
        private readonly string $root = ORGANILOG_ROOT,
        private readonly string $configFile = ORGANILOG_CONFIG_FILE,
        private readonly ?\Closure $accountProbe = null,
    ) {
    }

    public function locked(): bool
    {
        return file_exists($this->configFile) || is_link($this->configFile) || file_exists($this->root . '/var/installed.lock');
    }

    /** Ownership code is available only through the hosting file manager, outside public/. */
    public function ownershipCode(): string
    {
        $dir = $this->root . '/var';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Create a var folder beside public and allow PHP to write to it using your hosting file manager.');
        }
        $path = $dir . '/setup-key.txt';
        $mask = umask(0077);
        try {
            $handle = @fopen($path, 'x+b');
        } finally {
            umask($mask);
        }
        if ($handle !== false) {
            try {
                $code = bin2hex(random_bytes(24));
                if (!flock($handle, LOCK_EX) || fwrite($handle, $code) !== strlen($code) || !fflush($handle)) {
                    throw new \RuntimeException('Cannot save the installation code. Check var folder permissions.');
                }
            } finally {
                fclose($handle);
            }
        }
        $code = @file_get_contents($path);
        if (!is_string($code) || !preg_match('/^[a-f0-9]{48}$/D', $code)) {
            throw new \RuntimeException('Cannot read the installation code. Check var/setup-key.txt in your hosting file manager.');
        }
        return $code;
    }

    public function settings(array $input): array
    {
        $text = static function (string $key, int $max = 255) use ($input): string {
            if (!is_string($input[$key] ?? '') || strlen($input[$key] ?? '') > $max) {
                throw new \RuntimeException('One of the form values is invalid or too long.');
            }
            return trim($input[$key] ?? '');
        };
        $account = $text('account', 128);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/iD', $account)) {
            throw new \RuntimeException('Enter your Organilog account name (the part before .organilog.com), without a URL.');
        }
        $token = $text('token', 4096);
        if ($token === '') {
            throw new \RuntimeException('Enter your master Organilog API token.');
        }
        $name = $text('name', 320) ?: 'Organilog API';
        return Configuration::validate([
            'organilog' => ['account' => $account, 'token' => $token],
            'admin_key_sha256' => str_repeat('0', 64),
            'branding' => ['name' => $name, 'logo' => null],
        ]);
    }

    public function testOrganilog(array $config): void
    {
        try {
            [$status, $body, $raw] = array_pad($this->accountProbe === null
                ? (new Upstream($config['organilog']))->call('account/', [], false)
                : ($this->accountProbe)($config['organilog']), 3, '');
        } catch (ApiError $e) {
            if ($e->errorCode === 'upstream_cooldown') {
                throw new \RuntimeException('Organilog calls are temporarily paused. Try again in ' . (int) ($e->details['retry_after'] ?? 60) . ' seconds.');
            }
            throw new \RuntimeException($this->redact($e->getMessage(), $config));
        } catch (\Throwable) {
            throw new \RuntimeException('Could not reach Organilog. Check your internet access, PHP cURL certificates, and try again.');
        }
        $fields = is_array($body) ? array_change_key_case($body, CASE_LOWER) : [];
        $error = $fields['error'] ?? $fields['errors'] ?? null;
        $message = is_string($fields['message'] ?? null) ? $fields['message'] : '';
        $failed = $status < 200 || $status >= 300 || !is_array($body) || $body === []
            || UpstreamResponse::isError($body)
            || preg_match('/error|invalid|invalide|unauthori|incorrect|refus|denied|erreur/i', $message);
        if (!$failed) {
            return;
        }
        $reason = is_string($error) ? $error : ($fields['message'] ?? null);
        $detail = '';
        if (is_string($reason) && trim($reason) !== '') {
            // Display only the error/message field, never an account record or full response body.
            $reason = $this->redact($reason, $config);
            $reason = trim(preg_replace('/[\x00-\x1f\x7f]/', ' ', strip_tags($reason)));
            $detail = ' Organilog says: ' . mb_substr($reason, 0, 400);
        }
        $hint = match (true) {
            in_array($status, [401, 403], true) => 'Check the account name, master API token, and API access for this account.',
            $status === 429 => 'The Organilog request limit was reached. Wait before retrying.',
            $status >= 500 => 'Organilog is having a server problem. Try again later.',
            $status >= 300 && $status < 400 => 'Organilog redirected the request unexpectedly. Check the API URL or ask your hosting provider about outbound redirects.',
            !is_array($body) => 'The server returned ' . (preg_match('/^\s*(?:<!doctype\s+html|<html)/i', $raw) ? 'an HTML page' : 'an unreadable response') . ' instead of JSON. Your hosting provider may be filtering outgoing API requests.',
            default => 'Check the account name and master API token in your Organilog API settings.',
        };
        throw new \RuntimeException("Organilog connection test failed (HTTP $status).$detail $hint");
    }

    private function redact(string $message, array $config): string
    {
        $token = $config['organilog']['token'];
        return str_replace(array_unique([$token, rawurlencode($token), urlencode($token)]), '[redacted]', $message);
    }

    /** Reject executable/vector uploads; store only validated raster images outside public/. */
    public function logo(array $upload): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])
            || ($upload['size'] ?? 0) > 2 * 1024 * 1024) {
            throw new \RuntimeException('Choose a PNG, JPEG or WebP logo smaller than 2 MB.');
        }
        return self::image((string) file_get_contents($upload['tmp_name']));
    }

    public static function image(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        $extension = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
        if (strlen($bytes) > 2 * 1024 * 1024 || $extension === null
            || $info[0] > 4096 || $info[1] > 4096) {
            throw new \RuntimeException('Choose a PNG, JPEG or WebP logo up to 4096 pixels and smaller than 2 MB.');
        }
        return ['bytes' => $bytes, 'extension' => $extension];
    }

    public function finish(array $config, ?array $logo = null): string
    {
        $this->ownershipCode();
        $handle = @fopen($this->root . '/var/installing.lock', 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            throw new \RuntimeException('Another installation is in progress. Wait a moment and try again.');
        }
        $logoFile = null;
        $lockFile = $this->root . '/var/installed.lock';
        $marked = false;
        try {
            if ($this->locked()) {
                throw new \RuntimeException('This instance is already installed. Setup is locked.');
            }
            $this->testOrganilog($config);
            if ($logo !== null) {
                $logo = self::image($logo['bytes']);
                $name = 'logo-' . bin2hex(random_bytes(16)) . '.' . $logo['extension'];
                $logoFile = $this->root . '/var/' . $name;
                if (file_put_contents($logoFile, $logo['bytes'], LOCK_EX) !== strlen($logo['bytes'])) {
                    throw new \RuntimeException('Could not save the logo. Check var folder permissions.');
                }
                $config['branding']['logo'] = $name;
            }
            // Create the durable lock before committing config, so even a re-uploaded installer stays closed.
            $marker = @fopen($lockFile, 'x+b');
            if ($marker === false) {
                throw new \RuntimeException('Cannot create the installation lock. Check var folder permissions.');
            }
            fclose($marker);
            $marked = true;
            $secret = Setup::install($this->root, $config['organilog']['account'], $config['organilog']['token'], $this->configFile, $config);
            @unlink($this->root . '/var/setup-key.txt');
            return $secret;
        } catch (\Throwable $e) {
            if (!is_file($this->configFile)) {
                if ($marked) @unlink($lockFile);
                if ($logoFile !== null) @unlink($logoFile);
            }
            throw $e;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
