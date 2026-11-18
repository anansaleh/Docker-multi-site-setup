# Phase 09 — PHP through Apache (Secondary Web Server)

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM)
**Host project root:** `~/Projects01/`
**Date completed:** 2026-10-15
**Status:** ✅ Complete — all checks passed

---

## Objective

Make Apache serve PHP on ports 8080/8443, using the same PHP-FPM containers that Nginx uses. After this phase:

- Both web servers independently serve PHP.
- PHP version routing is preserved across both web servers:
  - `site1.test` → PHP 8.4 (both Nginx and Apache)
  - `site2.test` → PHP 7.4 (both Nginx and Apache)
  - `dev.test` / `localhost` → PHP 8.4 (both Nginx and Apache)
- Nginx on 80/443 remains unaffected.

---

## Final Routing Matrix

| Site | Nginx (80/443) | Apache (8080/8443) | PHP version |
|---|---|---|---|
| `site1.test` | ✅ | ✅ | 8.4.26 |
| `site2.test` | ✅ | ✅ | 7.4.33 |
| `dev.test` / `localhost` / `127.0.0.1` | ✅ | ✅ | 8.4.26 |

---

## What We Built

```
~/Projects01/docker-env/
├── apache2/
│   └── sites/
│       ├── default.conf    (updated — SetHandler for php-fpm84)
│       ├── site1.conf      (updated — SetHandler for php-fpm84)
│       └── site2.conf      (updated — SetHandler for php-fpm74)
└── docker-compose.yml      (unchanged — Apache already on dev-net)
```

**No new container or image.** Apache's Dockerfile already enabled `mod_proxy_fcgi` in Phase 4.

---

## How Apache ↔ FPM Works (Different from Nginx)

| Aspect | Nginx | Apache |
|---|---|---|
| Directive | `fastcgi_pass` | `SetHandler "proxy:fcgi://..."` |
| Module | `ngx_http_fastcgi_module` | `mod_proxy_fcgi` |
| Path handling | Manual (`SCRIPT_FILENAME` param) | Automatic (resolves `DocumentRoot` + URI) |
| Location | Inside `location ~ \.php$` | Inside `<FilesMatch \.php$>` |

**Nginx** requires explicit `SCRIPT_FILENAME $document_root$fastcgi_script_name`.

**Apache** resolves the file path itself and passes it as `SCRIPT_FILENAME` to FPM. Both must agree on the path — which they do because both containers mount sites at identical paths.

---

## The Apache PHP Handler (the critical addition)

Added to **every** `<VirtualHost>` block (HTTP and HTTPS) in every vhost file:

```apache
<FilesMatch \.php$>
    SetHandler "proxy:fcgi://php-fpm84:9000"
</FilesMatch>
```

For `site2.conf`, replace `php-fpm84` with `php-fpm74`:

```apache
<FilesMatch \.php$>
    SetHandler "proxy:fcgi://php-fpm74:9000"
</FilesMatch>
```

**Line by line:**
- `<FilesMatch \.php$>` — matches files whose name ends in `.php` (case-sensitive by default).
- `SetHandler` — assigns a request handler for those files.
- `"proxy:fcgi://php-fpm84:9000"` — tells `mod_proxy_fcgi` to forward the request to the named FPM container on port 9000.

**Why this works without hardcoded paths:** Apache internally resolves the file path using `DocumentRoot` + the requested URI, and passes the resolved path to FPM as `SCRIPT_FILENAME`. Since the path inside Apache's container and the path inside FPM's container are the same (`/var/www/html/siteN/…`), FPM finds the file.

---

## Files Modified in Phase 9

### `apache2/sites/default.conf`

Full file (PHP handler added to both HTTP and HTTPS blocks):

```apache
# Default catch-all vhost for Apache
# Matches: dev.test, localhost, 127.0.0.1 on ports 8080/8443
# PHP: routes to php-fpm84 (PHP 8.4)

<VirtualHost *:8080>
    ServerName dev.test
    ServerAlias localhost 127.0.0.1
    DocumentRoot /var/www/html/v3client

    <Directory /var/www/html/v3client>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:fcgi://php-fpm84:9000"
    </FilesMatch>

    ErrorLog  /var/log/apache2/default.error.log
    CustomLog /var/log/apache2/default.access.log combined
</VirtualHost>

<VirtualHost *:8443>
    ServerName dev.test
    ServerAlias localhost 127.0.0.1
    DocumentRoot /var/www/html/v3client

    SSLEngine on
    SSLCertificateFile    /etc/apache2/certs/dev.test.crt
    SSLCertificateKeyFile /etc/apache2/certs/dev.test.key
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLCipherSuite HIGH:!aNULL:!MD5

    <Directory /var/www/html/v3client>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:fcgi://php-fpm84:9000"
    </FilesMatch>

    ErrorLog  /var/log/apache2/default.error.log
    CustomLog /var/log/apache2/default.access.log combined
</VirtualHost>
```

### `apache2/sites/site1.conf`

Same pattern, `ServerName site1.test`, `DocumentRoot /var/www/html/site1`, `SetHandler "proxy:fcgi://php-fpm84:9000"` in both blocks.

### `apache2/sites/site2.conf`

Same pattern, `ServerName site2.test`, `DocumentRoot /var/www/html/site2`, **`SetHandler "proxy:fcgi://php-fpm74:9000"`** in both blocks (PHP 7.4).

---

## Step-by-Step Record

### Step 9.1 — Confirm `mod_proxy_fcgi` is available

```bash
docker compose exec apache2 httpd -M | grep -E "proxy|fcgi"
```

**Output:**
```
 proxy_module (shared)
 proxy_fcgi_module (shared)
```

**Result:** ✅ Both modules loaded. No Apache image change needed — the Dockerfile enabled them during Phase 4.

---

### Step 9.2 — `apache2/sites/default.conf` rewritten

Added `<FilesMatch>` blocks to both HTTP and HTTPS vhosts. Target: `php-fpm84:9000`.

**Result:** ✅ Pass.

---

### Step 9.3 — `apache2/sites/site1.conf` rewritten

Same pattern. Target: `php-fpm84:9000`.

**Result:** ✅ Pass.

---

### Step 9.4 — `apache2/sites/site2.conf` rewritten

Target: **`php-fpm74:9000`** (PHP 7.4).

**Verification (grep):**

| File | `php-fpm84:9000` | `php-fpm74:9000` |
|---|---|---|
| `default.conf` | 2 | 0 |
| `site1.conf` | 2 | 0 |
| `site2.conf` | **0** | **2** |

**Result:** ✅ Routing is exactly as designed, symmetrical with Nginx.

---

### Step 9.5 — Rebuild Apache image and restart

```bash
docker compose build apache2     # 1.2s (only COPY sites/ re-ran)
docker compose up -d apache2
docker compose ps
```

**Output:**
```
NAME            IMAGE               COMMAND                  STATUS              PORTS
dev-apache2     dev-env-apache2     "httpd-foreground"       Up < 1s             8080, 8443
dev-nginx       dev-env-nginx       "/docker-entrypoint.…"   Up 55 minutes       80, 443
dev-php-fpm74   dev-env-php-fpm74   "docker-php-entrypoi…"   Up 55 minutes       9000/tcp
dev-php-fpm84   dev-env-php-fpm84   "docker-php-entrypoi…"   Up 2 hours          9000/tcp
```

**Result:** ✅ Apache restarted, other containers untouched.

---

### Step 9.6 — HTTP PHP via Apache — the moment of truth 🎉

**Commands:**
```bash
curl -sS http://site1.test:8080/info.php
curl -sS http://site2.test:8080/info.php
curl -sS http://dev.test:8080/info.php
```

**Output:**
```
<h1>site1.test — PHP via FPM</h1>
<p>PHP version: 8.4.26</p>
<p>Server software: Apache</p>
<p>Served from: /var/www/html/site1/info.php</p>

<h1>site2.test — PHP via FPM</h1>
<p>PHP version: 7.4.33</p>
<p>Server software: Apache</p>
<p>Served from: /var/www/html/site2/info.php</p>

<h1>Default site (dev.test / localhost) — PHP via FPM</h1>
<p>PHP version: 8.4.26</p>
<p>Server software: Apache</p>
<p>Served from: /var/www/html/v3client/info.php</p>
```

**Result:** ✅ **Pass — on the first try, no errors.**

- **Server software: `Apache`** — confirms the request went through Apache (not Nginx).
- **PHP versions preserved** — site1/dev show 8.4.26, site2 shows 7.4.33.
- **Container paths correct** — `/var/www/html/siteN/…`.

**The complete dual-server + dual-PHP matrix is now working.**

---

### Step 9.7 — HTTPS PHP via Apache

**Commands:**
```bash
curl -sS https://site1.test:8443/info.php
curl -sS https://site2.test:8443/info.php
curl -sS https://dev.test:8443/info.php
```

**Output:** Identical to Step 9.6, over HTTPS. No `-k` needed.

**Result:** ✅ Pass.

---

### Step 9.8 — Nginx regression test

**Commands:**
```bash
curl -sS http://site1.test/info.php
curl -sS http://site2.test/info.php
curl -sS http://dev.test/info.php
curl -sS https://site1.test/info.php
curl -sS https://site2.test/info.php
```

**Output:** All show **`Server software: nginx/1.27.5`** and correct PHP versions.

**Result:** ✅ Pass — no interference. Apache changes had zero impact on Nginx.

---

### Step 9.9 — Browser test

All six URLs verified in browser:

**Nginx (default ports):**
- `https://site1.test/info.php` → PHP 8.4.26, server: nginx ✅
- `https://site2.test/info.php` → PHP 7.4.33, server: nginx ✅
- `https://dev.test/info.php` → PHP 8.4.26, server: nginx ✅

**Apache (secondary ports):**
- `https://site1.test:8443/info.php` → PHP 8.4.26, server: Apache ✅
- `https://site2.test:8443/info.php` → PHP 7.4.33, server: Apache ✅
- `https://dev.test:8443/info.php` → PHP 8.4.26, server: Apache ✅

**Result:** ✅ Pass.

---

### Step 9.10 — Static HTML regression on both web servers

**Commands:**
```bash
echo "=== Nginx (default ports) ==="
curl -sS http://site1.test
curl -sS http://site2.test
curl -sS http://dev.test
echo
echo "=== Apache (8080) ==="
curl -sS http://site1.test:8080
curl -sS http://site2.test:8080
curl -sS http://dev.test:8080
```

**Output:**
```
=== Nginx (default ports) ===
<h1>Site 1 — static placeholder</h1>
<h1>Site 2 — static placeholder</h1>
<h1>Default — v3client placeholder</h1>

=== Apache (8080) ===
<h1>Site 1 — static placeholder</h1>
<h1>Site 2 — static placeholder</h1>
<h1>Default — v3client placeholder</h1>
```

**Result:** ✅ Pass — static HTML works on both web servers, no regression.

---

## Phase 9 Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | `mod_proxy_fcgi` available in Apache | ✅ |
| 2 | `default.conf` updated with SetHandler | ✅ |
| 3 | `site1.conf` updated with SetHandler (php-fpm84) | ✅ |
| 4 | `site2.conf` updated with SetHandler (php-fpm74) | ✅ |
| 5 | Apache image rebuilt | ✅ |
| 6 | Apache restarted cleanly | ✅ |
| 7 | HTTP PHP works via Apache (all 3 sites, correct versions) | ✅ |
| 8 | HTTPS PHP works via Apache | ✅ |
| 9 | Nginx still works (no regression) | ✅ |
| 10 | Browser test passes | ✅ |
| 11 | Static HTML works on both servers | ✅ |

**Phase 9 result: ✅ PASS**

---

## Carry-Forward Notes for Future Phases

1. **Apache PHP handler pattern** (reuse for future sites):
   ```apache
   <FilesMatch \.php$>
       SetHandler "proxy:fcgi://php-fpm84:9000"
   </FilesMatch>
   ```
   For a 7.4 site, change `php-fpm84` to `php-fpm74`.

2. **Key differences from Nginx:**
   - Nginx uses `fastcgi_pass` inside `location ~ \.php$`.
   - Apache uses `SetHandler` inside `<FilesMatch \.php$>`.
   - Apache resolves path automatically; Nginx needs explicit `SCRIPT_FILENAME`.

3. **To flip a site's PHP version in Apache:**
   ```bash
   sed -i '' 's/php-fpm84:9000/php-fpm74:9000/g' ~/Projects01/docker-env/apache2/sites/<site>.conf
   cd ~/Projects01/docker-env
   docker compose build apache2
   docker compose up -d apache2
   ```

4. **Keep Nginx and Apache PHP assignments in sync.** When reassigning a site's PHP version, update **both** web servers for consistency.

5. **Adding a new site requires configs in both web servers:**
   - `nginx/sites/<site>.conf`
   - `apache2/sites/<site>.conf`
   - Rebuild both `nginx` and `apache2`.

6. **Error logs per web server, per site:**
   - Nginx: `~/Projects01/docker-env/logs/nginx/<site>.error.log`
   - Apache: `~/Projects01/docker-env/logs/apache2/<site>.error.log`

7. **Apache `<FilesMatch \.php$>` is case-sensitive.** Standard PHP files use lowercase `.php`. If case-insensitivity is needed:
   ```apache
   <FilesMatch "(?i)\.php$">
   ```

8. **Chrome trust on `:8443`:** Chrome treats `site.test:8443` as a separate origin from `site.test:443`. A one-time "Proceed anyway" per hostname:8443 combination may be needed.

9. **Container names and ports (final state after Phase 9):**

   | Container | Host ports | Internal ports |
   |---|---|---|
   | `dev-nginx` | 80, 443 | 80, 443 |
   | `dev-apache2` | 8080, 8443 | 8080, 8443 |
   | `dev-php-fpm84` | — | 9000 |
   | `dev-php-fpm74` | — | 9000 |

10. **Only the web servers have host ports.** FPM containers are internal-only, reachable only via the Docker network `dev-env_dev-net` by their service names (`php-fpm84`, `php-fpm74`).

11. **`mod_proxy_fcgi` and `mod_proxy` were enabled during Phase 4** in the Apache Dockerfile. No re-enablement was needed for this phase — the modules were already loaded.

12. **Path consistency is the silent requirement.** Apache resolves `DocumentRoot` + URI to a filesystem path, then passes that path to FPM. Since both containers mount sites at identical paths, FPM finds the file. If they ever diverged, Apache would return `500` with "Primary script unknown" in the error log.

---

## Files and System Changes in Phase 9

**Files modified:**

```
~/Projects01/docker-env/apache2/sites/default.conf     (SetHandler for php-fpm84)
~/Projects01/docker-env/apache2/sites/site1.conf       (SetHandler for php-fpm84)
~/Projects01/docker-env/apache2/sites/site2.conf       (SetHandler for php-fpm74)
```

**Docker objects rebuilt:**

- Image: `dev-env-apache2:latest`
- Container: `dev-apache2` (restarted)

**No new images, containers, or networks created.**

---

## Rollback Procedure

To revert Apache to static-only (undo PHP through Apache):

Option A — remove the `<FilesMatch>` blocks from all three vhost files, then:
```bash
cd ~/Projects01/docker-env
docker compose build apache2
docker compose up -d apache2
```

Option B — do nothing. If the configs fail to load, Apache won't start, and the impact is contained to the Apache container. Nginx keeps serving PHP unchanged.

No data loss — no databases involved.

---

## Summary of Phase 9:

- ✅ 11/11 checks passed
- ✅ Apache now serves PHP on ports 8080/8443
- ✅ PHP version routing preserved across both web servers (site1/dev → 8.4, site2 → 7.4)
- ✅ Nginx unaffected (no regression)
- ✅ Static HTML still works on both web servers
- ✅ Full dual-server + dual-PHP matrix is now operational

**Both web servers and both PHP versions are working together**. The infrastructure foundation is complete.


---

## Next Phase

**Phase 10 — MySQL 8.0 (First Database)**

Goal: stand up MySQL with persistent data in `data_files/mysql80/`, reachable from PHP. This phase establishes the DB-service pattern that MariaDB, PostgreSQL, MongoDB, and Redis will follow.

Phase 10 will:
- Create `docker-env/mysql/Dockerfile` and `my.cnf`
- Extend `docker-compose.yml` with the `mysql` service
- Add `data_files/mysql80/` mount for data persistence
- Add MySQL env vars to `.env`
- Verify connectivity from workspace/php-fpm containers
- Verify data persists across container restarts

Phase 10 begins once this document is saved and confirmed.

---

**End of phase_09.md**