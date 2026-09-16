<?php
/**
 * NoRoot VPN Panel — Cron entry point.
 *
 * Runs the same self-healing watchdog used on every panel page load and
 * every proxy.php request (nrv_ensure_services_running), but triggered by
 * the host's own cron scheduler instead of an HTTP request. This matters on
 * hosts where a background process spawned from a web request gets killed
 * the moment that request ends — a cron-invoked PHP CLI process has no such
 * lifetime tied to it, so anything it spawns has a real chance of surviving.
 *
 * Add this to your host's cron jobs (see Settings page for the exact line,
 * already including the required secret token, with your real paths filled
 * in): * * * * * <php binary> <this file> <token> >/dev/null 2>&1
 *
 * This file is necessarily public (a cron job has no session/cookie to
 * authenticate with), so it's gated by a random per-install secret instead —
 * without a valid token, this does nothing at all. Accepts the token either
 * as a CLI argument (normal cron invocation) or as ?token=... (for the rare
 * host that only offers URL-ping-style "cron").
 */

define('NRV_INCLUDED_AS_LIB', true);
require __DIR__ . '/NoRoot-Panel.php';

$cfg = nrv_load_config();
if ($cfg && !empty($cfg['installed'])) {
    $nrvExpectedToken = nrv_ensure_cron_token($cfg);
    $nrvProvidedToken = $_GET['token'] ?? (isset($argv[1]) ? $argv[1] : null);
    if (is_string($nrvProvidedToken) && hash_equals($nrvExpectedToken, $nrvProvidedToken)) {
        nrv_ensure_services_running($cfg);
    }
}
