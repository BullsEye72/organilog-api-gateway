<?php
declare(strict_types=1);

namespace Organilog;

// A controllable clock avoids sleeps when exercising cooldowns and abandoned probes.
function time(): int { return $GLOBALS['test_now']; }

require __DIR__ . '/../src/bootstrap.php';
$GLOBALS['test_now'] = \time();
$checks = 0;
function check(bool $value, string $message): void
{
    global $checks;
    if (!$value) throw new \RuntimeException($message);
    $checks++;
}
function blocked(UpstreamHealth $health): void
{
    try { $health->acquire(); } catch (ApiError $e) {
        check($e->status === 503 && $e->errorCode === 'upstream_cooldown' && $e->details['retry_after'] > 0, 'Fast rejection includes retry delay');
        return;
    }
    throw new \RuntimeException('Expected cooldown rejection');
}

$file = tempnam(sys_get_temp_dir(), 'organilog-health-');
$settings = ['timeout' => 1, 'failure_threshold' => 2, 'cooldown_seconds' => 60];
$health = new UpstreamHealth($settings, $file);
$otherWorker = new UpstreamHealth($settings, $file);
$process = null;
$log = tmpfile();
$responseState = tempnam(sys_get_temp_dir(), 'organilog-response-');
file_put_contents($responseState, '{}');
try {
    check($health->snapshot()['state'] === 'unknown', 'Fresh status is unknown');
    $old = $health->acquire();
    $health->record($old, 0, true, 5);
    check($health->snapshot()['state'] === 'degraded', 'First failure degrades');
    $health->record($otherWorker->acquire(), 503, false, 10);
    check($otherWorker->snapshot()['state'] === 'unavailable', 'Workers share cooldown');
    blocked($otherWorker);
    $health->record($old, 200, false, 1);
    check($health->snapshot()['state'] === 'unavailable', 'Late success cannot clear cooldown');
    $GLOBALS['test_now'] += 61;
    check($health->snapshot()['state'] === 'retry_ready', 'No automatic healthy status after expiry');
    $probe = $health->acquire();
    check($health->snapshot()['state'] === 'recovering', 'One probe gets a lease');
    blocked($otherWorker);
    $health->record($probe, 0, true, 10);
    check($health->snapshot()['state'] === 'unavailable', 'Failed probe reopens');
    $GLOBALS['test_now'] += 61;
    $abandoned = $health->acquire();
    $GLOBALS['test_now'] += 7;
    $replacement = $otherWorker->acquire();
    check($replacement !== $abandoned, 'Abandoned probe can be replaced');
    $health->record($abandoned, 200, false, 1);
    check($health->snapshot()['state'] === 'recovering', 'Abandoned probe cannot clear new lease');
    $otherWorker->record($replacement, 200, false, 1);
    check($health->snapshot()['state'] === 'healthy', 'Successful probe restores traffic');
    $GLOBALS['test_now'] += 301;
    check($health->snapshot()['state'] === 'unknown', 'Old observations expire');
    $health->record($health->acquire(), 0, true, 1);
    $GLOBALS['test_now'] += 61;
    $health->record($health->acquire(), 0, true, 1);
    check($health->snapshot()['state'] === 'degraded', 'Isolated failures do not accumulate forever');
    $health->record($health->acquire(), 422, false, 1);
    check($health->snapshot()['consecutive_failures'] === 0, 'Client errors prove reachability');

    // Exercise real cURL against a disposable local HTTP server.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = proc_open([PHP_BINARY, '-n', '-S', $address, __DIR__ . '/fixtures/upstream.php'],
        [0 => ['pipe', 'r'], 1 => $log, 2 => $log], $pipes, null,
        array_merge(getenv(), ['ORGANILOG_RESPONSE_FIXTURE_STATE' => $responseState]));
    fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(50_000);
    }
    check($ready, 'Local fixture server starts');
    $GLOBALS['test_now'] = \time();
    $options = $settings + ['account' => 'test', 'token' => 'test', 'base_url' => 'http://' . $address];
    file_put_contents($file, '{}');
    $upstream = new Upstream($options, $health);
    [$status, $body] = $upstream->call('ok', [], true);
    check($status === 200 && $body['fixture'], 'Successful JSON is unchanged');
    $upstream->call('bad_request', [], true);
    check($health->snapshot()['state'] === 'healthy', 'HTTP 422 does not trip breaker');
    $upstream->call('rate_limit', [], true);
    check($health->snapshot()['retry_after'] === 120, '429 trips immediately and honors Retry-After');
    $before = $upstream->calls;
    try { $upstream->call('ok', [], false); } catch (ApiError $e) {
        check($e->status === 503 && $e->errorCode === 'upstream_cooldown', 'Proxy fast-fails during cooldown');
    }
    check($upstream->calls === $before, 'Rejected call never reaches cURL');
    file_put_contents($file, '{}');
    $upstream->call('retry_date', [], true);
    check($health->snapshot()['retry_after'] >= 119, '503 with HTTP-date Retry-After pauses immediately');
    foreach (['html', 'malformed', 'empty', 'null', 'false', 'number', 'string'] as $scenario) {
        file_put_contents($file, '{}');
        $invalid = new Upstream(array_merge($options, ['base_url' => 'http://' . $address . '/response/' . $scenario, 'retry_reads' => true]), $health);
        $caught = false;
        try { $invalid->call('', [], true); } catch (ApiError $e) {
            $caught = $e->errorCode === 'upstream_invalid_response' && $e->details['upstream_status'] === 200;
        }
        check($caught && $invalid->calls === 1, 'Invalid successful response is rejected without retry: ' . $scenario);
        check($health->snapshot()['state'] === 'degraded', 'Invalid response updates shared availability state.');
    }
    file_put_contents($file, '{}');
    $invalidWrite = new Upstream(array_merge($options, ['base_url' => 'http://' . $address . '/response/html']), $health);
    $writeError = null;
    try { $invalidWrite->call('', [], false); } catch (ApiError $e) {
        $writeError = $e;
    }
    check(($writeError?->details['write_outcome'] ?? '') === 'unknown' && $invalidWrite->calls === 1, 'An invalid write reply must communicate uncertainty and never retry.');
    $catalog = new Catalog();
    $admin = new Principal('admin', 'admin', true);
    foreach (['verify_invalid', 'verify_http_error', 'verify_restore_invalid'] as $scenario) {
        file_put_contents($file, '{}');
        $client = new Upstream(array_merge($options, ['base_url' => 'http://' . $address . '/response/' . $scenario]), $health);
        $proxy = new Proxy(['allow_writes' => true, 'verify_updates' => true], $catalog, $client);
        [$resultStatus, $reply] = $proxy->handle($admin, 'issue/update', ['id' => '123', 'data' => ['name' => 'after']], false);
        if ($scenario === 'verify_restore_invalid') {
            check($resultStatus === 502 && !$reply['meta']['verification']['restored'], 'Unconfirmed restoration must report field loss without retrying blindly.');
        } else {
            check($resultStatus === 200 && $reply['meta']['write_outcome'] === 'acknowledged'
                && $reply['meta']['verification']['status'] === 'unknown', 'Verification failure must retain the acknowledged update result.');
        }
    }
    file_put_contents($file, '{}');
    $before = $upstream->calls;
    try { $upstream->call('slow', [], true); throw new \RuntimeException('Expected timeout'); }
    catch (ApiError $e) { check($e->errorCode === 'upstream_unreachable', 'Transport error contract preserved'); }
    check($upstream->calls === $before + 1, 'Reads do not automatically retry timeouts');
    check($health->snapshot()['state'] === 'degraded', 'Real timeout is recorded');
    echo "Passed $checks upstream health and response checks.\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    fclose($log);
    unlink($file);
    unlink($responseState);
}
