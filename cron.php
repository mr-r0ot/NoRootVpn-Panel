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
 * Add this to your host's cron jobs (see Settings page for the exact line
 * with your real paths already filled in):
 *   * * * * * <php binary> <this file> >/dev/null 2>&1
 */

define('NRV_INCLUDED_AS_LIB', true);
require __DIR__ . '/NoRoot-Panel.php';

$cfg = nrv_load_config();
if ($cfg && !empty($cfg['installed'])) {
    nrv_ensure_services_running($cfg);
}
