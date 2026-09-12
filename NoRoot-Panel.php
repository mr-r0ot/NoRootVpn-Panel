<?php
/**
 * NoRoot VPN Panel — Main Application
 * Single-file installer + admin panel for a rootless shared-hosting VPN
 * built on Xray-core (VLESS + XHTTP) relayed through proxy.php.
 */

// When required by proxy.php purely for its function library (process control,
// watchdog), skip session/error-display setup entirely — a session file per
// relay request would be pure waste, and proxy.php manages its own error/output
// settings (it must never emit stray bytes into the proxied stream).
if (!defined('NRV_INCLUDED_AS_LIB')) {
    session_start();
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', 0);
}

// ============================================================
// Paths & constants
// ============================================================
define('NRV_DIR', __DIR__);
define('NRV_CONFIG_FILE', NRV_DIR . '/NoRoot-Config.json');
define('NRV_IS_WINDOWS', DIRECTORY_SEPARATOR === '\\' || stripos((string)(defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY : PHP_OS), 'win') !== false);
define('NRV_XRAY_RELEASE_URL_AMD64', 'https://github.com/XTLS/Xray-core/releases/latest/download/Xray-linux-64.zip');
define('NRV_XRAY_RELEASE_URL_ARM64', 'https://github.com/XTLS/Xray-core/releases/latest/download/Xray-linux-arm64-v8a.zip');
define('NRV_XRAY_RELEASE_URL_WIN64', 'https://github.com/XTLS/Xray-core/releases/latest/download/Xray-windows-64.zip');
define('NRV_XRAY_RELEASE_URL_WIN_ARM64', 'https://github.com/XTLS/Xray-core/releases/latest/download/Xray-windows-arm64-v8a.zip');

// ============================================================
// Helpers: config load/save
// ============================================================
function nrv_config_exists() {
    return file_exists(NRV_CONFIG_FILE);
}

function nrv_load_config() {
    if (!nrv_config_exists()) return null;
    $raw = file_get_contents(NRV_CONFIG_FILE);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function nrv_save_config($data) {
    file_put_contents(NRV_CONFIG_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    nrv_write_protective_htaccess();
}

function nrv_write_protective_htaccess() {
    $path = NRV_DIR . '/.htaccess';
    $marker = '# NoRootVPN-protect';
    $rule = "\n$marker\n<IfModule mod_authz_core.c>\n  <Files \"NoRoot-Config.json\">\n    Require all denied\n  </Files>\n</IfModule>\n<IfModule !mod_authz_core.c>\n  <Files \"NoRoot-Config.json\">\n    Order allow,deny\n    Deny from all\n  </Files>\n</IfModule>\n\n# Disable compression/buffering for the traffic proxy — required for xhttp streaming to work\n<IfModule mod_deflate.c>\n  SetEnvIfNoCase Request_URI proxy\\.php$ no-gzip dont-vary\n</IfModule>\n<IfModule mod_brotli.c>\n  SetEnvIfNoCase Request_URI proxy\\.php$ no-brotli dont-vary\n</IfModule>\n<Files \"proxy.php\">\n  SetEnv no-gzip 1\n  SetEnv no-brotli 1\n</Files>\n\n# Route /proxy.php/<anything> to proxy.php instead of 404ing on the extra path segments\nAcceptPathInfo On\n<IfModule mod_rewrite.c>\n  RewriteEngine On\n  RewriteCond %{REQUEST_FILENAME} !-f\n  RewriteRule ^proxy\\.php(/.*)?$ proxy.php [L]\n</IfModule>\n";
    $existing = file_exists($path) ? file_get_contents($path) : '';
    if (strpos($existing, $marker) === false) {
        file_put_contents($path, $existing . $rule);
    }
}

function nrv_write_bin_htaccess($binDir) {
    $path = rtrim($binDir, '/') . '/.htaccess';
    $content = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    @file_put_contents($path, $content);
}

// ============================================================
// Helpers: system
// ============================================================
function nrv_exec_available() {
    if (!function_exists('exec')) return false;
    $disabled = explode(',', str_replace(' ', '', (string)ini_get('disable_functions')));
    return !in_array('exec', $disabled);
}

function nrv_proc_open_available() {
    if (!function_exists('proc_open')) return false;
    $disabled = explode(',', str_replace(' ', '', (string)ini_get('disable_functions')));
    return !in_array('proc_open', $disabled);
}

function nrv_process_control_available() {
    return nrv_exec_available() || nrv_proc_open_available();
}

function nrv_uuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function nrv_cpu_percent() {
    $stat1 = @file_get_contents('/proc/stat');
    if ($stat1 === false) {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : null;
        return $load ? min(100, round($load[0] * 100 / max(1, nrv_cpu_cores()))) : null;
    }
    usleep(200000);
    $stat2 = @file_get_contents('/proc/stat');

    $parse = function ($s) {
        preg_match('/^cpu\s+(.+)$/m', $s, $m);
        return array_map('intval', preg_split('/\s+/', trim($m[1])));
    };
    $a = $parse($stat1);
    $b = $parse($stat2);
    $idle1 = $a[3] + ($a[4] ?? 0);
    $idle2 = $b[3] + ($b[4] ?? 0);
    $total1 = array_sum($a);
    $total2 = array_sum($b);
    $totalDelta = $total2 - $total1;
    $idleDelta = $idle2 - $idle1;
    if ($totalDelta <= 0) return 0;
    return round((1 - $idleDelta / $totalDelta) * 100, 1);
}

function nrv_cpu_cores() {
    $c = @file_get_contents('/proc/cpuinfo');
    if ($c === false) return 1;
    return max(1, substr_count($c, 'processor'));
}

function nrv_mem_percent() {
    $mem = @file_get_contents('/proc/meminfo');
    if ($mem === false) return null;
    preg_match('/MemTotal:\s+(\d+)/', $mem, $t);
    preg_match('/MemAvailable:\s+(\d+)/', $mem, $a);
    if (!$t || !$a) return null;
    $total = (int)$t[1];
    $avail = (int)$a[1];
    if ($total <= 0) return null;
    return round((1 - $avail / $total) * 100, 1);
}

function nrv_disk_percent($path) {
    $free = @disk_free_space($path);
    $total = @disk_total_space($path);
    if (!$free || !$total) return null;
    return round((1 - $free / $total) * 100, 1);
}

// ============================================================
// Helpers: cross-platform process control (Xray + daemon share these)
// ============================================================
// Matches against the process's FULL command line, not just its short `comm`
// name — on hosts where PHP runs via a wrapper (e.g. lsphp), `comm` is often
// just "lsphp" for every PHP process regardless of what script it's running,
// which made every liveness check here a false negative. The full command
// line still contains the real invoked path (".../daemon.php", ".../xray"),
// so matching against that works regardless of what the interpreter is called.
function nrv_process_alive_posix($pid, $expectedImage) {
    $needle = basename($expectedImage);
    $cmdlineFile = '/proc/' . intval($pid) . '/cmdline';
    $cmdline = @file_get_contents($cmdlineFile);
    if ($cmdline !== false) {
        if ($cmdline === '') return false; // /proc/<pid> exists but is empty -> not a real process
        return stripos(str_replace("\0", ' ', $cmdline), $needle) !== false;
    }
    // /proc not available (non-Linux POSIX) — fall back to full args, not
    // just the short comm name.
    $out = [];
    @exec('ps -p ' . intval($pid) . ' -o args= 2>&1', $out);
    if (empty($out) || trim($out[0]) === '') return false;
    return stripos(trim($out[0]), $needle) !== false;
}

function nrv_process_alive_windows($pid, $expectedImage) {
    $out = [];
    @exec('tasklist /FI "PID eq ' . intval($pid) . '" /FO CSV /NH 2>NUL', $out);
    if (empty($out[0])) return false;
    $fields = str_getcsv($out[0]);
    return isset($fields[0]) && stripos($fields[0], basename($expectedImage)) !== false;
}

// DIAGNOSTIC ONLY — do not treat this as proof our process is what's
// listening. A port answering is not proof our process bound it: confirmed
// on a real shared host where something answered on our configured ports
// while Xray had never even started, making the panel falsely report
// "Running" and hide a real failure. Use this only to surface a warning
// ("something's on this port but we can't verify it's ours"), never as a
// substitute for genuine process verification (nrv_process_alive).
function nrv_port_open($port, $timeoutSec = 0.3) {
    $sock = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, $timeoutSec);
    if ($sock) { fclose($sock); return true; }
    return false;
}

// $expectedImage: path/basename of the binary that pid file is supposed to belong to.
// Verifying the running image name (not just "some process with this PID exists")
// avoids a false-positive if the PID was reused by an unrelated process after the
// original process died — a stale pid file alone is not proof of liveness.
function nrv_process_alive($pidFile, $expectedImage) {
    if (!file_exists($pidFile)) return null;
    $pid = trim(file_get_contents($pidFile));
    if ($pid === '' || !ctype_digit($pid)) return null;
    $alive = NRV_IS_WINDOWS
        ? nrv_process_alive_windows((int)$pid, $expectedImage)
        : nrv_process_alive_posix((int)$pid, $expectedImage);
    return $alive ? (int)$pid : null;
}

function nrv_process_stop($pidFile, $expectedImage) {
    $pid = nrv_process_alive($pidFile, $expectedImage);
    if ($pid) {
        if (NRV_IS_WINDOWS) {
            @exec('taskkill /PID ' . $pid . ' 2>&1');
            usleep(500000);
            if (nrv_process_alive($pidFile, $expectedImage)) @exec('taskkill /PID ' . $pid . ' /F 2>&1');
        } else {
            @exec('kill ' . $pid . ' 2>&1');
            usleep(500000);
            if (nrv_process_alive($pidFile, $expectedImage)) @exec('kill -9 ' . $pid . ' 2>&1');
        }
    }
    @unlink($pidFile);
}

// Last resort for hosts without SSH access: find and kill ANY process whose
// full command line matches our own xray binary path or daemon.php path,
// regardless of what our pidfiles currently say. This is specifically for
// clearing orphans left behind by earlier failed attempts (e.g. a daemon
// still holding its port from before a bug fix) when there's no way to log
// in and kill them by hand.
function nrv_force_cleanup_stray_processes($cfg) {
    if (NRV_IS_WINDOWS || !nrv_exec_available()) {
        return ['ok' => false, 'error' => 'Not available on this host (needs exec() on a POSIX system).'];
    }
    $targets = [$cfg['paths']['xray_bin'], NRV_DIR . '/daemon.php'];
    $out = [];
    foreach ($targets as $t) {
        @exec('pkill -f ' . escapeshellarg($t) . ' 2>&1', $o);
        $out = array_merge($out, $o);
    }
    @unlink($cfg['paths']['xray_pid']);
    @unlink($cfg['paths']['base_dir'] . '/daemon.pid');
    return ['ok' => true, 'output' => $out];
}

function nrv_spawn_last_error($set = null) {
    static $err = '';
    if ($set !== null) $err = $set;
    return $err;
}

function nrv_setsid_available() {
    static $available = null;
    if ($available === null) {
        $out = [];
        @exec('command -v setsid 2>/dev/null', $out);
        $available = !empty($out[0]);
    }
    return $available;
}

// Launches a detached background process. Returns the child PID on success, or
// false — on failure, nrv_spawn_last_error() holds a human-readable reason.
// $envVars lets a caller set environment variables for the child (e.g.
// capping GOMAXPROCS for a Go binary on hosts with a strict per-account
// process/thread limit — see nrv_xray_start).
function nrv_spawn_background(array $cmdParts, $logFile, array $envVars = []) {
    nrv_spawn_last_error('');
    if (NRV_IS_WINDOWS) {
        if (!nrv_proc_open_available()) { nrv_spawn_last_error('proc_open() is not available.'); return false; }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']];
        $env = null;
        if ($envVars) {
            $env = [];
            foreach ($_SERVER as $k => $v) { if (is_string($v)) $env[$k] = $v; }
            foreach ($envVars as $k => $v) { $env[$k] = $v; }
        }
        $proc = @proc_open($cmdParts, $descriptors, $pipes, null, $env, ['bypass_shell' => true, 'create_new_console' => true]);
        if (!is_resource($proc)) { nrv_spawn_last_error('proc_open() call failed.'); return false; }
        if (isset($pipes[0])) @fclose($pipes[0]);
        $status = proc_get_status($proc);
        $pid = $status['pid'] ?? false;
        // Deliberately not proc_close()'d — that would block waiting on the child.
        // We intend the child to keep running detached after this request ends.
        if (!$pid) nrv_spawn_last_error('proc_open() did not report a PID.');
        return $pid ?: false;
    }
    if (!nrv_exec_available()) { nrv_spawn_last_error('exec() is not available.'); return false; }
    // Prefer setsid (fully detaches from the controlling terminal/process
    // group) but fall back to plain nohup if setsid isn't installed (some
    // minimal hosts lack util-linux) — nohup alone still survives SIGHUP,
    // which covers most "parent request ended" scenarios.
    $detach = nrv_setsid_available() ? 'setsid nohup' : 'nohup';
    $envPrefix = '';
    foreach ($envVars as $k => $v) { $envPrefix .= $k . '=' . escapeshellarg($v) . ' '; }
    // Wrap the whole thing so any shell-level error (e.g. "setsid: not
    // found", permission denied) lands in $out instead of vanishing — a
    // successful run puts exactly one line (the PID) in $out, so anything
    // else there is itself the diagnostic.
    $out = [];
    @exec($envPrefix . $detach . ' ' . implode(' ', array_map('escapeshellarg', $cmdParts))
        . ' > ' . escapeshellarg($logFile) . ' 2>&1 & echo $! 2>&1', $out);
    $pid = isset($out[0]) ? trim($out[0]) : '';
    if ($pid !== '' && ctype_digit($pid)) return (int)$pid;
    nrv_spawn_last_error($pid !== '' ? $pid : 'exec() produced no output at all — exec() may be silently restricted on this host.');
    return false;
}

// ============================================================
// Helpers: Xray process management
// ============================================================
function nrv_xray_last_error($set = null) {
    static $err = null;
    if ($set !== null) $err = $set;
    return $err;
}

function nrv_xray_pid($cfg) {
    return nrv_process_alive($cfg['paths']['xray_pid'], $cfg['paths']['xray_bin']);
}

function nrv_xray_stop($cfg) {
    nrv_process_stop($cfg['paths']['xray_pid'], $cfg['paths']['xray_bin']);
}

function nrv_xray_start($cfg) {
    nrv_xray_last_error('');
    if (nrv_xray_pid($cfg) !== null) return true; // already genuinely running
    if (!nrv_process_control_available()) {
        nrv_xray_last_error('Neither exec() nor proc_open() is available on this host.');
        return false;
    }
    $bin = $cfg['paths']['xray_bin'];
    $conf = $cfg['paths']['xray_config'];
    $log = $cfg['paths']['xray_log'];
    if (!is_executable($bin) || !file_exists($conf)) {
        nrv_xray_last_error('Xray binary is not executable or config.json is missing.');
        return false;
    }

    // Xray is a Go binary — by default its runtime spins up several OS
    // threads at startup (scaling with perceived CPU count). On shared
    // hosting with a strict per-account process/thread cap (common with
    // CloudLinux/LVE), that alone can exhaust the limit and crash Xray with
    // "runtime: failed to create new OS thread" before it ever binds a
    // port. GOMAXPROCS=1 caps Go's own thread pool to the minimum, which is
    // the standard mitigation for exactly this crash on constrained hosts.
    $pid = nrv_spawn_background([$bin, 'run', '-config', $conf], $log, ['GOMAXPROCS' => '1']);
    if ($pid === false) {
        nrv_xray_last_error('Failed to spawn Xray process. ' . nrv_spawn_last_error());
        return false;
    }
    usleep(600000);
    // Verify the FRESHLY SPAWNED pid specifically is still alive before
    // claiming the pidfile — if Xray immediately exited (e.g. its own port
    // conflict) and we wrote the pidfile anyway, a genuinely-alive earlier
    // instance would be orphaned with nothing left tracking it (the same
    // failure mode already fixed in daemon.php's own pidfile-write timing).
    $stillAlive = NRV_IS_WINDOWS
        ? nrv_process_alive_windows($pid, $bin)
        : nrv_process_alive_posix($pid, $bin);
    if ($stillAlive) {
        file_put_contents($cfg['paths']['xray_pid'], $pid);
        return nrv_xray_pid($cfg) !== null;
    }
    $tail = @file_get_contents($log);
    if ($tail) {
        $diag = 'Last log: ' . substr(trim($tail), -300);
    } else {
        // A completely empty log (not even Xray's own startup banner) means
        // the binary likely never actually executed — surface exactly what
        // we can check WITHOUT SSH (file size + permissions) so a corrupt
        // download or a lost executable bit is immediately visible instead
        // of guessing blind.
        $size = @filesize($bin);
        $perms = @fileperms($bin);
        $permsOctal = $perms !== false ? substr(sprintf('%o', $perms), -4) : 'unknown';
        $diag = '(log is empty — Xray likely never started). Binary: '
            . ($size !== false ? number_format($size) . ' bytes' : 'size unreadable')
            . ', permissions ' . $permsOctal . '. A genuine Xray Linux binary is normally 35-45MB —'
            . ' if this is much smaller, the download was likely truncated/corrupted; re-download it.'
            . ' If permissions don\'t include execute (the last digit should have bit 1, e.g. x1, 5, or 7),'
            . ' the binary lost its executable bit.';
    }
    nrv_xray_last_error('Xray did not stay running after spawn. ' . $diag);
    return false;
}

function nrv_xray_restart($cfg) {
    nrv_xray_stop($cfg);
    usleep(300000);
    return nrv_xray_start($cfg);
}

// ============================================================
// Helpers: persistent session-broker daemon management
// ============================================================
function nrv_daemon_pid($cfg) {
    return nrv_process_alive($cfg['paths']['base_dir'] . '/daemon.pid', nrv_find_php_binary());
}

function nrv_daemon_stop($cfg) {
    nrv_process_stop($cfg['paths']['base_dir'] . '/daemon.pid', nrv_find_php_binary());
}

function nrv_find_php_binary() {
    if (NRV_IS_WINDOWS) {
        if (defined('PHP_BINARY') && PHP_BINARY && stripos(PHP_BINARY, 'php') !== false && is_executable(PHP_BINARY)) {
            return PHP_BINARY;
        }
        $iniFile = @php_ini_loaded_file();
        if ($iniFile) {
            $iniDir = dirname($iniFile);
            foreach ([$iniDir . '\\php.exe', $iniDir . '\\php-cgi.exe'] as $c) {
                if (is_executable($c)) return $c;
            }
        }
        foreach ((array)@glob('C:\\laragon\\bin\\php\\php-*\\php.exe') as $c) {
            if (is_executable($c)) return $c;
        }
        foreach (['C:\\xampp\\php\\php.exe', 'C:\\wamp64\\bin\\php\\php.exe'] as $c) {
            if (is_executable($c)) return $c;
        }
        if (nrv_exec_available()) {
            $out = [];
            @exec('where php.exe 2>NUL', $out);
            if (!empty($out[0])) return trim($out[0]);
        }
        return 'php.exe'; // last resort
    }
    // The web SAPI's own interpreter path is the most reliable choice —
    // 'php' alone can be missing or wrong-versioned in exec()'s minimal PATH.
    if (defined('PHP_BINARY') && PHP_BINARY && is_executable(PHP_BINARY)) {
        return PHP_BINARY;
    }
    $candidates = ['/usr/bin/php', '/usr/local/bin/php', '/opt/cpanel/ea-php81/root/usr/bin/php', '/opt/cpanel/ea-php82/root/usr/bin/php'];
    foreach ($candidates as $c) {
        if (is_executable($c)) return $c;
    }
    $out = [];
    @exec('command -v php 2>/dev/null', $out);
    if (!empty($out[0])) return trim($out[0]);
    return 'php'; // last resort
}

function nrv_daemon_start($cfg) {
    // daemon.php only claims its own pidfile after a successful bind (fixed
    // in daemon.php itself), so nrv_daemon_pid() here is trustworthy — no
    // port-based shortcut needed or wanted (one previously caused false
    // "Running" reports on a real host where something else answered on
    // this port while the daemon had never actually started).
    if (nrv_daemon_pid($cfg) !== null) return ['ok' => true];
    if (!nrv_process_control_available()) return ['ok' => false, 'error' => 'Neither exec() nor proc_open() is available'];
    $daemonScript = NRV_DIR . '/daemon.php';
    $baseDir = $cfg['paths']['base_dir'];
    $log = $baseDir . '/daemon.log';
    $fatalLog = NRV_DIR . '/daemon-fatal.log';
    if (!file_exists($daemonScript)) return ['ok' => false, 'error' => 'daemon.php not found at ' . $daemonScript];

    @unlink($fatalLog);
    $phpBin = nrv_find_php_binary();

    $pid = nrv_spawn_background([$phpBin, $daemonScript], $log);
    if ($pid === false) {
        return ['ok' => false, 'error' => 'Failed to spawn daemon process (php binary used: ' . $phpBin . '). ' . nrv_spawn_last_error()];
    }

    usleep(700000);

    if (nrv_daemon_pid($cfg) !== null) {
        return ['ok' => true];
    }

    // Failed — surface the most useful diagnostic we can find.
    $error = 'Daemon did not stay running (php binary used: ' . $phpBin . ').';
    if (file_exists($fatalLog)) {
        $error .= ' ' . trim(file_get_contents($fatalLog));
    } elseif (file_exists($log)) {
        $tail = trim(file_get_contents($log));
        if ($tail !== '') $error .= ' Last log: ' . substr($tail, -300);
    } else {
        $error .= ' (daemon.log was never created — the spawn itself likely never ran)';
    }
    return ['ok' => false, 'error' => $error];
}

function nrv_daemon_restart($cfg) {
    nrv_daemon_stop($cfg);
    usleep(300000);
    return nrv_daemon_start($cfg);
}

// Build the server-side Xray config.json from the users list
function nrv_build_xray_config($cfg) {
    $clients = [];
    foreach ($cfg['users'] as $u) {
        if (array_key_exists('enabled', $u) && !$u['enabled']) continue; // disabled users keep their record but lose server-side access
        $clients[] = ['id' => $u['id'], 'level' => 0];
    }
    $conf = [
        'log' => ['loglevel' => 'debug'],
        'inbounds' => [[
            'listen' => '127.0.0.1',
            'port' => (int)$cfg['xray']['local_port'],
            'protocol' => 'vless',
            'settings' => [
                'clients' => $clients,
                'decryption' => 'none',
            ],
            'streamSettings' => [
                'network' => 'xhttp',
                'xhttpSettings' => [
                    'path' => $cfg['xray']['proxy_public_path'] ?? '/proxy.php',
                    // "auto" (not a hardcoded single sub-mode) so the server
                    // doesn't reject a client's session-less/initial request
                    // with "stream-one mode is not allowed" — confirmed from
                    // Xray-core's own source (transport/internet/splithttp/hub.go):
                    // that specific check only passes when Mode is "", "auto",
                    // "stream-one", or "stream-up". Our own relay (daemon.php's
                    // hand-crafted download connection) always includes a real
                    // x_session, so this doesn't change how it behaves — it only
                    // stops rejecting requests from real clients that don't.
                    'mode' => 'auto',
                    'extra' => [
                        'sessionPlacement' => 'query',
                        'seqPlacement' => 'query',
                    ],
                    'xmux' => [
                        'maxConcurrency' => (int)($cfg['xray']['xmux_max_concurrency'] ?? 2),
                        'maxConnections' => (int)($cfg['xray']['xmux_max_connections'] ?? 2),
                    ],
                ],
            ],
        ]],
        'outbounds' => [
            ['protocol' => 'freedom', 'tag' => 'direct'],
        ],
    ];
    file_put_contents($cfg['paths']['xray_config'], json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function nrv_apply_users_and_restart($cfg) {
    nrv_build_xray_config($cfg);
    return nrv_xray_restart($cfg);
}

// ============================================================
// Helpers: client config generation
// ============================================================
function nrv_client_links($cfg, $user) {
    $host = $cfg['xray']['public_host'];
    $port = (int)$cfg['xray']['public_port'];
    $path = $cfg['xray']['proxy_public_path'] ?? '/proxy.php';
    $name = rawurlencode($user['name']);

    $xmux = [
        'maxConcurrency' => (int)($cfg['xray']['xmux_max_concurrency'] ?? 2),
        'maxConnections' => (int)($cfg['xray']['xmux_max_connections'] ?? 2),
    ];

    $useTls = !empty($cfg['xray']['public_tls']);

    $queryParams = [
        'type' => 'xhttp',
        'path' => $path,
        'mode' => 'packet-up',
        'host' => $host,
        'security' => $useTls ? 'tls' : 'none',
    ];
    if ($useTls) {
        $queryParams['sni'] = $host;
        $queryParams['fp'] = 'chrome';
        $queryParams['alpn'] = 'h2';
    }
    $queryParams['extra'] = json_encode(['sessionPlacement' => 'query', 'seqPlacement' => 'query'], JSON_UNESCAPED_SLASHES);
    $queryParams['xmux'] = json_encode($xmux, JSON_UNESCAPED_SLASHES);
    $query = http_build_query($queryParams);
    $vlessUri = "vless://{$user['id']}@{$host}:{$port}?{$query}#{$name}";
    $base64Sub = base64_encode($vlessUri);

    $streamSettings = [
        'network' => 'xhttp',
        'security' => $useTls ? 'tls' : 'none',
        'xhttpSettings' => [
            'path' => $path,
            'mode' => 'packet-up',
            'extra' => [
                'sessionPlacement' => 'query',
                'seqPlacement' => 'query',
            ],
            'xmux' => $xmux,
        ],
    ];
    if ($useTls) {
        $streamSettings['tlsSettings'] = ['serverName' => $host];
    }

    $jsonClient = [
        'log' => ['loglevel' => 'warning'],
        'inbounds' => [[
            'tag' => 'socks',
            'port' => 10808,
            'listen' => '127.0.0.1',
            'protocol' => 'socks',
            'settings' => ['udp' => true],
        ]],
        'outbounds' => [[
            'protocol' => 'vless',
            'settings' => [
                'vnext' => [[
                    'address' => $host,
                    'port' => $port,
                    'users' => [[
                        'id' => $user['id'],
                        'encryption' => 'none',
                    ]],
                ]],
            ],
            'streamSettings' => $streamSettings,
        ]],
    ];

    return [
        'uri' => $vlessUri,
        'base64' => $base64Sub,
        'json' => json_encode($jsonClient, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ];
}

// ============================================================
// Environment tests (used during install wizard)
// ============================================================
function nrv_run_env_tests() {
    $tests = [];
    $execOk = nrv_exec_available();
    $procOpenOk = nrv_proc_open_available();
    $tests[] = ['name' => 'exec() function', 'ok' => $execOk, 'detail' => $execOk ? 'Available' : 'Blocked on this host'];
    $tests[] = ['name' => 'proc_open() function', 'ok' => $procOpenOk, 'detail' => $procOpenOk ? 'Available' : 'Blocked on this host'];

    $curlOk = function_exists('curl_init');
    $tests[] = ['name' => 'curl extension', 'ok' => $curlOk, 'detail' => $curlOk ? 'Available' : 'Not available'];

    $downloadOk = $curlOk;
    if (!$downloadOk && $execOk) {
        $findBin = NRV_IS_WINDOWS ? 'where wget 2>NUL' : 'which wget 2>/dev/null';
        $wgetBin = trim((string)@shell_exec($findBin));
        $downloadOk = $wgetBin !== '';
    }
    $tests[] = ['name' => 'Download capability (curl ext / wget)', 'ok' => $downloadOk, 'detail' => $downloadOk ? 'At least one method available' : 'None available — manual binary upload will be required'];

    $writeOk = is_writable(NRV_DIR);
    $tests[] = ['name' => 'Write permission in panel directory', 'ok' => $writeOk, 'detail' => $writeOk ? NRV_DIR : 'Not writable — installation cannot proceed'];

    if ($execOk || $procOpenOk) {
        // Some real hosts (loaded shared hosting, lsphp-wrapped PHP) are slow
        // enough that a tight timing budget here produces a false negative
        // even though detachment genuinely works — poll for up to ~4s instead
        // of a single fixed sleep, and only report failure if it never shows up.
        $bgFile = sys_get_temp_dir() . '/nrv_bgtest_' . uniqid() . '.txt';
        $phpBin = nrv_find_php_binary();
        $probeLog = sys_get_temp_dir() . '/nrv_bgtest_' . uniqid() . '.log';
        $code = 'usleep(800000); file_put_contents(' . var_export($bgFile, true) . ', "ok");';
        $pid = nrv_spawn_background([$phpBin, '-r', $code], $probeLog);
        $alive = false;
        $deadline = microtime(true) + 4.0;
        while (microtime(true) < $deadline) {
            usleep(300000);
            if (file_exists($bgFile)) { $alive = true; break; }
        }
        @unlink($bgFile);
        @unlink($probeLog);
        $tests[] = ['name' => 'Background process (detached PHP spawn)', 'ok' => $alive, 'detail' => $alive ? "PID $pid completed its detached task" : 'Process did not complete after detaching from the request — Xray/daemon Start may need to be retried or done manually if this keeps failing'];
    }

    return $tests;
}

// ============================================================
// Detect server architecture for Xray binary
// ============================================================
function nrv_detect_arch() {
    $m = php_uname('m');
    if (strpos($m, 'aarch64') !== false || strpos($m, 'arm64') !== false) return 'arm64';
    return 'amd64';
}

define('NRV_XRAY_ZIP_MAX_BYTES', 80 * 1024 * 1024);
define('NRV_XRAY_ZIP_ENTRY_MAX_BYTES', 200 * 1024 * 1024);
define('NRV_XRAY_ZIP_MAX_ENTRIES', 20);

function nrv_download_xray($destDir) {
    // A slow connection to GitHub can legitimately take well over PHP's
    // default max_execution_time (often 30-60s on shared hosting), which
    // would otherwise fatal-error this ENTIRE request partway through the
    // download — give this specific slow network operation real headroom.
    @set_time_limit(120);
    $arch = nrv_detect_arch();
    if (NRV_IS_WINDOWS) {
        $url = $arch === 'arm64' ? NRV_XRAY_RELEASE_URL_WIN_ARM64 : NRV_XRAY_RELEASE_URL_WIN64;
    } else {
        $url = $arch === 'arm64' ? NRV_XRAY_RELEASE_URL_ARM64 : NRV_XRAY_RELEASE_URL_AMD64;
    }
    $zipPath = rtrim($destDir, '/') . '/xray.zip';

    $downloaded = false;
    if (function_exists('curl_init')) {
        $fp = fopen($zipPath, 'w');
        if ($fp) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 100);
            curl_setopt($ch, CURLOPT_MAXFILESIZE, NRV_XRAY_ZIP_MAX_BYTES);
            curl_exec($ch);
            $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200;
            curl_close($ch);
            fclose($fp);
            $downloaded = $ok && filesize($zipPath) > 100000 && filesize($zipPath) <= NRV_XRAY_ZIP_MAX_BYTES;
        }
    }
    // Windows has no wget fallback here — the curl extension is the only supported
    // download path on Windows; if it's missing, the admin must upload manually.
    if (!$downloaded && !NRV_IS_WINDOWS && nrv_exec_available()) {
        @exec('wget -q -O ' . escapeshellarg($zipPath) . ' ' . escapeshellarg($url) . ' 2>&1');
        $downloaded = file_exists($zipPath) && filesize($zipPath) > 100000 && filesize($zipPath) <= NRV_XRAY_ZIP_MAX_BYTES;
    }
    if (!$downloaded) {
        @unlink($zipPath);
        return ['ok' => false, 'error' => 'Download failed. Please upload the Xray binary manually to: ' . $destDir . '/' . (NRV_IS_WINDOWS ? 'xray.exe' : 'xray')];
    }

    if (!class_exists('ZipArchive')) return ['ok' => false, 'error' => 'ZipArchive PHP extension is not available. Please upload and extract the xray binary manually to: ' . $destDir . '/' . (NRV_IS_WINDOWS ? 'xray.exe' : 'xray')];

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        @unlink($zipPath);
        return ['ok' => false, 'error' => 'Failed to extract xray.zip (corrupt file)'];
    }

    if ($zip->numFiles > NRV_XRAY_ZIP_MAX_ENTRIES) {
        $zip->close();
        @unlink($zipPath);
        return ['ok' => false, 'error' => 'Refusing to extract xray.zip: too many entries (possible zip bomb)'];
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        if ($st && $st['size'] > NRV_XRAY_ZIP_ENTRY_MAX_BYTES) {
            $zip->close();
            @unlink($zipPath);
            return ['ok' => false, 'error' => 'Refusing to extract xray.zip: an entry is implausibly large (possible zip bomb)'];
        }
    }

    $zip->extractTo($destDir);
    $zip->close();
    @unlink($zipPath);

    $binPath = rtrim($destDir, '/') . '/' . (NRV_IS_WINDOWS ? 'xray.exe' : 'xray');
    if (!file_exists($binPath)) return ['ok' => false, 'error' => 'xray binary not found after extraction'];
    if (filesize($binPath) < 1_000_000) return ['ok' => false, 'error' => 'extracted xray binary is implausibly small; download likely corrupt'];
    if (!NRV_IS_WINDOWS) @chmod($binPath, 0755);
    return ['ok' => true];
}

// ============================================================
// Helpers: self-healing watchdog + login healthcheck
// ============================================================
define('NRV_WATCHDOG_COOLDOWN_SEC', 20);
define('NRV_WATCHDOG_MAX_FAILS', 5);

// Cheap, rate-limited liveness check + lazy relaunch. Safe to call on every
// page load / every proxy.php request — it does nothing until the cooldown
// has elapsed, and gives up on a service after too many consecutive failures
// so a permanently broken host can't be hammered with relaunch attempts.
function nrv_ensure_services_running($cfg) {
    $markerFile = $cfg['paths']['base_dir'] . '/watchdog.json';
    $marker = json_decode((string)@file_get_contents($markerFile), true);
    if (!is_array($marker)) $marker = ['xray_fail' => 0, 'daemon_fail' => 0, 'last' => 0];
    $now = time();
    if ($now - ($marker['last'] ?? 0) < NRV_WATCHDOG_COOLDOWN_SEC) return;

    $dirty = false;
    if (nrv_xray_pid($cfg) === null) {
        if (($marker['xray_fail'] ?? 0) < NRV_WATCHDOG_MAX_FAILS) {
            $ok = nrv_xray_start($cfg);
            $marker['xray_fail'] = $ok ? 0 : ($marker['xray_fail'] ?? 0) + 1;
            $dirty = true;
        }
    } elseif (($marker['xray_fail'] ?? 0) !== 0) {
        $marker['xray_fail'] = 0;
        $dirty = true;
    }

    if (nrv_daemon_pid($cfg) === null) {
        if (($marker['daemon_fail'] ?? 0) < NRV_WATCHDOG_MAX_FAILS) {
            $r = nrv_daemon_start($cfg);
            $marker['daemon_fail'] = !empty($r['ok']) ? 0 : ($marker['daemon_fail'] ?? 0) + 1;
            $dirty = true;
        }
    } elseif (($marker['daemon_fail'] ?? 0) !== 0) {
        $marker['daemon_fail'] = 0;
        $dirty = true;
    }

    if ($dirty) {
        $marker['last'] = $now;
        @file_put_contents($markerFile, json_encode($marker));
    }
}

// Resets the watchdog's failure counters — call this whenever an admin
// manually starts/restarts a service, so a fresh manual attempt isn't
// immediately blocked by a stale failure cap from earlier.
function nrv_watchdog_reset($cfg) {
    $markerFile = $cfg['paths']['base_dir'] . '/watchdog.json';
    @file_put_contents($markerFile, json_encode(['xray_fail' => 0, 'daemon_fail' => 0, 'last' => 0]));
}

// Runs on every admin login: full env re-check, service liveness/relaunch,
// and re-download of the Xray binary if it's gone missing. Result is meant
// to be shown once as a dashboard banner and then discarded.
function nrv_run_login_healthcheck($cfg) {
    $repairs = [];
    $tests = nrv_run_env_tests();
    nrv_ensure_services_running($cfg);
    if (!is_executable($cfg['paths']['xray_bin'])) {
        $dl = nrv_download_xray($cfg['paths']['base_dir']);
        if ($dl['ok']) {
            $repairs[] = 're-downloaded missing Xray binary';
            nrv_xray_start($cfg);
        } else {
            $repairs[] = 'Xray binary missing and re-download failed: ' . $dl['error'];
        }
    }
    $xrayOk = nrv_xray_pid($cfg) !== null;
    $daemonOk = nrv_daemon_pid($cfg) !== null;
    $warnings = [];
    // Diagnostic only, never treated as "running" — but if our own process
    // check says stopped while something else answers on the port, that's
    // worth surfacing: either a leftover orphan from before a fix, or
    // (as seen on one real host) an unrelated service/proxy on this port.
    if (!$xrayOk && nrv_port_open((int)$cfg['xray']['local_port'])) {
        $warnings[] = 'Xray looks stopped, but something is already answering on local port ' . $cfg['xray']['local_port'] . ' — likely a leftover process from an earlier attempt, or a port conflict with another service on this host.';
    }
    if (!$daemonOk && nrv_port_open((int)($cfg['xray']['daemon_port'] ?? 10001))) {
        $warnings[] = 'The daemon looks stopped, but something is already answering on daemon port ' . ($cfg['xray']['daemon_port'] ?? 10001) . ' — likely a leftover process from an earlier attempt, or a port conflict with another service on this host.';
    }
    return [
        'tests' => $tests,
        'xray_ok' => $xrayOk,
        'daemon_ok' => $daemonOk,
        'repairs' => $repairs,
        'warnings' => $warnings,
    ];
}

// Tests the HOST'S OWN outbound network directly with plain curl — no Xray,
// no VLESS, no relay involved at all. This is the one tool that can tell
// "our relay is broken" apart from "this specific host's own outbound
// network to this destination is slow/blocked/throttled", which otherwise
// looks identical from inside the tunnel (both show up as "connects, but no
// data" or "very slow"). Especially valuable on hosts with no SSH access,
// where there's no other way to run a quick connectivity check.
function nrv_run_network_diagnostics() {
    $targets = [
        'Google' => 'https://www.google.com',
        'Cloudflare' => 'https://www.cloudflare.com',
        'GitHub' => 'https://github.com',
    ];
    $results = [];
    foreach ($targets as $label => $url) {
        $start = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true, // HEAD-equivalent, we only care about connect+TTFB
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $ok = curl_exec($ch) !== false;
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $connectTime = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $totalTime = microtime(true) - $start;
        $error = $ok ? null : curl_error($ch);
        curl_close($ch);
        $results[] = [
            'label' => $label,
            'ok' => $ok && $httpCode > 0,
            'http_code' => $httpCode,
            'connect_ms' => round($connectTime * 1000),
            'total_ms' => round($totalTime * 1000),
            'error' => $error,
        ];
    }
    return $results;
}

if (!defined('NRV_INCLUDED_AS_LIB')) {

// ============================================================
// ROUTER
// ============================================================
$cfg = nrv_load_config();
$installed = $cfg !== null && !empty($cfg['installed']);

// ---------- Logout ----------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: ' . basename(__FILE__));
    exit;
}

// ---------- Not installed: run wizard ----------
if (!$installed) {
    $step = $_POST['step'] ?? $_GET['step'] ?? '1';
    $envTests = null;
    $envPassed = false;
    $installError = null;

    if ($step === '1') {
        $envTests = nrv_run_env_tests();
        // Only directory-writability is truly install-blocking. exec()/proc_open()
        // being unavailable just means Start/Stop controls will be disabled later
        // (see Settings page) — the panel, config, and client-config generation
        // all work without them, which matters on the most locked-down hosts.
        $writeTest = current(array_filter($envTests, fn($t) => $t['name'] === 'Write permission in panel directory'));
        $envPassed = $writeTest && $writeTest['ok'];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === '2') {
        $baseDir = rtrim($_POST['base_dir'] ?: (NRV_DIR . '/bin'), '/');
        $localPort = (int)($_POST['local_port'] ?: 10000);
        $splitPath = '/' . ltrim($_POST['splithttp_path'] ?: ('xr' . substr(md5(uniqid()), 0, 8)), '/');
        $publicHost = $_POST['public_host'] ?: $_SERVER['HTTP_HOST'];
        $publicPort = (int)($_POST['public_port'] ?: 443);
        $publicTls = !empty($_POST['public_tls']);
        $adminUser = trim($_POST['admin_username'] ?: 'admin');
        $adminPass = $_POST['admin_password'] ?? '';

        if (strlen($adminPass) < 6) {
            $installError = 'Admin password must be at least 6 characters.';
        } else {
            if (!is_dir($baseDir)) @mkdir($baseDir, 0755, true);

            $proxyPublicPath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/proxy.php';
            if ($proxyPublicPath === '/proxy.php' && dirname($_SERVER['SCRIPT_NAME']) === '.') {
                $proxyPublicPath = '/proxy.php';
            }

            $newCfg = [
                'installed' => false,
                'admin_username' => $adminUser,
                'admin_password_hash' => password_hash($adminPass, PASSWORD_DEFAULT),
                'paths' => [
                    'base_dir' => $baseDir,
                    'xray_bin' => $baseDir . '/xray' . (NRV_IS_WINDOWS ? '.exe' : ''),
                    'xray_config' => $baseDir . '/config.json',
                    'xray_pid' => $baseDir . '/xray.pid',
                    'xray_log' => $baseDir . '/xray.log',
                ],
                'xray' => [
                    'local_port' => $localPort,
                    'daemon_port' => $localPort + 1,
                    'splithttp_path' => $splitPath,
                    'proxy_public_path' => $proxyPublicPath,
                    'public_host' => $publicHost,
                    'public_port' => $publicPort,
                    'public_tls' => $publicTls,
                    'xmux_max_concurrency' => 2,
                    'xmux_max_connections' => 2,
                ],
                'users' => [],
            ];

            nrv_write_bin_htaccess($baseDir);

            $xrayReady = is_executable($newCfg['paths']['xray_bin']);
            if (!$xrayReady) {
                $dl = nrv_download_xray($baseDir);
                $xrayReady = $dl['ok'] && is_executable($newCfg['paths']['xray_bin']);
                if (!$dl['ok']) $installError = $dl['error'];
            }

            if ($xrayReady) {
                nrv_build_xray_config($newCfg);
                // Config must be written to disk BEFORE starting the daemon —
                // daemon.php is a separate process that reads NoRoot-Config.json
                // from disk on its own; starting it while the config only exists
                // in this request's memory means it always fails to read it.
                $newCfg['installed'] = true;
                nrv_save_config($newCfg);
                $started = nrv_xray_start($newCfg);
                $daemonResult = nrv_daemon_start($newCfg);

                if (!$started || !$daemonResult['ok']) {
                    $installError = 'Xray/daemon installed but one failed to start on first attempt.';
                    if (!$started) $installError .= ' Xray error: ' . nrv_xray_last_error();
                    if (!$daemonResult['ok']) $installError .= ' Daemon error: ' . $daemonResult['error'];
                    $installError .= ' Check Settings after login and use Restart there.';
                }
                $_SESSION['nrv_logged_in'] = true;
                header('Location: ' . basename(__FILE__));
                exit;
            } else {
                nrv_save_config($newCfg);
            }
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NoRoot VPN Panel — Setup</title>
    <style><?php include __DIR__ . '/_nrv_style_inline.css.php'; ?></style>
    </head>
    <body>
    <div class="setup-wrap">
      <div class="setup-card">
        <h1>NoRoot VPN Panel</h1>
        <div class="subtitle">First-time setup</div>

        <?php if ($step === '1'): ?>
          <h2>Step 1 — Environment Check</h2>
          <div class="test-list">
            <?php foreach ($envTests as $t): ?>
              <div class="row">
                <div class="name"><?= htmlspecialchars($t['name']) ?><div class="detail"><?= htmlspecialchars($t['detail']) ?></div></div>
                <span class="badge <?= $t['ok'] ? 'ok' : 'fail' ?>"><?= $t['ok'] ? 'OK' : 'FAIL' ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if ($envPassed): ?>
            <form method="get">
              <input type="hidden" name="step" value="2">
              <button class="btn" type="submit">Continue to Installation</button>
            </form>
          <?php else: ?>
            <div class="alert bad">The panel directory is not writable. This must be fixed before installation can proceed.</div>
          <?php endif; ?>
          <?php $procCtl = current(array_filter($envTests, fn($t) => $t['name'] === 'exec() function')); $procCtl2 = current(array_filter($envTests, fn($t) => $t['name'] === 'proc_open() function')); ?>
          <?php if ($envPassed && !($procCtl && $procCtl['ok']) && !($procCtl2 && $procCtl2['ok'])): ?>
            <div class="alert bad" style="margin-top:12px;">
              Neither exec() nor proc_open() is available on this host. Installation will still work, but Xray and the
              session broker daemon cannot be started/stopped from this panel — you'll need to start them yourself
              (e.g. via SSH or your host's process manager) after installing.
            </div>
          <?php endif; ?>

        <?php elseif ($step === '2'): ?>
          <h2>Step 2 — Installation</h2>
          <?php if ($installError): ?><div class="alert bad"><?= htmlspecialchars($installError) ?></div><?php endif; ?>
          <form method="post">
            <input type="hidden" name="step" value="2">
            <label>Base directory for Xray files</label>
            <input type="text" name="base_dir" value="<?= htmlspecialchars(NRV_DIR . '/bin') ?>">

            <label>Local Xray port (internal only)</label>
            <input type="text" name="local_port" value="10000">

            <label>SplitHTTP path</label>
            <input type="text" name="splithttp_path" value="xr<?= substr(md5(uniqid()), 0, 8) ?>">

            <label>Public host (domain clients connect to)</label>
            <input type="text" name="public_host" value="<?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '') ?>">

            <label>Public port</label>
            <input type="text" name="public_port" value="<?= (!empty($_SERVER['HTTPS']) || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? '443' : '80' ?>">

            <label style="display:flex;align-items:center;gap:8px;">
              <input type="checkbox" name="public_tls" value="1" style="width:auto;" <?= (!empty($_SERVER['HTTPS']) || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? 'checked' : '' ?>>
              Public endpoint uses HTTPS/TLS (uncheck for plain-HTTP testing, e.g. a local/dev URL)
            </label>

            <hr>

            <label>Admin username</label>
            <input type="text" name="admin_username" value="admin">

            <label>Admin password</label>
            <input type="password" name="admin_password" required minlength="6">

            <button class="btn" type="submit">Install</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// ---------- Installed but not logged in ----------
if (empty($_SESSION['nrv_logged_in'])) {
    $loginError = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = $_POST['username'] ?? '';
        $p = $_POST['password'] ?? '';
        if ($u === $cfg['admin_username'] && password_verify($p, $cfg['admin_password_hash'])) {
            $_SESSION['nrv_logged_in'] = true;
            $_SESSION['nrv_last_healthcheck'] = nrv_run_login_healthcheck($cfg);
            header('Location: ' . basename(__FILE__));
            exit;
        } else {
            $loginError = 'Invalid username or password.';
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NoRoot VPN Panel — Login</title>
    <style><?php include __DIR__ . '/_nrv_style_inline.css.php'; ?></style>
    </head>
    <body>
    <div class="setup-wrap">
      <div class="setup-card">
        <h1>NoRoot VPN Panel</h1>
        <div class="subtitle">Sign in</div>
        <?php if ($loginError): ?><div class="alert bad"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
        <form method="post">
          <label>Username</label>
          <input type="text" name="username" required>
          <label>Password</label>
          <input type="password" name="password" required>
          <button class="btn" type="submit">Sign in</button>
        </form>
      </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// Every authenticated page load gets a cheap, rate-limited liveness check —
// this is what makes a dead Xray/daemon process self-heal without an admin
// noticing and manually clicking Restart.
nrv_ensure_services_running($cfg);

// ============================================================
// AUTHENTICATED ACTIONS (POST)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nrv_action'])) {
    $action = $_POST['nrv_action'];

    if ($action === 'create_user') {
        $name = trim($_POST['name'] ?: ('user_' . substr(md5(uniqid()), 0, 5)));
        $cfg['users'][] = [
            'id' => nrv_uuid(),
            'name' => $name,
            'enabled' => true,
            'created' => date('c'),
        ];
        nrv_save_config($cfg);
        nrv_apply_users_and_restart($cfg);
        header('Location: ?page=users&created=1');
        exit;
    }

    if ($action === 'edit_user') {
        $id = $_POST['id'];
        foreach ($cfg['users'] as &$u) {
            if ($u['id'] === $id) { $u['name'] = trim($_POST['name']) ?: $u['name']; break; }
        }
        unset($u);
        nrv_save_config($cfg);
        header('Location: ?page=users&updated=1');
        exit;
    }

    if ($action === 'toggle_user') {
        $id = $_POST['id'];
        foreach ($cfg['users'] as &$u) {
            if ($u['id'] === $id) { $u['enabled'] = !($u['enabled'] ?? true); break; }
        }
        unset($u);
        nrv_save_config($cfg);
        nrv_apply_users_and_restart($cfg);
        header('Location: ?page=users&toggled=1');
        exit;
    }

    if ($action === 'regenerate_uuid') {
        $id = $_POST['id'];
        foreach ($cfg['users'] as &$u) {
            if ($u['id'] === $id) { $u['id'] = nrv_uuid(); break; }
        }
        unset($u);
        nrv_save_config($cfg);
        nrv_apply_users_and_restart($cfg);
        header('Location: ?page=users&regenerated=1');
        exit;
    }

    if ($action === 'delete_user') {
        $id = $_POST['id'];
        $cfg['users'] = array_values(array_filter($cfg['users'], fn($u) => $u['id'] !== $id));
        nrv_save_config($cfg);
        nrv_apply_users_and_restart($cfg);
        header('Location: ?page=users&deleted=1');
        exit;
    }

    if ($action === 'xray_start') {
        nrv_watchdog_reset($cfg);
        if (!nrv_xray_start($cfg)) $_SESSION['nrv_flash_error'] = nrv_xray_last_error();
        header('Location: ?page=settings');
        exit;
    }
    if ($action === 'xray_stop') { nrv_xray_stop($cfg); header('Location: ?page=settings'); exit; }
    if ($action === 'xray_restart') {
        nrv_watchdog_reset($cfg);
        if (!nrv_xray_restart($cfg)) $_SESSION['nrv_flash_error'] = nrv_xray_last_error();
        header('Location: ?page=settings');
        exit;
    }
    if ($action === 'rebuild_config') {
        nrv_watchdog_reset($cfg);
        nrv_apply_users_and_restart($cfg);
        $r = nrv_daemon_restart($cfg);
        if (!$r['ok']) $_SESSION['nrv_flash_error'] = $r['error'];
        header('Location: ?page=settings&rebuilt=1');
        exit;
    }
    if ($action === 'daemon_start') {
        nrv_watchdog_reset($cfg);
        $r = nrv_daemon_start($cfg);
        if (!$r['ok']) $_SESSION['nrv_flash_error'] = $r['error'];
        header('Location: ?page=settings');
        exit;
    }
    if ($action === 'daemon_stop') { nrv_daemon_stop($cfg); header('Location: ?page=settings'); exit; }
    if ($action === 'daemon_restart') {
        nrv_watchdog_reset($cfg);
        $r = nrv_daemon_restart($cfg);
        if (!$r['ok']) $_SESSION['nrv_flash_error'] = $r['error'];
        header('Location: ?page=settings');
        exit;
    }

    if ($action === 'force_cleanup') {
        nrv_watchdog_reset($cfg);
        $r = nrv_force_cleanup_stray_processes($cfg);
        if ($r['ok']) {
            $_SESSION['nrv_flash_info'] = 'Cleanup ran. ' . (empty($r['output']) ? 'No matching stray processes found.' : implode(' ', $r['output']));
        } else {
            $_SESSION['nrv_flash_error'] = $r['error'];
        }
        header('Location: ?page=settings&cleanup=1');
        exit;
    }

    if ($action === 'run_network_diagnostics') {
        $_SESSION['nrv_network_diag'] = nrv_run_network_diagnostics();
        header('Location: ?page=settings&diag=1');
        exit;
    }

    if ($action === 'update_xmux') {
        $cfg['xray']['xmux_max_concurrency'] = max(1, min(20, (int)$_POST['max_concurrency']));
        $cfg['xray']['xmux_max_connections'] = max(1, min(20, (int)$_POST['max_connections']));
        nrv_save_config($cfg);
        nrv_apply_users_and_restart($cfg);
        header('Location: ?page=settings&xmux_updated=1');
        exit;
    }

    if ($action === 'update_tls') {
        $cfg['xray']['public_tls'] = !empty($_POST['public_tls']);
        nrv_save_config($cfg);
        header('Location: ?page=settings&tls_updated=1');
        exit;
    }
}

// ============================================================
// DASHBOARD LAYOUT
// ============================================================
$page = $_GET['page'] ?? 'dashboard';
$xrayPid = nrv_xray_pid($cfg);
$daemonPid = nrv_daemon_pid($cfg);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NoRoot VPN Panel</title>
<style><?php include __DIR__ . '/_nrv_style_inline.css.php'; ?></style>
</head>
<body>
<div class="mobile-topbar">
  <div class="brand">NoRoot VPN</div>
  <nav>
    <a href="?page=dashboard" class="<?= $page === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
    <a href="?page=users" class="<?= $page === 'users' ? 'active' : '' ?>">Users</a>
    <a href="?page=settings" class="<?= $page === 'settings' ? 'active' : '' ?>">Settings</a>
    <a href="?action=logout" class="logout">Exit</a>
  </nav>
</div>
<div class="app">
  <aside class="sidebar">
    <div class="brand">NoRoot VPN</div>
    <nav>
      <a href="?page=dashboard" class="<?= $page === 'dashboard' ? 'active' : '' ?>"><span class="icon">&#9679;</span> Dashboard</a>
      <a href="?page=users" class="<?= $page === 'users' ? 'active' : '' ?>"><span class="icon">&#9675;</span> Users</a>
      <a href="?page=settings" class="<?= $page === 'settings' ? 'active' : '' ?>"><span class="icon">&#9881;</span> Settings</a>
      <a href="?action=logout" class="logout"><span class="icon">&#10005;</span> Logout</a>
    </nav>
  </aside>

  <main class="content">
    <?php if ($page === 'dashboard'): ?>
      <h1>Dashboard</h1>
      <?php if (!empty($_SESSION['nrv_last_healthcheck'])): ?>
        <?php $hc = $_SESSION['nrv_last_healthcheck']; unset($_SESSION['nrv_last_healthcheck']); ?>
        <?php if (!empty($hc['repairs'])): ?>
          <div class="alert good">
            Login check: <?= count($hc['repairs']) ?> repair(s) made —
            <?= htmlspecialchars(implode('; ', $hc['repairs'])) ?>
          </div>
        <?php endif; ?>
        <?php if (!$hc['xray_ok'] || !$hc['daemon_ok']): ?>
          <div class="alert bad">
            Login check: <?= !$hc['xray_ok'] ? 'Xray is not running. ' : '' ?><?= !$hc['daemon_ok'] ? 'Session broker daemon is not running.' : '' ?>
            See Settings to start manually.
          </div>
        <?php endif; ?>
        <?php foreach ($hc['warnings'] ?? [] as $w): ?>
          <div class="alert bad"><?= htmlspecialchars($w) ?></div>
        <?php endforeach; ?>
      <?php endif; ?>
      <div class="stat-grid">
        <?php
        $cpu = nrv_cpu_percent();
        $mem = nrv_mem_percent();
        $disk = nrv_disk_percent(NRV_DIR);
        foreach ([['CPU', $cpu], ['Memory', $mem], ['Disk', $disk]] as [$label, $val]):
        ?>
        <div class="stat-card">
          <div class="stat-label"><?= $label ?></div>
          <div class="stat-value"><?= $val === null ? 'N/A' : $val . '%' ?></div>
          <div class="stat-bar"><div class="stat-bar-fill" style="width: <?= $val ?? 0 ?>%"></div></div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="panel-box">
        <h2>Xray Status</h2>
        <p>Xray: <?= $xrayPid !== null ? '<span class="badge ok">Running</span> (PID ' . ($xrayPid ?: 'unknown') . ')' : '<span class="badge fail">Stopped</span>' ?></p>
        <p>Session Broker Daemon: <?= $daemonPid !== null ? '<span class="badge ok">Running</span> (PID ' . ($daemonPid ?: 'unknown') . ')' : '<span class="badge fail">Stopped — connections will fail</span>' ?></p>
        <p>Users: <?= count($cfg['users']) ?></p>
        <p>Public endpoint: <code><?= htmlspecialchars($cfg['xray']['public_host']) ?>:<?= $cfg['xray']['public_port'] ?></code></p>
        <p>Developed through research by Taha Gorji -- <a href="https://github.com/mr-r0ot">[CLIKE HERE]</a></p>
        <p>Please star our open-source project on GitHub to show your support! -- <a href="https://github.com/mr-r0ot/NoRootVpn-Panel">[CLIKE HERE]</a></p>
      </div>

    <?php elseif ($page === 'users'): ?>
      <h1>Users</h1>
      <?php if (isset($_GET['created'])): ?><div class="alert good">User created.</div><?php endif; ?>
      <?php if (isset($_GET['deleted'])): ?><div class="alert good">User deleted.</div><?php endif; ?>
      <?php if (isset($_GET['updated'])): ?><div class="alert good">User updated.</div><?php endif; ?>
      <?php if (isset($_GET['toggled'])): ?><div class="alert good">User status changed.</div><?php endif; ?>
      <?php if (isset($_GET['regenerated'])): ?><div class="alert good">UUID regenerated — the user's old config no longer works; share the new one.</div><?php endif; ?>

      <div class="panel-box">
        <h2>Add new user</h2>
        <form method="post">
          <input type="hidden" name="nrv_action" value="create_user">
          <label>Name</label>
          <input type="text" name="name" placeholder="e.g. john" required>
          <button class="btn" type="submit">Create</button>
        </form>
      </div>

      <div class="panel-box">
        <h2>All users</h2>
        <?php if (empty($cfg['users'])): ?>
          <p class="muted">No users yet.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Name</th><th>Status</th><th>UUID</th><th>Created</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($cfg['users'] as $u): $enabled = $u['enabled'] ?? true; ?>
            <tr>
              <td><?= htmlspecialchars($u['name']) ?></td>
              <td><span class="badge <?= $enabled ? 'ok' : 'fail' ?>"><?= $enabled ? 'Enabled' : 'Disabled' ?></span></td>
              <td><code><?= htmlspecialchars($u['id']) ?></code></td>
              <td><?= htmlspecialchars(substr($u['created'], 0, 10)) ?></td>
              <td class="actions">
                <button class="btn small" onclick="nrvShowConfig('<?= htmlspecialchars(addslashes($u['id'])) ?>', '<?= htmlspecialchars(addslashes($u['name'])) ?>')">Configs</button>
                <button class="btn small ghost" onclick="nrvShowEdit('<?= htmlspecialchars(addslashes($u['id'])) ?>', '<?= htmlspecialchars(addslashes($u['name'])) ?>')">Edit</button>
                <form method="post" style="display:inline">
                  <input type="hidden" name="nrv_action" value="toggle_user">
                  <input type="hidden" name="id" value="<?= htmlspecialchars($u['id']) ?>">
                  <button class="btn small ghost" type="submit"><?= $enabled ? 'Disable' : 'Enable' ?></button>
                </form>
                <form method="post" style="display:inline">
                  <input type="hidden" name="nrv_action" value="delete_user">
                  <input type="hidden" name="id" value="<?= htmlspecialchars($u['id']) ?>">
                  <button class="btn small danger" type="submit" onclick="return confirm('Delete this user?')">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>

      <div id="nrv-edit-modal" class="modal hidden">
        <div class="modal-box">
          <h2>Edit User</h2>
          <form method="post" id="nrv-edit-form">
            <input type="hidden" name="nrv_action" value="edit_user">
            <input type="hidden" name="id" id="nrv-edit-id">
            <label>Name</label>
            <input type="text" name="name" id="nrv-edit-name" required>
            <button class="btn" type="submit">Save name</button>
          </form>
          <hr>
          <p class="muted" style="margin-bottom: 10px;">
            Regenerating the UUID immediately invalidates this user's current config on every device they've
            installed it on — only do this if their credential may have leaked.
          </p>
          <form method="post" onsubmit="return confirm('This invalidates the user\'s current config everywhere. Continue?')">
            <input type="hidden" name="nrv_action" value="regenerate_uuid">
            <input type="hidden" name="id" id="nrv-edit-id-2">
            <button class="btn danger" type="submit">Regenerate UUID</button>
          </form>
          <div class="modal-actions">
            <button class="btn ghost" style="margin-top:14px;" onclick="document.getElementById('nrv-edit-modal').classList.add('hidden')">Close</button>
          </div>
        </div>
      </div>

      <div id="nrv-config-modal" class="modal hidden">
        <div class="modal-box">
          <h2 id="nrv-modal-title">Client Config</h2>
          <label>VLESS URI (import in v2rayN / v2rayNG)</label>
          <textarea id="nrv-cfg-uri" rows="3" readonly></textarea>
          <button class="btn small" onclick="nrvCopy('nrv-cfg-uri')">Copy</button>

          <label>Base64 Subscription</label>
          <textarea id="nrv-cfg-b64" rows="3" readonly></textarea>
          <button class="btn small" onclick="nrvCopy('nrv-cfg-b64')">Copy</button>

          <label>Full JSON client config</label>
          <textarea id="nrv-cfg-json" rows="10" readonly></textarea>
          <button class="btn small" onclick="nrvCopy('nrv-cfg-json')">Copy</button>

          <button class="btn" onclick="document.getElementById('nrv-config-modal').classList.add('hidden')">Close</button>
        </div>
      </div>

      <script>
        const NRV_USERS = <?php
          $map = [];
          foreach ($cfg['users'] as $u) { $map[$u['id']] = nrv_client_links($cfg, $u); }
          echo json_encode($map);
        ?>;
        function nrvShowConfig(id, name) {
          const c = NRV_USERS[id];
          document.getElementById('nrv-modal-title').textContent = 'Client Config — ' + name;
          document.getElementById('nrv-cfg-uri').value = c.uri;
          document.getElementById('nrv-cfg-b64').value = c.base64;
          document.getElementById('nrv-cfg-json').value = c.json;
          document.getElementById('nrv-config-modal').classList.remove('hidden');
        }
        function nrvShowEdit(id, name) {
          document.getElementById('nrv-edit-id').value = id;
          document.getElementById('nrv-edit-id-2').value = id;
          document.getElementById('nrv-edit-name').value = name;
          document.getElementById('nrv-edit-modal').classList.remove('hidden');
        }
        function nrvCopy(id) {
          const el = document.getElementById(id);
          el.select();
          document.execCommand('copy');
        }
      </script>

    <?php elseif ($page === 'settings'): ?>
      <h1>Settings</h1>
      <?php if (!empty($_SESSION['nrv_flash_error'])): ?>
        <div class="alert bad"><?= htmlspecialchars($_SESSION['nrv_flash_error']) ?></div>
        <?php unset($_SESSION['nrv_flash_error']); ?>
      <?php endif; ?>
      <?php if (!empty($_SESSION['nrv_flash_info'])): ?>
        <div class="alert good"><?= htmlspecialchars($_SESSION['nrv_flash_info']) ?></div>
        <?php unset($_SESSION['nrv_flash_info']); ?>
      <?php endif; ?>
      <?php $procCtlAvailable = nrv_process_control_available(); ?>
      <div class="panel-box">
        <h2>Xray Control</h2>
        <p>Status: <?= $xrayPid !== null ? '<span class="badge ok">Running</span> (PID ' . ($xrayPid ?: 'unknown') . ')' : '<span class="badge fail">Stopped</span>' ?></p>
        <?php if (!$procCtlAvailable): ?>
          <div class="alert bad" style="margin-bottom:12px;">
            Neither exec() nor proc_open() is available on this host — the buttons below are disabled.
            Start Xray manually: <code><?= htmlspecialchars($cfg['paths']['xray_bin']) ?> run -config <?= htmlspecialchars($cfg['paths']['xray_config']) ?></code>
          </div>
        <?php endif; ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="nrv_action" value="xray_start">
          <button class="btn small" type="submit" <?= $procCtlAvailable ? '' : 'disabled' ?>>Start</button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="nrv_action" value="xray_stop">
          <button class="btn small danger" type="submit" <?= $procCtlAvailable ? '' : 'disabled' ?>>Stop</button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="nrv_action" value="xray_restart">
          <button class="btn small" type="submit" <?= $procCtlAvailable ? '' : 'disabled' ?>>Restart</button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="nrv_action" value="rebuild_config">
          <button class="btn small" type="submit" <?= $procCtlAvailable ? '' : 'disabled' ?>>Rebuild Config &amp; Restart</button>
        </form>
        <?php if (isset($_GET['rebuilt'])): ?><div class="alert good" style="margin-top:12px;">Xray config rebuilt from current users and restarted.</div><?php endif; ?>
      </div>

      <div class="panel-box">
        <h2>Session Broker Daemon</h2>
        <p class="muted" style="margin-bottom:12px;">
          A persistent background process that holds the slow, long-lived
          part of each connection so individual web requests stay fast and
          don't exhaust this host's limited concurrent-process budget.
          Required for the tunnel to work — if this is stopped, connections
          will fail even while Xray itself is running.
        </p>
        <p>Status: <?= $daemonPid !== null ? '<span class="badge ok">Running</span> (PID ' . ($daemonPid ?: 'unknown') . ')' : '<span class="badge fail">Stopped</span>' ?></p>
        <?php if (!$procCtlAvailable): ?>
          <div class="alert bad" style="margin-bottom:12px;">
            Neither exec() nor proc_open() is available on this host — the buttons below are disabled.
            Start the daemon manually: <code><?= htmlspecialchars(nrv_find_php_binary()) ?> <?= htmlspecialchars(NRV_DIR . '/daemon.php') ?></code>
          </div>
        <?php endif; ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="nrv_action" value="daemon_start">
          <button class="btn small" type="submit" <?= $procCtlAvailable ? '' : 'disabled' ?>>Start</button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="nrv_action" value="daemon_stop">
          <button class="btn small danger" type="submit" <?= $procCtlAvailable ? '' : 'disabled' ?>>Stop</button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="nrv_action" value="daemon_restart">
          <button class="btn small" type="submit" <?= $procCtlAvailable ? '' : 'disabled' ?>>Restart</button>
        </form>
      </div>

      <div class="panel-box">
        <h2>Guaranteed Recovery via Cron</h2>
        <p class="muted" style="margin-bottom:12px;">
          The Start/Restart buttons above spawn Xray/the daemon from this web request — on some hosts,
          a process spawned this way gets killed the moment the request ends, so it never actually stays
          running no matter how many times you click Start. A cron job runs independently of any web
          request and doesn't have this problem, so it can genuinely guarantee recovery (within about a
          minute) even on hosts where the buttons above can't. Add this exact line in your host's Cron
          Jobs page (cPanel: Advanced &rarr; Cron Jobs), set to run every minute:
        </p>
        <pre class="log-box"><?= htmlspecialchars('* * * * * ' . nrv_find_php_binary() . ' ' . NRV_DIR . '/cron.php' . (NRV_IS_WINDOWS ? ' >NUL 2>&1' : ' >/dev/null 2>&1')) ?></pre>
        <?php if (!NRV_IS_WINDOWS): ?>
          <p class="muted" style="margin-top:14px;margin-bottom:10px;">
            If Start ever fails with "Address already in use" and you have no SSH access to kill the
            leftover process yourself, use this to find and kill any stray Xray/daemon processes still
            running from an earlier failed attempt:
          </p>
          <form method="post" style="display:inline">
            <input type="hidden" name="nrv_action" value="force_cleanup">
            <button class="btn small danger" type="submit" <?= nrv_exec_available() ? '' : 'disabled' ?>>Force kill stray Xray/daemon processes</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="panel-box">
        <h2>Concurrency Limit (xmux)</h2>
        <p class="muted" style="margin-bottom:14px;">
          Controls how many simultaneous HTTP connections a single client is allowed to open through the tunnel.
          Every simultaneous connection consumes one PHP process on this shared host, which has a hard, unchangeable
          limit. Set this as low as your host's limit requires — lower values reduce speed under heavy parallel load
          (e.g. many open browser tabs) but keep the tunnel responsive instead of queuing and timing out.
        </p>
        <?php if (isset($_GET['xmux_updated'])): ?><div class="alert good">Updated and applied to all users.</div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="nrv_action" value="update_xmux">
          <label>Max concurrent connections per client (maxConcurrency)</label>
          <input type="text" name="max_concurrency" value="<?= (int)($cfg['xray']['xmux_max_concurrency'] ?? 2) ?>">
          <label>Max underlying connections (maxConnections)</label>
          <input type="text" name="max_connections" value="<?= (int)($cfg['xray']['xmux_max_connections'] ?? 2) ?>">
          <button class="btn" type="submit">Save &amp; Rebuild</button>
        </form>
      </div>

      <div class="panel-box">
        <h2>Paths</h2>
        <table class="table">
          <tr><td>Base directory</td><td><code><?= htmlspecialchars($cfg['paths']['base_dir']) ?></code></td></tr>
          <tr><td>Xray binary</td><td><code><?= htmlspecialchars($cfg['paths']['xray_bin']) ?></code></td></tr>
          <tr><td>Xray config</td><td><code><?= htmlspecialchars($cfg['paths']['xray_config']) ?></code></td></tr>
          <tr><td>PID file</td><td><code><?= htmlspecialchars($cfg['paths']['xray_pid']) ?></code></td></tr>
          <tr><td>Log file</td><td><code><?= htmlspecialchars($cfg['paths']['xray_log']) ?></code></td></tr>
        </table>
      </div>

      <div class="panel-box">
        <h2>Network</h2>
        <table class="table">
          <tr><td>Local port</td><td><code><?= $cfg['xray']['local_port'] ?></code></td></tr>
          <tr><td>SplitHTTP path</td><td><code><?= htmlspecialchars($cfg['xray']['splithttp_path']) ?></code></td></tr>
          <tr><td>Public host</td><td><code><?= htmlspecialchars($cfg['xray']['public_host']) ?></code></td></tr>
          <tr><td>Public port</td><td><code><?= $cfg['xray']['public_port'] ?></code></td></tr>
          <tr><td>Proxy endpoint</td><td><code><?= htmlspecialchars($cfg['xray']['proxy_public_path'] ?? '/proxy.php') ?></code> (session/seq passed via query string)</td></tr>
        </table>
        <?php if (isset($_GET['tls_updated'])): ?><div class="alert good" style="margin-top:12px;">TLS setting updated for newly generated client configs.</div><?php endif; ?>
        <form method="post" style="margin-top:14px;">
          <input type="hidden" name="nrv_action" value="update_tls">
          <label style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" name="public_tls" value="1" style="width:auto;" <?= !empty($cfg['xray']['public_tls']) ? 'checked' : '' ?>>
            Public endpoint uses HTTPS/TLS (client configs will use <code>security=tls</code>; uncheck for plain-HTTP testing)
          </label>
          <button class="btn small" type="submit">Save</button>
        </form>
      </div>

      <div class="panel-box">
        <h2>Recent Xray Log</h2>
        <pre class="log-box"><?php
          $log = @file_get_contents($cfg['paths']['xray_log']);
          echo $log ? htmlspecialchars(implode("\n", array_slice(explode("\n", trim($log)), -20))) : '(empty)';
        ?></pre>
      </div>

      <div class="panel-box">
        <h2>Network Diagnostics</h2>
        <p class="muted" style="margin-bottom:12px;">
          Tests this HOST's own outbound network directly with plain HTTP requests — no Xray, no VLESS, no
          tunnel involved at all. If a real client's connection through the tunnel is slow or empty, run this
          first: if it's slow or fails here too, the problem is this host's own network path to that
          destination (nothing this panel's code can fix), not the tunnel.
        </p>
        <form method="post">
          <input type="hidden" name="nrv_action" value="run_network_diagnostics">
          <button class="btn small" type="submit">Run diagnostic</button>
        </form>
        <?php if (!empty($_SESSION['nrv_network_diag'])): ?>
          <table class="table" style="margin-top:14px;">
            <thead><tr><th>Target</th><th>Result</th><th>Connect</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($_SESSION['nrv_network_diag'] as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['label']) ?></td>
                <td>
                  <?php if ($r['ok']): ?>
                    <span class="badge ok">OK (<?= (int)$r['http_code'] ?>)</span>
                  <?php else: ?>
                    <span class="badge fail">Failed<?= $r['error'] ? ': ' . htmlspecialchars($r['error']) : '' ?></span>
                  <?php endif; ?>
                </td>
                <td><?= $r['connect_ms'] ?>ms</td>
                <td><?= $r['total_ms'] ?>ms</td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php unset($_SESSION['nrv_network_diag']); ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
<?php
} // end if (!defined('NRV_INCLUDED_AS_LIB'))
