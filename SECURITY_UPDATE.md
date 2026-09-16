# Security & Reliability Findings (Security Update Log)
### This file records security issues that have been identified, reviewed, and resolved in NoRootVpn-Panel.


---


# V2.0.0 vulnerabilities fixed in V2.5.0:

### 1. Public Attack Surface in `proxy.php`
**Level:** Medium
**Issue:** `proxy.php` accepts client-controlled `x_session` values and passes them to the daemon without panel authentication.
**Impact:** Unauthenticated session allocation can increase resource consumption and expose the daemon to abuse and DoS conditions.

### 2. Public `cron.php` Endpoint
**Level:** Medium
**Issue:** `cron.php` is publicly accessible and can trigger service-recovery logic without authentication.
**Impact:** Repeated requests may cause unnecessary watchdog activity, process checks, or process restarts, increasing resource consumption.

### 3. CAPTCHA Brute-Force Protection
**Level:** Medium
**Issue:** The current arithmetic CAPTCHA has only 17 possible answers.
**Impact:** The CAPTCHA can be exhaustively bypassed very quickly and should not be relied upon as the primary protection against automated login attempts.

### 4. `.htaccess` Is Not Universal
**Level:** Low / Medium
**Issue:** Protection of sensitive files depends partly on `.htaccess`.
**Impact:** On Nginx, IIS, or incorrectly configured Apache deployments, files intended to be protected may become directly accessible.

### 5. Logout via GET
**Level:** Low
**Issue:** Logout is performed through a GET request.
**Impact:** Third-party pages or resources may be able to trigger an unintended logout of an authenticated user.

## 6. Panel Version Detection
**Level:** Informational / Low
**Issue:** The panel does not clearly display its installed version and compare it with the latest project release.
**Impact:** Administrators may not immediately know whether the deployment is outdated or missing security fixes.

## Notes
**FullRate-limiter and OverHead**: we add FullRate-limiter and test, it's had a lots of OverHead, then we still light project and low Rate-limiter + some light limit check
**Auto TLS Set**: now project check TLS on host and set host config as defult

