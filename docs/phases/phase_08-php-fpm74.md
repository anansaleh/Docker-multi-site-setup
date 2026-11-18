# Phase 08 — php-fpm 7.4 + a Site Assigned to 7.4

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM)
**Host project root:** `~/Projects01/`
**Date completed:** 2026-10-15
**Status:** ✅ Complete — all checks passed

---

## Objective

Wire `php-fpm74` to Nginx and route **one site** (`site2.test`) through PHP 7.4 instead of 8.4. Prove that two PHP versions can run simultaneously and serve different sites through the same web server.

**This is the proof-of-concept for the entire multi-PHP design.** Once it works, extending the pattern to more sites or versions is mechanical.

---

## What This Phase Proves

- Two PHP-FPM containers can run side by side on the same Docker network, each with its own PHP version.
- Nginx can route different vhosts to different FPM backends based on the `fastcgi_pass` directive.
- Both FPM containers see the same site codebases via identical mounts.
- No cross-contamination: each request goes to its assigned PHP version, running its own compiled stack.
- A 7.4-only extension (`imap`) genuinely loads on 7.4 and genuinely cannot load on 8.4.

---

## Final Routing After Phase 8

| URL | PHP version | FPM container | Nginx vhost |
|---|---|---|---|
| `site1.test` | 8.4.26 | `php-fpm84:9000` | `site1.conf` |
| `site2.test` | **7.4.33** | `php-fpm74:9000` | `site2.conf` |
| `dev.test` / `localhost` / `127.0.0.1` | 8.4.26 | `php-fpm84:9000` | `default.conf` |

---

## What We Built

```
~/Projects01/docker-env/
├── php-fpm74/
│   └── Dockerfile                  (thin: FROM php-base:7.4)
├── logs/
│   └── php-fpm74/                  (new — PHP 7.4 error log folder)
├── nginx/
│   └── sites/
│       ├── default.conf            (unchanged — still php-fpm84)
│       ├── site1.conf              (unchanged — still php-fpm84)
│       └── site2.conf              (CHANGED — now php-fpm74)
└── docker-compose.yml              (extended: php-fpm74 service added)
```

---

## Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| php-fpm74 Dockerfile | Thin (`FROM php-base:7.4`) | Base image has everything |
| Which site goes to 7.4 | `site2.test` | Arbitrary — needed one site to demonstrate |
| Nginx change | **One line per block** in `site2.conf` | `fastcgi_pass php-fpm84:9000;` → `fastcgi_pass php-fpm74:9000;` |
| Both FPM containers | Same internal port (9000) | No conflict — Docker DNS resolves by service name |
| Ports | Both internal-only (9000) | Only web servers need host ports |
| Logs | `logs/php-fpm74/` (new, separate from 8.4) | Independent error tracking per PHP version |
| nginx `depends_on` | Both FPM services | Ensures nginx starts after both are ready |
| Site code mounts | Identical for both FPM containers | Every FPM can see every site, so switching versions = 1-line change |

---

## The `php-fpm74/Dockerfile`

```dockerfile
# ============================================================
# php-fpm 7.4 container
# Thin layer over the shared base image.
# ============================================================
FROM php-base:7.4

WORKDIR /var/www/html

# Default CMD from php:7.4-fpm image is `php-fpm` — inherit it.
```

**Image built:** `dev-env-php-fpm74:latest`

---

## The `docker-compose.yml` — php-fpm74 service

```yaml
  php-fpm74:
    build:
      context: ./php-fpm74
    image: dev-env-php-fpm74
    container_name: dev-php-fpm74
    restart: unless-stopped
    volumes:
      - ${PROJECTS_ROOT}/sites/site1:/var/www/html/site1
      - ${PROJECTS_ROOT}/sites/site2:/var/www/html/site2
      - ${PROJECTS_ROOT}/default-host/v3client:/var/www/html/v3client
      - ${PROJECTS_ROOT}/default-host/client_data:/home/client_data
      - ${PROJECTS_ROOT}/default-host/server_side:/home/server_side
      - ${PROJECTS_ROOT}/docker-env/logs/php-fpm74:/var/log/php
    environment:
      - TZ=${TZ}
    networks:
      - dev-net
```

**Notes:**
- No `ports:` — internal only (same as php-fpm84).
- Same site code mounts as php-fpm84.
- Log path is unique (`logs/php-fpm74`).

Also, `nginx` service was updated:
```yaml
    depends_on:
      - php-fpm84
      - php-fpm74
```

---

## The Nginx change — one line, four times

In `site2.conf`, both `location ~ \.php$` blocks (HTTP and HTTPS) were updated.

**Before:**
```nginx
fastcgi_pass php-fpm84:9000;
```

**After:**
```nginx
fastcgi_pass php-fpm74:9000;
```

Everything else in `site2.conf` stayed the same. Site1 and default were left untouched.

---

## Step-by-Step Record

### Step 8.1 — Folder and log directory created

```bash
mkdir -p ~/Projects01/docker-env/php-fpm74
mkdir -p ~/Projects01/docker-env/logs/php-fpm74
```

**Result:** ✅ Pass.

---

### Step 8.2 — Base image verified

```bash
docker images php-base:7.4
```

**Output:** present, ID `d8a1443bafa0`, 1.72 GB.

---

### Step 8.3 — Build

```bash
cd ~/Projects01/docker-env/php-fpm74
docker build -t dev-env-php-fpm74 .
```

**Output:**
```
[+] Building 0.2s (6/6) FINISHED
 => [1/2] FROM docker.io/library/php-base:7.4@sha256:d8a1443bafa0...
 => [2/2] WORKDIR /var/www/html
 => => naming to docker.io/library/dev-env-php-fpm74:latest
```

**Result:** ✅ Image built in 0.2s.

---

### Step 8.4 — Update `site2.conf` — one-line change

**Command:**
```bash
sed -i '' 's/fastcgi_pass php-fpm84:9000;/fastcgi_pass php-fpm74:9000;/g' ~/Projects01/docker-env/nginx/sites/site2.conf
```

**Note on macOS `sed`:** BSD sed requires `-i ''` (empty extension), not `sed -i`.

**Verification (grep counts):**

| File | `php-fpm74:9000` | `php-fpm84:9000` |
|---|---|---|
| `site2.conf` | **2** | **0** |
| `site1.conf` | 0 | **2** |
| `default.conf` | 0 | **2** |

**Result:** ✅ Pass — surgical change, only site2 was affected.

---

### Step 8.5 — Extend `docker-compose.yml`

Added `php-fpm74` service and updated nginx `depends_on`.

**Validated with `docker compose config`:**
- Four services defined: `nginx`, `apache2`, `php-fpm84`, `php-fpm74`.
- nginx `depends_on` lists both FPM services.
- php-fpm74 has no `ports:`.
- php-fpm74 mounts are identical to php-fpm84 (plus its own log path).

**Result:** ✅ Pass.

---

### Step 8.6 — Rebuild nginx and start php-fpm74

**Commands:**
```bash
docker compose build nginx    # 1.2s
docker compose up -d
docker compose ps
```

**Output:**
```
NAME            IMAGE               COMMAND                  STATUS              PORTS
dev-apache2     dev-env-apache2     "httpd-foreground"       Up About an hour    8080, 8443
dev-nginx       dev-env-nginx       "/docker-entrypoint.…"   Up < 1s             80, 443
dev-php-fpm74   dev-env-php-fpm74   "docker-php-entrypoi…"   Up < 1s             9000/tcp
dev-php-fpm84   dev-env-php-fpm84   "docker-php-entrypoi…"   Up About an hour    9000/tcp
```

**Result:** ✅ Four containers running. Both FPMs internal-only on port 9000.

---

### Step 8.7 — FPM 7.4 logs

```bash
docker compose logs php-fpm74
```

**Output:**
```
dev-php-fpm74  | [15-Oct-2026 10:18:10] NOTICE: fpm is running, pid 1
dev-php-fpm74  | [15-Oct-2026 10:18:10] NOTICE: ready to handle connections
```

**Result:** ✅ FPM 7.4 ready.

---

### Step 8.8 — HTTP dual-version test 🎉

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
<p>PHP version: 7.4.33</p>
<p>Server software: nginx/1.27.5</p>
<p>Served from: /var/www/html/site2/info.php</p>

<h1>Default site (dev.test / localhost) — PHP via FPM</h1>
<p>PHP version: 8.4.26</p>
<p>Server software: nginx/1.27.5</p>
<p>Served from: /var/www/html/v3client/info.php</p>
```

**Result:** ✅ **Pass — the core milestone.**

- `site1.test` → PHP 8.4.26
- `site2.test` → **PHP 7.4.33**
- `dev.test` → PHP 8.4.26

**Two PHP versions serving two sites through one Nginx.** This is the design goal realized.

---

### Step 8.9 — HTTPS dual-version test

**Commands:**
```bash
curl -sS https://site1.test/info.php
curl -sS https://site2.test/info.php
curl -sS https://dev.test/info.php
```

**Output:** Identical routing to Step 8.8, over HTTPS.

**Result:** ✅ Pass — TLS termination is orthogonal to PHP version routing.

---

### Step 8.10 — 7.4-specific extension verification

Created `site2/version-check.php` to test PHP version and key extensions.

**Output:**
```
PHP version: 7.4.33
Server API: fpm-fcgi

imap (7.4 only)           : LOADED
intl                      : LOADED
imagick                   : LOADED
redis                     : LOADED
mongodb                   : LOADED
zmq                       : LOADED
xdebug                    : LOADED
```

**Result:** ✅ **Definitive proof** — `imap` loads (impossible on 8.4). This isn't just a version number; it's genuinely the 7.4 stack running.

**Cleanup:** `version-check.php` was removed after the test.

---

### Step 8.11 — Browser test

Confirmed in Chrome:
- `https://site1.test/info.php` → PHP 8.4.26 ✅
- `https://site2.test/info.php` → **PHP 7.4.33** ✅
- `https://dev.test/info.php` → PHP 8.4.26 ✅
- `https://localhost/info.php` → PHP 8.4.26 ✅

**Result:** ✅ Pass.

---

### Step 8.12 — Static HTML regression test

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

**Result:** ✅ Pass — static HTML serving unaffected.

---

## Phase 8 Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | `php-fpm74/` + `logs/php-fpm74/` created | ✅ |
| 2 | Base image `php-base:7.4` verified | ✅ |
| 3 | `dev-env-php-fpm74` image built | ✅ |
| 4 | `site2.conf` updated (surgical, one line per block) | ✅ |
| 5 | `docker-compose.yml` extended with `php-fpm74` | ✅ |
| 6 | All 4 containers running | ✅ |
| 7 | FPM 7.4 logs clean | ✅ |
| 8 | HTTP: site2 → PHP 7.4.33, site1/dev → 8.4.26 | ✅ |
| 9 | HTTPS: same routing | ✅ |
| 10 | `imap` loads on site2 (7.4-only) | ✅ |
| 11 | Browser test | ✅ |
| 12 | Static HTML still works (no regression) | ✅ |

**Phase 8 result: ✅ PASS**

---

## Carry-Forward Notes for Future Phases

1. **Multi-PHP pattern is proven.** To assign any site to any PHP version:
   - Ensure its vhost's `fastcgi_pass` points at the correct FPM container.
   - Rebuild nginx if vhost configs changed: `docker compose build nginx`
   - Restart nginx: `docker compose up -d nginx`

2. **One-line sed pattern to flip a site's PHP version:**
   ```bash
   sed -i '' 's/fastcgi_pass php-fpm84:9000;/fastcgi_pass php-fpm74:9000;/g' ~/Projects01/docker-env/nginx/sites/<site>.conf
   ```
   Then rebuild + restart nginx.

3. **Current routing table (this is the source of truth):**

   | Site | PHP | FPM container | Nginx vhost |
   |---|---|---|---|
   | `site1.test` | 8.4.26 | `php-fpm84:9000` | `site1.conf` |
   | `site2.test` | 7.4.33 | `php-fpm74:9000` | `site2.conf` |
   | `dev.test` / `localhost` / `127.0.0.1` | 8.4.26 | `php-fpm84:9000` | `default.conf` |

4. **Both FPM containers mount all sites.** This means you can reassign a site to the other PHP version by editing **only** its Nginx vhost — the FPM container already has the code available.

5. **`imap` extension is 7.4-only.** Any project that uses `imap_open()` belongs on `php-fpm74`.

6. **Independent error logs per PHP version:**
   - 8.4: `~/Projects01/docker-env/logs/php-fpm84/error.log`
   - 7.4: `~/Projects01/docker-env/logs/php-fpm74/error.log`

7. **Both FPM containers run on port 9000 internally.** No host conflict because Docker isolates each container's network namespace. Nginx reaches them by DNS service name (`php-fpm84` or `php-fpm74`) on `dev-env_dev-net`.

8. **Container names for debugging:**
   - `dev-nginx`
   - `dev-apache2`
   - `dev-php-fpm84`
   - `dev-php-fpm74`

9. **Docker layer caching for nginx:** only `COPY sites/` re-runs when vhost configs change. Rebuild takes ~1 second.

10. **Extension sets differ slightly between versions:**
    - 8.4 image: 62 extensions (no `imap`, no `xmlrpc`)
    - 7.4 image: 62 extensions + `imap` (no `xmlrpc`)
    
    Both have: bcmath, bz2, curl, dba, dom, exif, fileinfo, ftp, gd, gettext, gmp, iconv, igbinary, imagick, intl, ldap, mbstring, memcached, mongodb, mysqli, opcache, pcntl, pdo, pdo_mysql, pdo_pgsql, pgsql, phar, posix, readline, redis, shmop, simplexml, soap, sockets, sodium, sqlite3, sysvmsg, sysvsem, sysvshm, tokenizer, xml, xmlreader, xmlwriter, xsl, zip, zlib, plus PECL (apcu, amqp, xdebug, zmq).

---

## Files and System Changes in Phase 8

**Files created:**

```
~/Projects01/docker-env/php-fpm74/Dockerfile
~/Projects01/docker-env/logs/php-fpm74/         (folder)
```

**Files modified:**

```
~/Projects01/docker-env/nginx/sites/site2.conf   (fastcgi_pass target changed)
~/Projects01/docker-env/docker-compose.yml       (php-fpm74 service added; nginx depends_on updated)
```

**Docker objects created:**

- Image: `dev-env-php-fpm74:latest`
- Container: `dev-php-fpm74`

**Docker objects rebuilt:**

- Image: `dev-env-nginx:latest`
- Container: `dev-nginx`

---

## Rollback Procedure

To undo Phase 8 and revert site2 back to PHP 8.4:

```bash
sed -i '' 's/fastcgi_pass php-fpm74:9000;/fastcgi_pass php-fpm84:9000;/g' ~/Projects01/docker-env/nginx/sites/site2.conf
cd ~/Projects01/docker-env
docker compose build nginx
docker compose up -d nginx
```

To fully remove the php-fpm74 service:

```bash
docker compose down php-fpm74
docker rmi dev-env-php-fpm74:latest
# Then remove php-fpm74 from docker-compose.yml and remove the php-fpm74 depends_on from nginx
```

No data loss — no databases involved.

---
## Summary of Phase 8:

- ✅ 12/12 checks passed
- ✅ dev-env-php-fpm74 image built (thin layer over php-base:7.4)
- ✅ site2.conf updated — one line per block (fastcgi_pass php-fpm74:9000)
- ✅ All 4 containers running simultaneously
- ✅ HTTP & HTTPS: site2 serves PHP 7.4.33, site1/dev serve 8.4.26
- ✅ imap extension loads on site2 (proof it's genuinely 7.4)
- ✅ No regressions to static HTML or other sites
- ✅ Multi-PHP routing pattern proven and documented

**The environment's core multi-PHP capability is now working**. Phase 9 extends this to Apache.
---

## Next Phase

**Phase 9 — PHP through Apache (secondary web server)**

Goal: make Apache serve PHP too, so both web servers can host PHP sites on different ports.

Phase 9 will:
- Update Apache vhosts to use `mod_proxy_fcgi` (`ProxyPassMatch ^/(.*\.php)$ fcgi://php-fpm84:9000/var/www/html/...`)
- Point each Apache vhost at the same FPM container its Nginx counterpart uses:
  - `site1.conf` → `php-fpm84`
  - `site2.conf` → `php-fpm74`
  - `default.conf` → `php-fpm84`
- Verify `http://site1.test:8080/info.php` → PHP 8.4.26
- Verify `http://site2.test:8080/info.php` → PHP 7.4.33
- Verify Nginx paths still work (no regression)

**Note:** `proxy_fcgi` and `proxy_module` are already enabled in the Apache Dockerfile from Phase 4. No image change required.

Phase 9 begins once this document is saved and confirmed.

---

**End of phase_08.md**