<?php
/**
 * NoRoot VPN Panel — Persistent Session Broker Daemon
 *
 * Runs as a long-lived PHP CLI process (started via setsid/nohup, same
 * pattern as Xray itself). Solves three problems that plague a pure
 * per-web-request PHP relay for the xhttp download channel:
 *
 *  1) WORKER EXHAUSTION — a web-triggered PHP process no longer needs to
 *     hold itself open for 8-40 seconds waiting on a slow round trip;
 *     that single long-lived wait now happens ONCE here, off the shared
 *     host's tiny concurrent-process budget entirely.
 *
 *  2) GET/POST ORDERING RACE — this daemon opens the real, persistent
 *     xhttp download connection to Xray SYNCHRONOUSLY when asked (the
 *     ENSURE command) and only acknowledges once that connection attempt
 *     has actually started, so a POST that waits for that ack can never
 *     race ahead of it the way two independent per-request curl calls could.
 *
 *  3) DATA LOSS BETWEEN SHORT POLLS — this daemon keeps draining Xray's
 *     response continuously into an in-memory per-session buffer whether
 *     or not any web request is currently asking for it, so nothing is
 *     lost between one short client GET and the next.
 *
 * Control protocol (plain text, one command per connection, newline-terminated):
 *   ENSURE <sessionId> <base64(getPath)>\n   -> replies "OK\n"
 *   GET <sessionId>\n                        -> replies "<STATUS> <LEN>\n<LEN bytes>"
 *                                                STATUS is OK | DEAD
 *
 * GET is a LONG-POLL, not an instant poll: if there's no buffered data yet
 * and the session is still alive, this connection is held open (not
 * responded to) until either new data arrives from Xray or a bounded max
 * wait elapses (NRVD_PENDING_GET_MAX_WAIT_SEC) — see $pendingGets below.
 * This is what makes forwarding latency bounded by real data arrival instead
 * of an artificial fixed poll interval, while also cutting the number of
 * control-connection round trips (and their syscall/CPU cost) dramatically
 * under load — both matter on a CPU/process-limited shared host.
 */

$baseDir = __DIR__;
$cfgFile = $baseDir . '/NoRoot-Config.json';
$cfg = json_decode(@file_get_contents($cfgFile), true);
if (!is_array($cfg)) { file_put_contents($baseDir . '/daemon-fatal.log', 'could not read config: ' . $cfgFile . "\n"); exit(1); }
$binDir = $cfg['paths']['base_dir'];
$logFile = $binDir . '/daemon.log';
$pidFile = $binDir . '/daemon.pid';

function nrvd_log($msg) {
    global $logFile;
    @file_put_contents($logFile, date('H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
}

$localPort = (int)$cfg['xray']['local_port'];
$daemonPort = (int)($cfg['xray']['daemon_port'] ?? 10001);

define('NRVD_MAX_SESSIONS', 500);
define('NRVD_CONNECT_RETRY_BACKOFF', [1, 2, 4, 8, 15]);
define('NRVD_CONNECT_MAX_ATTEMPTS', 5);
// On a CPU-throttled shared host, Xray can genuinely take longer to produce
// response headers under load than on a fast/local box — too short a
// timeout here kills and retries a connection that was just slow, not dead,
// which looks like random instability to the end user even though nothing
// actually failed. 25s is generous enough to absorb real CPU throttling
// while still being well under the idle-GC windows below.
define('NRVD_HEADER_STALL_TIMEOUT_SEC', 25);
define('NRVD_IDLE_GC_SEC', 90);
define('NRVD_FAILED_IDLE_GC_SEC', 15);
// Every proxy.php GET request holding this long-poll open ties up one full
// PHP worker process (lsphp/PHP-FPM) on the web server for the ENTIRE wait.
// A real page load needs many concurrent resource requests, each its own
// VLESS/xhttp session — and shared hosting typically caps concurrent PHP
// workers for an account very low. A long wait here (previously 8s, and
// proxy.php's own cap let it run up to 20-28s in testing) means only a
// handful of a page's ~10-50 concurrent requests can be served before the
// account's worker budget is exhausted and the rest fail outright — this is
// the actual cause of "a normal page won't load." Keeping this short lets
// far more concurrent sessions fit inside the same tiny worker budget, at
// the cost of the client reconnecting its GET more often (cheap: it's a
// bounded wait, not a busy loop, and each reconnect returns instantly once
// real data exists).
define('NRVD_PENDING_GET_MAX_WAIT_SEC', 4);
// Hard cap on how much unread data a single session may buffer in memory.
// Without this, a session whose client-side GET can't keep up (e.g. a large
// download stuck behind worker exhaustion) would let nrvd_process_bytes grow
// $s['buf'] without bound — on a memory-limited shared host that's a real
// crash risk (architectural, not tunable away with a timeout). Once a
// session hits this cap, its Xray socket is excluded from the read set below
// until the buffer drains — real TCP backpressure: Xray's own write to that
// socket eventually blocks, which naturally throttles the upstream source
// too, instead of us just accumulating unbounded memory.
define('NRVD_MAX_SESSION_BUFFER_BYTES', 1 * 1024 * 1024);
// NOTE: a periodic in-loop Xray liveness/restart check was tried here and
// removed — it called nrv_xray_pid()/nrv_xray_start() (which can block on
// exec() and a hardcoded 600ms usleep) directly inside this single-threaded
// stream_select() loop, freezing EVERY active session's I/O each time it
// ran. Self-healing belongs in proxy.php's nrv_ensure_services_running()
// (cooldown-limited, runs in its own short-lived per-request process) and in
// cron.php — never in this process, which must never block.

nrvd_log("daemon starting, pid=" . getmypid() . ", local_port=$localPort, daemon_port=$daemonPort");

$server = @stream_socket_server("tcp://127.0.0.1:$daemonPort", $errno, $errstr);
if (!$server) {
    // Do NOT write the pidfile here — if we did, a failed bind (e.g. because a
    // still-healthy earlier daemon instance already holds this port) would
    // overwrite the pidfile with OUR dead pid, orphaning the real running
    // instance with nothing left tracking it. Every later Start/Restart click
    // would then spawn yet another doomed duplicate. Leave any existing
    // pidfile exactly as it is so it keeps pointing at whatever is actually
    // alive and holding the port.
    nrvd_log("FATAL: bind failed: $errstr ($errno)");
    exit(1);
}
// Only now, after a successful bind, is it safe to claim the pidfile.
file_put_contents($pidFile, getmypid());
stream_set_blocking($server, false);

$sessions = [];      // id => [sock, buf, partial, headerDone, chunked, remaining, closed, lastActivity]
$controlConns = [];  // (int)resource => ['sock'=>resource, 'buf'=>string]
$pendingGets = [];   // sessionId => ['sock'=>resource, 'since'=>timestamp] — see long-poll note above

// Sends the current buffered state for $sid to a waiting control connection
// and closes it. Used both by the immediate-response fast path (data/dead
// already known at GET time) and by the long-poll fulfillment paths below.
function nrvd_respond_get(&$sessions, $sid, $sock) {
    $data = $sessions[$sid]['buf'] ?? '';
    if (isset($sessions[$sid])) $sessions[$sid]['buf'] = '';
    $isDead = !isset($sessions[$sid]) || ($sessions[$sid]['closed'] && $data === '');
    $status = $isDead ? 'DEAD' : 'OK';
    @fwrite($sock, $status . ' ' . strlen($data) . "\n" . $data);
    @fclose($sock);
}

// Xray's xhttp inbound rejects a GET with a 400 unless its x_padding query
// param is at least ~100 bytes (empirically confirmed: <100 -> 400, >=100 ->
// 200; this floor is enforced server-side regardless of the xhttpSettings
// extra.xPaddingBytes config, which does not appear to change this specific
// check in this Xray-core build). This download-channel connection is one WE
// hand-craft, so we must supply padding ourselves rather than relying on
// whatever the real client's own GET happened to include.
define('NRVD_X_PADDING_LEN', 128);

function nrvd_ensure_x_padding($path) {
    $pad = str_repeat('A', NRVD_X_PADDING_LEN);
    return $path . (strpos($path, '?') !== false ? '&' : '?') . 'x_padding=' . $pad;
}

function nrvd_open_xray_get($localPort, $forwardPath) {
    $sock = @stream_socket_client("tcp://127.0.0.1:$localPort", $errno, $errstr, 5);
    if (!$sock) {
        nrvd_log("connect to 127.0.0.1:$localPort FAILED errno=$errno errstr=$errstr");
        return null;
    }
    stream_set_blocking($sock, false);
    $paddedPath = nrvd_ensure_x_padding($forwardPath);
    $req = "GET $paddedPath HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: keep-alive\r\n\r\n";
    fwrite($sock, $req);
    return $sock;
}

function nrvd_process_bytes(&$s, $chunk) {
    $s['partial'] .= $chunk;

    if (!$s['headerDone']) {
        $pos = strpos($s['partial'], "\r\n\r\n");
        if ($pos === false) return;
        $headers = substr($s['partial'], 0, $pos);
        $s['partial'] = substr($s['partial'], $pos + 4);
        $s['headerDone'] = true;
        $s['chunked'] = (stripos($headers, 'Transfer-Encoding: chunked') !== false);
        if (!$s['chunked'] && preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) {
            $s['remaining'] = (int)$m[1];
        }
    }

    if ($s['chunked']) {
        while (true) {
            $eol = strpos($s['partial'], "\r\n");
            if ($eol === false) return;
            $size = hexdec(trim(substr($s['partial'], 0, $eol)));
            if ($size === 0) { $s['closed'] = true; return; }
            if (strlen($s['partial']) < $eol + 2 + $size + 2) return;
            $s['buf'] .= substr($s['partial'], $eol + 2, $size);
            $s['partial'] = substr($s['partial'], $eol + 2 + $size + 2);
        }
    } elseif ($s['remaining'] !== null) {
        $take = min(strlen($s['partial']), $s['remaining']);
        $s['buf'] .= substr($s['partial'], 0, $take);
        $s['partial'] = substr($s['partial'], $take);
        $s['remaining'] -= $take;
        if ($s['remaining'] <= 0) { $s['closed'] = true; }
    } else {
        $s['buf'] .= $s['partial'];
        $s['partial'] = '';
    }
}

$lastGc = time();

while (true) {
    $read = [$server];
    foreach ($controlConns as $cc) { $read[] = $cc['sock']; }
    foreach ($sessions as $s) {
        // Backpressure: stop reading further from a session's Xray socket
        // once its buffer is already at cap — see NRVD_MAX_SESSION_BUFFER_BYTES.
        if ($s['sock'] && !$s['closed'] && strlen($s['buf']) < NRVD_MAX_SESSION_BUFFER_BYTES) {
            $read[] = $s['sock'];
        }
    }
    // Watch pending long-poll sockets too, purely so we notice if the client
    // (proxy.php) disconnects early (e.g. request aborted) and can clean up
    // instead of leaking the entry until its max-wait timeout.
    foreach ($pendingGets as $pg) { $read[] = $pg['sock']; }

    $write = null; $except = null;
    $n = @stream_select($read, $write, $except, 0, 150000);
    if ($n === false) { usleep(50000); continue; }

    foreach ($read as $r) {
        // Fault isolation: a bug or unexpected input while handling ONE
        // connection must never be able to crash this whole process and take
        // down every OTHER active session with it — that would turn a single
        // malformed request into an outage for every user. Log and move on.
        try {
        if ($r === $server) {
            $c = @stream_socket_accept($server, 0);
            if ($c) { stream_set_blocking($c, false); $controlConns[(int)$c] = ['sock' => $c, 'buf' => '']; }
            continue;
        }

        $handledAsControl = false;
        foreach ($controlConns as $key => $cc) {
            if ($cc['sock'] !== $r) continue;
            $handledAsControl = true;

            $chunk = @fread($r, 65536);
            if ($chunk === '' || $chunk === false) {
                @fclose($r);
                unset($controlConns[$key]);
                break;
            }
            $controlConns[$key]['buf'] .= $chunk;

            $nl = strpos($controlConns[$key]['buf'], "\n");
            if ($nl === false) break; // wait for the rest of the line

            $line = substr($controlConns[$key]['buf'], 0, $nl);
            $parts = explode(' ', trim($line));
            $cmd = $parts[0] ?? '';
            $sid = $parts[1] ?? '';

            if ($cmd === 'ENSURE' && $sid !== '') {
                $pathB64 = $parts[2] ?? '';
                $forwardPath = $pathB64 !== '' ? base64_decode($pathB64) : null;
                $now = time();

                if (!isset($sessions[$sid])) {
                    if (count($sessions) >= NRVD_MAX_SESSIONS) {
                        nrvd_log("ENSURE $sid REJECTED: session cap (" . NRVD_MAX_SESSIONS . ") reached");
                        @fwrite($r, "FAIL\n");
                        @fclose($r);
                        unset($controlConns[$key]);
                        break;
                    }
                    $sessions[$sid] = [
                        'sock' => null, 'buf' => '', 'partial' => '', 'headerDone' => false,
                        'chunked' => false, 'remaining' => null,
                        'closed' => true, 'connectFailed' => true,
                        'connectAttempts' => 0, 'nextRetryAt' => 0, 'permanentlyFailed' => false,
                        'forwardPath' => $forwardPath, 'sockOpenedAt' => null,
                        'lastActivity' => $now,
                    ];
                }

                $s = &$sessions[$sid];
                if ($forwardPath) $s['forwardPath'] = $forwardPath; // keep latest, in case it changes across retries
                $shouldRetry = $s['sock'] === null && !$s['permanentlyFailed'] && $now >= $s['nextRetryAt'] && $s['forwardPath'];
                if ($shouldRetry) {
                    $s['connectAttempts']++;
                    $xsock = nrvd_open_xray_get($localPort, $s['forwardPath']);
                    if ($xsock) {
                        $s['sock'] = $xsock;
                        $s['closed'] = false;
                        $s['connectFailed'] = false;
                        $s['sockOpenedAt'] = $now;
                        nrvd_log("ENSURE $sid connected on attempt {$s['connectAttempts']}");
                    } else {
                        $backoff = NRVD_CONNECT_RETRY_BACKOFF[min($s['connectAttempts'] - 1, count(NRVD_CONNECT_RETRY_BACKOFF) - 1)];
                        $s['nextRetryAt'] = $now + $backoff;
                        $s['connectFailed'] = true;
                        if ($s['connectAttempts'] >= NRVD_CONNECT_MAX_ATTEMPTS) {
                            $s['permanentlyFailed'] = true;
                            nrvd_log("ENSURE $sid permanently failed after {$s['connectAttempts']} attempts");
                        } else {
                            nrvd_log("ENSURE $sid attempt {$s['connectAttempts']} failed, retry in {$backoff}s");
                        }
                    }
                }
                $s['lastActivity'] = $now;
                @fwrite($r, empty($s['connectFailed']) ? "OK\n" : "FAIL\n");
                unset($s);
            } elseif ($cmd === 'GET' && $sid !== '') {
                if (isset($sessions[$sid])) {
                    $sessions[$sid]['lastActivity'] = time();
                    $hasData = $sessions[$sid]['buf'] !== '';
                    $isDead = $sessions[$sid]['closed'] && !$hasData;
                    if ($hasData || $isDead) {
                        nrvd_respond_get($sessions, $sid, $r);
                        unset($controlConns[$key]);
                        break;
                    }
                    // Nothing to send yet and the session is still alive —
                    // hold this connection open (long-poll) instead of
                    // answering with an empty chunk immediately. Fulfilled
                    // by nrvd_process_bytes below, by the feof/close path,
                    // or by the pending-timeout sweep in the GC block.
                    $pendingGets[$sid] = ['sock' => $r, 'since' => time()];
                    unset($controlConns[$key]);
                    break;
                } else {
                    @fwrite($r, "DEAD 0\n");
                }
            } else {
                @fwrite($r, "ERR 0\n");
            }
            @fclose($r);
            unset($controlConns[$key]);
            break;
        }
        if ($handledAsControl) continue;

        $handledAsPending = false;
        foreach ($pendingGets as $sid => $pg) {
            if ($pg['sock'] !== $r) continue;
            $handledAsPending = true;
            // The only thing we expect to read here is EOF (client gone) —
            // any actual data means proxy.php closed/reset the connection;
            // either way, stop waiting on it.
            @fread($r, 65536);
            if (feof($r)) { @fclose($r); unset($pendingGets[$sid]); }
            break;
        }
        if ($handledAsPending) continue;

        foreach ($sessions as $sid => &$s) {
            if ($s['sock'] !== $r) continue;
            $chunk = @fread($r, 65536);
            if ($chunk === '' || $chunk === false) {
                if (feof($r)) { @fclose($r); $s['sock'] = null; $s['closed'] = true; }
            } else {
                nrvd_process_bytes($s, $chunk);
                $s['lastActivity'] = time();
            }
            // Fulfill a waiting long-poll the instant we have something to
            // tell it — new data, or the session just ended.
            if (isset($pendingGets[$sid]) && ($s['buf'] !== '' || $s['closed'])) {
                nrvd_respond_get($sessions, $sid, $pendingGets[$sid]['sock']);
                unset($pendingGets[$sid]);
            }
            break;
        }
        unset($s);
        } catch (\Throwable $e) {
            nrvd_log('ERROR handling a connection, isolated and continuing: ' . $e->getMessage());
        }
    }

    // Bound how long a long-poll GET can hold a control connection (and
    // therefore a proxy.php PHP worker) open when a session goes idle with
    // nothing to report — cheap to check every loop iteration since
    // stream_select above already cycles at least every 150ms, and there
    // are normally very few pending entries at once.
    if ($pendingGets) {
        $nowPg = time();
        foreach ($pendingGets as $sid => $pg) {
            if ($nowPg - $pg['since'] >= NRVD_PENDING_GET_MAX_WAIT_SEC) {
                nrvd_respond_get($sessions, $sid, $pg['sock']);
                unset($pendingGets[$sid]);
            }
        }
    }

    if (time() - $lastGc > 10) {
        $nowGc = time();
        foreach ($sessions as $sid => &$s) {
            // Stall guard: a socket connected but never produced response headers
            // within the timeout is as good as dead — close it and let the next
            // ENSURE retry with backoff instead of hanging forever.
            if ($s['sock'] && !$s['headerDone'] && $s['sockOpenedAt'] !== null
                && $nowGc - $s['sockOpenedAt'] > NRVD_HEADER_STALL_TIMEOUT_SEC) {
                @fclose($s['sock']);
                $s['sock'] = null;
                $s['closed'] = true;
                $s['connectFailed'] = true;
                $backoff = NRVD_CONNECT_RETRY_BACKOFF[min($s['connectAttempts'] - 1, count(NRVD_CONNECT_RETRY_BACKOFF) - 1)];
                $s['nextRetryAt'] = $nowGc + $backoff;
                if ($s['connectAttempts'] >= NRVD_CONNECT_MAX_ATTEMPTS) $s['permanentlyFailed'] = true;
                nrvd_log("$sid: header stall timeout, closing and marking for retry");
            }
            $idleLimit = !empty($s['permanentlyFailed']) ? NRVD_FAILED_IDLE_GC_SEC : NRVD_IDLE_GC_SEC;
            if ($nowGc - $s['lastActivity'] > $idleLimit) {
                if ($s['sock']) @fclose($s['sock']);
                if (isset($pendingGets[$sid])) {
                    @fwrite($pendingGets[$sid]['sock'], "DEAD 0\n");
                    @fclose($pendingGets[$sid]['sock']);
                    unset($pendingGets[$sid]);
                }
                unset($sessions[$sid]);
            }
        }
        unset($s);
        $lastGc = $nowGc;
    }
}
