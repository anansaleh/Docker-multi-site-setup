# Phase 07 — php-fpm 8.4 + Nginx Integration

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM)
**Host project root:** `~/Projects01/`
**Date completed:** 2026-10-14
**Status:** ✅ Complete — all checks passed

---

## Objective

Wire `php-fpm84` to Nginx so all three sites serve PHP through FastCGI. This is the **first real PHP integration** — the moment our custom PHP base image (Phase 5) meets the web server (Phase 3).

**What this proves end-to-end:**
- Nginx ← request from browser/curl
- Nginx matches vhost by hostname
- Nginx routes `.php` to `php-fpm84:9000` over Docker's internal network
- FPM executes the PHP file at `/var/www/html/siteN/…`
- Response flows back through Nginx to the client

**No PHP 7.4 yet.** One PHP version, one web server. Extending to 7.4 (Phase 8) and Apache (Phase 9) becomes mechanical after this works.

---

## What We Built

```
~/Projects01/docker-env/
├── php-fpm84/
│   └── Dockerfile                 (thin: FROM php-base:8.4)
├── nginx/
│   └── sites/
│       ├── default.conf           (updated: PHP handler added)
│       ├── site1.conf             (updated: PHP handler added)
│       └── site2.conf             (updated: PHP handler added)
└── docker-compose.yml             (extended: php-fpm84 service)

~/Projects01/sites/site1/info.php       (new)
~/Projects01/sites/site2/info.php       (new)
~/Projects01/default-host/v3client/info.php  (new)
```

---

## Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| php-fpm84 Dockerfile | Thin (`FROM php-base:8.4`) | Base image has everything; child adds nothing yet |
| FPM listen | `php-fpm84:9000` (internal) | Docker's service-name DNS on `dev-env_dev-net` |
| Host ports for FPM | None | Internal-only; only web servers need host ports |
| Root path | `/var/www/html` — same in nginx and php-fpm84 | Path consistency is critical for SCRIPT_FILENAME |
| Sites served | All three (dev, site1, site2) | No per-site PHP toggling yet |
| Test file | `info.php` per site | Confirms PHP version + server + container path |
| `index` directive | `index index.html index.php;` | Static HTML wins at `/`; PHP wins at `/x.php` |
| Logging | `logs/php-fpm84/error.log` (host-mounted) | Runtime errors land on host for easy debugging |

---

## The `php-fpm84/Dockerfile`

```dockerfile
# ============================================================
# php-fpm 8.4 container
# Thin layer over the shared base image.
# ============================================================
FROM php-base:8.4

# Copy the php-fpm specific config (if we ever need to override)
# Currently using the default from the base image.

WORKDIR /var/www/html

# Default CMD from php:8.4-fpm image is `php-fpm` — inherit it.
```

**Image built:** `dev-env-php-fpm84:latest`

---

## The Nginx PHP Handler (the critical addition)

Added to every vhost (`default.conf`, `site1.conf`, `site2.conf`) inside both the HTTP and HTTPS `server` blocks:

```nginx
location ~ \.php$ {
    try_files $uri =404;
    fastcgi_split_path_info ^(.+\.php)(/.+)$;
    fastcgi_pass php-fpm84:9000;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param PATH_INFO $fastcgi_path_info;
}
```

**Line-by-line:**
- `location ~ \.php$` — regex: any URI ending in `.php`.
- `try_files $uri =404;` — 404 if the file doesn't exist (prevents path-traversal attacks).
- `fastcgi_split_path_info` — separates the script path from `PATH_INFO` (e.g., `/index.php/foo/bar`).
- `fastcgi_pass php-fpm84:9000;` — **the key line**: sends the request to the php-fpm84 container on port 9000.
- `SCRIPT_FILENAME $document_root$fastcgi_script_name;` — tells FPM which file to execute. `$document_root` comes from `root` directive, `$fastcgi_script_name` from the split.

---

## The `docker-compose.yml` (php-fpm84 service)

```yaml
  php-fpm84:
    build:
      context: ./php-fpm84
    image: dev-env-php-fpm84
    container_name: dev-php-fpm84
    restart: unless-stopped
    volumes:
      - ${PROJECTS_ROOT}/sites/site1:/var/www/html/site1
      - ${PROJECTS_ROOT}/sites/site2:/var/www/html/site2
      - ${PROJECTS_ROOT}/default-host/v3client:/var/www/html/v3client
      - ${PROJECTS_ROOT}/default-host/client_data:/home/client_data
      - ${PROJECTS_ROOT}/default-host/server_side:/home/server_side
      - ${PROJECTS_ROOT}/docker-env/logs/php-fpm84:/var/log/php
    environment:
      - TZ=${TZ}
    networks:
      - dev-net
```

**Notes:**
- **No `ports:`** — FPM is internal-only.
- **Same site mounts as nginx** — identical paths in both containers.
- **Log mount** at `/var/log/php` matches `error_log` in `php.ini`.
- **`depends_on`:** added to nginx service so it starts after FPM.

---

## Step-by-Step Record

### Step 7.1 — Folder and Dockerfile

```bash
mkdir -p ~/Projects01/docker-env/php-fpm84
# Dockerfile written
```

**Result:** ✅ Pass.

---

### Step 7.2 — Base image verified

```bash
docker images php-base:8.4
```

**Output:** image present, ID `9874174b1182`, 1.85 GB.

---

### Step 7.3 — Build

```bash
cd ~/Projects01/docker-env/php-fpm84
docker build -t dev-env-php-fpm84 .
```

**Output:**
```
[+] Building 0.2s (6/6) FINISHED
 => [1/2] FROM docker.io/library/php-base:8.4@sha256:9874174b11820af146860fbea601e6cf4829dce707cdd878aa56a3c9a0c0a58c
 => [2/2] WORKDIR /var/www/html
 => => naming to docker.io/library/dev-env-php-fpm84:latest
```

**Result:** ✅ Image built in 0.2s (all layers inherited from base).

---

### Step 7.4 — `info.php` in each site

**Files created:**
- `~/Projects01/sites/site1/info.php` (212 bytes)
- `~/Projects01/sites/site2/info.php` (212 bytes)
- `~/Projects01/default-host/v3client/info.php` (237 bytes)

**Each file:**
```php
<?php
echo "<h1>siteN.test — PHP via FPM</h1>";
echo "<p>PHP version: " . phpversion() . "</p>";
echo "<p>Server software: " . $_SERVER['SERVER_SOFTWARE'] . "</p>";
echo "<p>Served from: " . __FILE__ . "</p>";
```

**Existing `index.html` files preserved.**

---

### Step 7.5 — Update three Nginx vhosts

Added the PHP handler (see above) to `default.conf`, `site1.conf`, `site2.conf`.

**File sizes after update:**
- `default.conf` — 1732 bytes (was 1056)
- `site1.conf` — 1527 bytes (was 851)
- `site2.conf` — 1527 bytes (was 851)

**Result:** ✅ All three updated.

---

### Step 7.6 — Extend `docker-compose.yml`

Added `php-fpm84` service and `depends_on` for nginx.

**Validated with:**
```bash
docker compose config
```

**Result:** ✅ Three services defined, no variable substitution errors, php-fpm84 has no `ports:` section.

---

### Step 7.7 — Build and start

```bash
docker compose build nginx     # 1.2s
docker compose up -d            # all services
docker compose ps
```

**Output:**
```
NAME            IMAGE               COMMAND                  STATUS              PORTS
dev-apache2     dev-env-apache2     "httpd-foreground"       Up 2 hours          8080, 8443
dev-nginx       dev-env-nginx       "/docker-entrypoint.…"   Up < 1s             80, 443
dev-php-fpm84   dev-env-php-fpm84   "docker-php-entrypoi…"   Up < 1s             9000/tcp (internal)
```

**Result:** ✅ All three containers running.

---

### Step 7.8 — FPM logs

```bash
docker compose logs php-fpm84
```

**Output:**
```
dev-php-fpm84  | [14-Oct-2026 11:37:28] NOTICE: fpm is running, pid 1
dev-php-fpm84  | [14-Oct-2026 11:37:28] NOTICE: ready to handle connections
```

**Result:** ✅ FPM ready, no errors.

---

### Step 7.9 — HTTP PHP execution

**Commands:**
```bash
curl -sS http://site1.test/info.php
curl -sS http://site2.test/info.php
curl -sS http://dev.test/info.php
```

**Output:**
```
<h1>site1.test — PHP via FPM</h1>
<p>PHP version: 8.4.26</p>
<p>Server software: nginx/1.27.5</p>
<p>Served from: /var/www/html/site1/info.php</p>

<h1>site2.test — PHP via FPM</h1>
<p>PHP version: 8.4.26</p>
<p>Server software: nginx/1.27.5</p>
<p>Served from: /var/www/html/site2/info.php</p>

<h1>Default site (dev.test / localhost) — PHP via FPM</h1>
<p>PHP version: 8.4.26</p>
<p>Server software: nginx/1.27.5</p>
<p>Served from: /var/www/html/v3client/info.php</p>
```

**Result:** ✅ **First real PHP execution in the environment.**

Proves:
- Nginx → FPM routing works
- PHP 8.4.26 (from our base image, not system PHP)
- Server software `nginx/1.27.5` (request passed through Nginx)
- Container paths (`/var/www/html/site1/…`) match our design

---

### Step 7.10 — HTTPS PHP execution

**Commands:**
```bash
curl -sS https://site1.test/info.php
curl -sS https://site2.test/info.php
curl -sS https://dev.test/info.php
```

**Result:** ✅ Identical output to Step 7.9 — HTTPS + PHP works.

---

### Step 7.11 — Extension access from FPM

Created `ext-test.php` that checks 50 expected extensions + key classes.

**Output:**
```
PHP version: 8.4.26
Server API: fpm-fcgi

MISSING extensions: opcache

Key classes:
  Imagick: yes
  Redis: yes
  MongoDB\Driver\Manager: yes
  ZMQ: yes
  PDO: yes
  AMQPConnection: yes
  APCu: yes
```

**Verification:** Ran `opcache-check.php` — confirmed OPcache **is** working:
```
extension_loaded('Zend OPcache'): yes
extension_loaded('opcache'):      no
function_exists('opcache_get_status'): yes

OPcache enabled: yes
OPcache memory used: 8.75 MB
Cached scripts: 5
```

**Root cause of "missing opcache":** A PHP quirk — OPcache registers itself as `Zend OPcache`, not `opcache`. `extension_loaded('opcache')` returns false, but the extension is fully functional.

**Verdict:** All extensions loaded. FPM SAPI confirmed (`fpm-fcgi`).

---

### Step 7.12 — Nginx access logs

**`site1.access.log` tail:**
```
192.168.65.1 ... "GET /info.php HTTP/1.1" 200 156 "-" "curl/8.7.1" "-"
192.168.65.1 ... "GET /info.php HTTP/2.0" 200 145 "-" "curl/8.7.1" "-"
192.168.65.1 ... "GET /info.php?ext=1 HTTP/1.1" 200 156 "-" "curl/8.7.1" "-"
192.168.65.1 ... "GET /ext-test.php HTTP/1.1" 200 209 "-" "curl/8.7.1" "-"
192.168.65.1 ... "GET /opcache-check.php HTTP/1.1" 200 198 "-" "curl/8.7.1" "-"
```

**`site2.access.log` tail:**
```
192.168.65.1 ... "GET /info.php HTTP/1.1" 200 156 "-" "curl/8.7.1" "-"
192.168.65.1 ... "GET /info.php HTTP/2.0" 200 145 "-" "curl/8.7.1" "-"
```

**Result:** ✅ All PHP requests logged with `200`.

**Note:** `192.168.65.1` and `172.18.0.1` are Docker Desktop VM gateway IPs on macOS — expected client IPs for requests originating from the host.

---

### Step 7.12b — Nginx error logs

**All error logs empty:**
- `error.log` — 0 bytes
- `site1.error.log` — 0 bytes
- `site2.error.log` — 0 bytes
- `default.error.log` — 0 bytes

**Result:** ✅ No errors since PHP integration.

---

### Step 7.13 — PHP error logging to host

Created `error-test.php` that triggers a fatal error:

```php
<?php
$x = 1 / 0;
trigger_error("Test error from error-test.php", E_USER_WARNING);
```

**Response:**
```
<br />
<b>Fatal error</b>:  Uncaught DivisionByZeroError: Division by zero in /var/www/html/site1/error-test.php:3
Stack trace:
#0 {main}
  thrown in <b>/var/www/html/site1/error-test.php</b> on line <b>3</b><br />
```

**Error log on host (`~/Projects01/docker-env/logs/php-fpm84/error.log`):**
```
[14-Oct-2026 11:48:13 Europe/London] PHP Fatal error:  Uncaught DivisionByZeroError: Division by zero in /var/www/html/site1/error-test.php:3
Stack trace:
#0 {main}
  thrown in /var/www/html/site1/error-test.php on line 3
```

**Result:** ✅ Confirms:
- Bind mount is writable by FPM's `www-data` user
- `log_errors = On` + `error_log` setting are honored under FPM
- Timezone from `php.ini` (`Europe/London`) is applied to log timestamps
- Container paths (not host paths) appear in errors — needed for Xdebug path mapping

**Cleanup:** `error-test.php` and other test files removed. Only `index.html` and `info.php` remain in each site root.

---

### Step 7.14 — Browser test

**Verified in browser:**
- `https://site1.test/info.php` ✅
- `https://site2.test/info.php` ✅
- `https://dev.test/info.php` ✅
- `https://localhost/info.php` ✅

All show PHP 8.4.26 with padlock. No warnings.

---

### Step 7.15 — Static HTML regression test

**Commands:**
```bash
curl -sS http://site1.test
curl -sS http://site2.test
curl -sS http://dev.test
```

**Output:**
```
<h1>Site 1 — static placeholder</h1>
<h1>Site 2 — static placeholder</h1>
<h1>Default — v3client placeholder</h1>
```

**Result:** ✅ Static HTML serving still works. No regression.

The `index index.html index.php;` directive ensures `/` serves `index.html` when both exist.

---

## Phase 7 Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | `php-fpm84/` folder and Dockerfile created | ✅ |
| 2 | Base image `php-base:8.4` verified | ✅ |
| 3 | `dev-env-php-fpm84` image built | ✅ |
| 4 | `info.php` in all three site roots | ✅ |
| 5 | Three Nginx vhosts updated with PHP handler | ✅ |
| 6 | `docker-compose.yml` extended | ✅ |
| 7 | All services running | ✅ |
| 8 | FPM logs clean | ✅ |
| 9 | HTTP PHP works (site1, site2, dev) | ✅ |
| 10 | HTTPS PHP works | ✅ |
| 11 | All extensions accessible from FPM | ✅ |
| 12 | OPcache confirmed working (via Zend OPcache check) | ✅ |
| 13 | Nginx access logs show PHP requests | ✅ |
| 14 | Nginx error logs empty | ✅ |
| 15 | PHP error logging works to host | ✅ |
| 16 | Browser test passes | ✅ |
| 17 | Static HTML still works (no regression) | ✅ |

**Phase 7 result: ✅ PASS**

---

## Carry-Forward Notes for Future Phases

1. **PHP handler pattern** (reuse for Phase 8's php-fpm74 and Phase 9's Apache):
   ```nginx
   location ~ \.php$ {
       try_files $uri =404;
       fastcgi_split_path_info ^(.+\.php)(/.+)$;
       fastcgi_pass php-fpm84:9000;
       fastcgi_index index.php;
       include fastcgi_params;
       fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
       fastcgi_param PATH_INFO $fastcgi_path_info;
   }
   ```
   **Phase 8 will only change one line:** `fastcgi_pass php-fpm74:9000;`

2. **Service name = DNS hostname.** `php-fpm84` is the service name and the hostname Nginx uses to reach it. This is provided by Docker's internal DNS on `dev-env_dev-net`.

3. **Path consistency is non-negotiable.** Nginx `root /var/www/html/site1` and php-fpm84's mount `/var/www/html/site1` must match. Otherwise FPM opens the wrong path or 404s.

4. **php-fpm84 has no host ports.** `docker compose ps` shows `9000/tcp` (internal) with no `0.0.0.0:9000->9000` mapping. This is correct — only web servers should be externally reachable.

5. **`index` directive order:** `index index.html index.php;` — HTML wins at `/`. In real projects, either delete `index.html` or change order to `index index.php index.html;`.

6. **PHP error logging path:** `~/Projects01/docker-env/logs/php-fpm84/error.log` — the host-side location. Runtime errors (fatals, warnings, notices) appear here.

7. **Timezone in PHP logs:** `Europe/London` — from `date.timezone` in `php.ini`. Differs from Apache logs (UTC). Expected.

8. **OPcache quirk:** `extension_loaded('opcache')` returns false. Use `extension_loaded('Zend OPcache')` or `function_exists('opcache_get_status')`. OPcache is enabled and functional.

9. **`display_errors = On`** — PHP errors appear in HTTP responses during dev. This is intentional for development visibility.

10. **Test files kept:**
    - `index.html` (static placeholder)
    - `info.php` (useful for future debugging — safe to keep or remove)
    
    **Test files removed:**
    - `ext-test.php`, `opcache-check.php`, `error-test.php`

11. **Container images:**
    - `php-base:8.4` → `9874174b1182`
    - `dev-env-php-fpm84:latest` (thin, inherits from base)

12. **Container name:** `dev-php-fpm84`. Use this for `docker compose exec dev-php-fpm84 bash` when debugging.

---

## Files and System Changes in Phase 7

**Files created:**

```
~/Projects01/docker-env/php-fpm84/Dockerfile
~/Projects01/sites/site1/info.php
~/Projects01/sites/site2/info.php
~/Projects01/default-host/v3client/info.php
```

**Files modified:**

```
~/Projects01/docker-env/nginx/sites/default.conf    (PHP handler added)
~/Projects01/docker-env/nginx/sites/site1.conf      (PHP handler added)
~/Projects01/docker-env/nginx/sites/site2.conf      (PHP handler added)
~/Projects01/docker-env/docker-compose.yml          (php-fpm84 service added)
```

**Docker objects created:**

- Image: `dev-env-php-fpm84:latest`
- Container: `dev-php-fpm84` (no host ports)

**Docker objects rebuilt:**

- Image: `dev-env-nginx:latest`
- Container: `dev-nginx` (restarted)

---

## Rollback Procedure

To undo Phase 7:

```bash
cd ~/Projects01/docker-env
docker compose down
docker rmi dev-env-php-fpm84:latest
```

To revert the Nginx configs and compose file:
- Restore Phase 4 versions of `nginx/sites/*.conf` (no PHP handler)
- Remove `php-fpm84` service and `depends_on: php-fpm84` from `docker-compose.yml`
- Rebuild nginx and restart

No data loss — no databases involved.

---
## Summary of Phase 7:

- ✅ 17/17 checks passed
- ✅ dev-env-php-fpm84 image built (thin layer over php-base:8.4)
- ✅ All three sites serving PHP 8.4.26 through Nginx + FastCGI
- ✅ Both HTTP and HTTPS work
- ✅ Full extension set accessible
- ✅ OPcache confirmed working (naming quirk resolved)
- ✅ PHP error logging to host folder confirmed
- ✅ Static HTML serving preserved
- ✅ No errors in any logs
- ✅ Ready for extension to multiple PHP versions and multiple web servers


---

## Next Phase

**Phase 8 — php-fpm 7.4 + a Site Assigned to 7.4**

Goal: wire `php-fpm74` to Nginx, and route **one** site (e.g., `site2.test`) through PHP 7.4 instead of 8.4 — proving two PHP versions run simultaneously on different sites.

Phase 8 will:
- Create `docker-env/php-fpm74/Dockerfile` (thin: `FROM php-base:7.4`)
- Extend `docker-compose.yml` with the `php-fpm74` service
- Update `site2.conf` only — change `fastcgi_pass php-fpm84:9000;` to `fastcgi_pass php-fpm74:9000;`
- Verify `site1.test` → 8.4, `site2.test` → 7.4, `dev.test` → 8.4
- Confirm both FPM containers run side by side

Phase 8 begins once this document is saved and confirmed.

---

**End of phase_07.md**