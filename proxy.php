<?php
/**
 * NoRoot VPN Panel — Traffic Proxy (thin client to the session-broker daemon)
 *
 * GET (download): short-poll the daemon (fast, local) instead of holding
 * a slow, direct wait on Xray — the daemon does that waiting once, in the
 * background, regardless of how many short client GETs arrive.
 *
 * POST (upload): forwarded directly to Xray as before, but only after the
 * daemon has confirmed (ENSURE) that this session's download channel is
 * already open — replacing the old file-marker race with an authoritative
 * synchronous acknowledgement.
 *
 * This file must produce NO extraneous output — any stray byte corrupts
 * the proxied stream.
 */

error_reporting(0);
ini_set('display_errors', 0);

ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', 'off');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
    @apache_setenv('no-brotli', 1);
}
while (ob_get_level() > 0) { @ob_end_clean(); }
if (function_exists('ob_implicit_flush')) { ob_implicit_flush(true); }

ignore_user_abort(true);
set_time_limit(0);

header_remove('X-Powered-By');
header('X-Accel-Buffering: no');
header('X-LiteSpeed-Cache-Control: no-cache');
header('Cache-Control: no-cache, no-store');

// ============================================================
// Load config
// ============================================================
$configPath = __DIR__ . '/NoRoot-Config.json';
if (!file_exists($configPath)) { http_response_code(502); exit; }
$cfg = json_decode(file_get_contents($configPath), true);
if (!is_array($cfg) || empty($cfg['installed'])) { http_response_code(502); exit; }
$localPort = (int)($cfg['xray']['local_port'] ?? 0);
$daemonPort = (int)($cfg['xray']['daemon_port'] ?? 10001);
if ($localPort <= 0) { http_response_code(502); exit; }

// NOTE: a per-IP APCu-based rate limiter was tried here and removed — under
// real concurrent load (many active sessions, each polling every few
// seconds, exactly the pattern this whole relay is built to sustain) it
// meant every single request contended on a shared APCu key, and lock
// contention on that shared counter caused multi-second stalls under even
// moderate load. Given how central this file is to every connection, the
// existing NRVD_MAX_SESSIONS cap in daemon.php (500 sessions, checked
// in-memory with zero cross-request locking) remains the DoS safety net —
// it costs nothing per-request and doesn't share mutable state across
// concurrent requests the way an APCu counter does.

// NOTE: this file used to require the full ~90KB admin-panel library and
// call its watchdog (nrv_ensure_services_running) on every single request,
// purely for self-healing. That watchdog itself does a file read on every
// call (even when its own cooldown suppresses the actual check), and
// requiring the whole panel file adds parse/compile cost per request too.
// On a real, heavily process-constrained host, dozens of concurrent
// requests (one real page load) turned into dozens of concurrent large-file
// parses + file reads — a meaningful, measured contributor to host-wide
// slowdown, not just tunnel slowdown. Self-healing now relies entirely on
// admin page loads and cron.php (see Settings — guaranteed within ~1 minute
// independent of any web request), so this file stays fully self-contained
// and does no work beyond relaying bytes.

// ============================================================
// Determine the path/query to forward (full REQUEST_URI, unchanged —
// Xray's inbound path was configured at install time to match this
// script's real public path, subdirectory and all).
// ============================================================
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$forwardPath = $requestUri;
if ($forwardPath === '' || $forwardPath[0] !== '/') {
    $forwardPath = '/' . ltrim($forwardPath, '/');
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$sessionId = $_GET['x_session'] ?? null;

define('NRVP_DEBUG_LOG', __DIR__ . '/bin/proxy-debug.log');
$nrvpDebugStart = microtime(true);
$GLOBALS['nrvp_method'] = $_SERVER['REQUEST_METHOD'] ?? '?';
$GLOBALS['nrvp_forwardPath'] = $_SERVER['REQUEST_URI'] ?? '?';
$GLOBALS['nrvp_logged'] = false;

function nrvp_write_log($daemonNote, $status, $bytesOut, $extra = '') {
    if ($GLOBALS['nrvp_logged']) return; // avoid double-logging on normal completion
    $GLOBALS['nrvp_logged'] = true;
    $duration = round((microtime(true) - $GLOBALS['nrvp_debug_start']) * 1000);
    @file_put_contents(
        NRVP_DEBUG_LOG,
        sprintf(
            "[%s] %s %s | daemon=%s status=%s bytes_out=%s duration_ms=%d %s\n",
            date('H:i:s'), $GLOBALS['nrvp_method'], $GLOBALS['nrvp_forwardPath'],
            $daemonNote, $status, $bytesOut, $duration, $extra
        ),
        FILE_APPEND | LOCK_EX
    );
}
$GLOBALS['nrvp_debug_start'] = $nrvpDebugStart;

// GUARANTEED logging even on a fatal error / uncaught exception, so a
// silently-dying request is never invisible to us again.
register_shutdown_function(function () {
    if ($GLOBALS['nrvp_logged']) return;
    $err = error_get_last();
    $note = $err ? ('FATAL: ' . str_replace(["\r", "\n"], ' ', $err['message']) . ' @ ' . $err['file'] . ':' . $err['line']) : 'shutdown-without-explicit-log';
    nrvp_write_log('n/a', 'none', 0, $note);
});

// ============================================================
// Talk to the local daemon: send one command, read the full reply,
// close. Fast, local, and bounded.
// ============================================================
function nrvp_daemon_call($daemonPort, $command, $timeoutSec = 3) {
    $sock = @stream_socket_client("tcp://127.0.0.1:$daemonPort", $errno, $errstr, $timeoutSec);
    if (!$sock) return null;
    stream_set_timeout($sock, $timeoutSec);
    fwrite($sock, $command);
    $resp = '';
    while (!feof($sock)) {
        $chunk = fread($sock, 65536);
        if ($chunk === '' || $chunk === false) break;
        $resp .= $chunk;
    }
    fclose($sock);
    return $resp;
}

$nrvpDaemonOk = true;
$ensureResp = null;

if ($sessionId) {
    // The persistent download connection to Xray must be opened using the REAL
    // GET request's exact query string — Xray's xhttp inbound validates params
    // like x_padding that only a genuine client GET carries. A POST (upload)
    // request has different query params (x_seq, etc.) and must never be used
    // to open this connection; we still call ENSURE on POST so the daemon can
    // report whether a connection already exists, but with no path to open one.
    $realGetPath = ($method === 'GET') ? $forwardPath : '';
    $ensureResp = nrvp_daemon_call($daemonPort, 'ENSURE ' . $sessionId . ' ' . base64_encode($realGetPath) . "\n");
    // $ensureResp === null  -> couldn't even reach the daemon (not running / wrong port)
    // trim($ensureResp) !== 'OK' -> daemon reached Xray attempt but the connection itself failed
    $nrvpDaemonOk = ($ensureResp !== null && trim($ensureResp) === 'OK');
}

$nrvpStatusCode = null;
$nrvpBytesOut = 0;

// The daemon/download-channel check only matters for GET (the download
// direction it brokers). POST (upload) goes straight to Xray via curl below
// regardless of whether the download channel is open yet — in practice a
// client's upload POST commonly arrives before its first download GET, so
// gating uploads on download-channel state would wrongly 502 every upload
// until a GET happens to have landed first.
if ($method === 'GET' && !$nrvpDaemonOk) {
    http_response_code(502);
    $nrvpStatusCode = 502;
} elseif ($method === 'GET') {
    // xhttp's download channel is a single, persistently-streamed HTTP response —
    // not a short poll-once-and-return. We hold this response open, echoing and
    // flushing each new chunk into the SAME response, until the daemon reports
    // the session is dead, the client disconnects, or a wall-clock safety cap is
    // hit. Xray's client-side xmux pool is responsible for opening a
    // replacement GET when this one ends.
    //
    // Two DIFFERENT timeouts, on purpose — a single fixed cap punishes both
    // ends of a real trade-off:
    //   - Many concurrent, mostly-IDLE sessions (a page load, or a TUN-mode
    //     client tunneling a whole OS's traffic — DNS, heartbeats, background
    //     apps) need workers released FAST so the tiny shared worker budget
    //     can rotate across all of them. Confirmed on a real constrained
    //     host: a flat 6s cap was enough that two ordinary page loads
    //     saturated the account's ENTIRE process budget and stalled
    //     unrelated sites on the same account.
    //   - One session ACTIVELY streaming real bytes (a download) is doing
    //     useful work with its worker, not wasting it — cutting it off on
    //     the same short fixed cap just forces constant reconnects
    //     (fresh PHP process + ENSURE round trip each time) mid-transfer,
    //     which is wasted overhead AND can look like a stall/failure under
    //     heavy download load.
    // So: release fast when idle (protects the shared worker budget), but
    // let genuinely active transfers keep going much longer (protects
    // download throughput/stability) — governed by two separate clocks.
    $idleTimeoutSec = 1.5;   // no NEW data this long -> release the worker
    $absoluteMaxSec = 25;    // hard backstop regardless of activity
    $daemonCallTimeoutSec = 2; // safely above daemon.php's own 1s long-poll max wait
    $deadline = microtime(true) + $absoluteMaxSec;
    $lastActivity = microtime(true);
    $headerSent = false;
    while (microtime(true) < $deadline) {
        if (connection_aborted()) break;
        if (microtime(true) - $lastActivity > $idleTimeoutSec) break;
        $resp = nrvp_daemon_call($daemonPort, "GET $sessionId\n", $daemonCallTimeoutSec);
        if ($resp === null) {
            if ($nrvpStatusCode === null) { http_response_code(502); $nrvpStatusCode = 502; }
            break;
        }
        $nl = strpos($resp, "\n");
        $head = $nl !== false ? substr($resp, 0, $nl) : $resp;
        $headParts = explode(' ', trim($head));
        $status = $headParts[0] ?? 'DEAD';
        $len = isset($headParts[1]) ? (int)$headParts[1] : 0;
        $payload = $nl !== false ? substr($resp, $nl + 1, $len) : '';
        if (!$headerSent) {
            http_response_code(200);
            header('Content-Type: application/octet-stream');
            $headerSent = true;
            $nrvpStatusCode = 200;
        }
        if ($len > 0) {
            echo $payload;
            @flush();
            $nrvpBytesOut += $len;
            $lastActivity = microtime(true); // real data flowed — this worker is earning its keep, keep going
        }
        if ($status === 'DEAD') break;
        // No usleep here — the daemon call above already waited (via its own
        // long-poll) rather than returning instantly with nothing, so looping
        // straight back into the next call doesn't busy-spin.
    }

    if ($nrvpStatusCode === null) {
        http_response_code(200);
        header('Content-Type: application/octet-stream');
        $nrvpStatusCode = 200;
    }
} else {
    $body = file_get_contents('php://input');

    function nrvp_get_request_headers() {
        if (function_exists('getallheaders')) {
            $h = getallheaders();
            if (is_array($h)) return $h;
        }
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        if (isset($_SERVER['CONTENT_LENGTH'])) $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
        return $headers;
    }

    $stripHeaders = ['host', 'connection', 'content-length', 'accept-encoding', 'transfer-encoding', 'keep-alive', 'upgrade', 'proxy-connection'];
    $forwardHeaders = [];
    foreach (nrvp_get_request_headers() as $name => $value) {
        if (in_array(strtolower($name), $stripHeaders, true)) continue;
        $forwardHeaders[] = $name . ': ' . $value;
    }
    // Deliberately NOT adding X-Forwarded-For (or any other header revealing
    // that this request passed through a relay) — Xray doesn't need it for
    // VLESS/xhttp to function, and on a heavily-censored network, headers
    // that fingerprint traffic as "proxied" are exactly what DPI systems look
    // for. The request to Xray's local inbound should look as close as
    // possible to a plain, direct HTTP request.

    $targetUrl = 'http://127.0.0.1:' . $localPort . $forwardPath;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $targetUrl,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $forwardHeaders,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_LOW_SPEED_LIMIT => 1,
        CURLOPT_LOW_SPEED_TIME => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_HEADERFUNCTION => function ($ch, $headerLine) use (&$nrvpStatusCode) {
            $len = strlen($headerLine);
            $trimmed = trim($headerLine);
            if ($trimmed === '') return $len;
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $trimmed, $m)) {
                http_response_code((int)$m[1]);
                $nrvpStatusCode = (int)$m[1];
                return $len;
            }
            $colonPos = strpos($trimmed, ':');
            if ($colonPos === false) return $len;
            $name = trim(substr($trimmed, 0, $colonPos));
            $lname = strtolower($name);
            $skip = ['connection', 'transfer-encoding', 'content-length', 'keep-alive', 'upgrade'];
            if (in_array($lname, $skip, true)) return $len;
            header($name . ': ' . trim(substr($trimmed, $colonPos + 1)), false);
            return $len;
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$nrvpBytesOut) {
            echo $data;
            @flush();
            $nrvpBytesOut += strlen($data);
            return strlen($data);
        },
    ]);
    curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($nrvpStatusCode === null) {
        http_response_code(502);
        $nrvpStatusCode = 502;
        error_log('[NoRootVPN proxy] POST curl error ' . $curlErrno . ': ' . $curlError);
    }
}

// ---- debug log ----
nrvp_write_log(
    $nrvpDaemonOk ? 'ok' : ('FAIL(' . ($ensureResp === null ? 'unreachable' : trim($ensureResp)) . ')'),
    $nrvpStatusCode ?? 'none',
    $nrvpBytesOut
);

exit;
