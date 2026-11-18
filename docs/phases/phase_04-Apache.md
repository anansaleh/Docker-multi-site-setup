# Phase 04 — Apache Only (No PHP), on Secondary Ports

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM)
**Host project root:** `~/Projects01/`
**Date completed:** 2026-10-13
**Status:** ✅ Complete — all checks passed

---

## Objective

Stand up **Apache** as the **secondary** web server on ports **8080** (HTTP) and **8443** (HTTPS), serving the same static placeholders as Nginx. Both web servers run **concurrently**.

**No PHP** in this phase. Same static content as Phase 3, different ports and different web server.

---

## Architecture After Phase 4

| URL pattern | Web server | Host port | Container port |
|---|---|---|---|
| `http://*.test` | Nginx | 80 | 80 |
| `https://*.test` | Nginx | 443 | 443 |
| `http://*.test:8080` | Apache | 8080 | 8080 |
| `https://*.test:8443` | Apache | 8443 | 8443 |

Both servers:
- Serve the same site roots (`sites/site1`, `sites/site2`, `default-host/v3client`).
- Use the same SSL cert (`dev.test.crt`).
- Log to separate host folders (`logs/nginx/` vs `logs/apache2/`).
- Run in the same Docker network (`dev-env_dev-net`).

---

## Files Created in Phase 4

```
~/Projects01/docker-env/
├── apache2/
│   ├── Dockerfile
│   ├── vhost.conf              (replaces Apache's default httpd.conf)
│   └── sites/
│       ├── default.conf
│       ├── site1.conf
│       └── site2.conf
└── logs/
    └── apache2/                (Apache logs land here on host)
```

**Files modified:**

```
~/Projects01/docker-env/.env                 (Apache port variables added)
~/Projects01/docker-env/docker-compose.yml   (apache2 service added)
```

---

## Step-by-Step Record

### Step 4.1 — Port check

**Commands:**

```bash
sudo lsof -iTCP:8080 -sTCP:LISTEN
sudo lsof -iTCP:8443 -sTCP:LISTEN
```

**Output:** no output for either.

**Result:** ✅ Both ports free.

---

### Step 4.2 — Folder structure

**Commands:**

```bash
mkdir -p ~/Projects01/docker-env/apache2/sites
mkdir -p ~/Projects01/docker-env/logs/apache2
ls -la ~/Projects01/docker-env/apache2/
ls -la ~/Projects01/docker-env/logs/
```

**Output:**

- `apache2/` created with `sites/` subfolder.
- `logs/apache2/` created alongside existing `logs/nginx/`.

**Result:** ✅ Pass.

---

### Step 4.3 — Extend `.env`

**Command:**

```bash
cat >> ~/Projects01/docker-env/.env <<'EOF'

# --- Apache (secondary web server) ---
APACHE_HTTP_PORT=8080
APACHE_HTTPS_PORT=8443
EOF
```

**Resulting `.env`:**

```
# ============================================================
# docker-env — environment configuration
# Phase 3 — Nginx only
# ============================================================

COMPOSE_PROJECT_NAME=dev-env

# Host project root (absolute path on your Mac)
PROJECTS_ROOT=/Users/anansaleh/Projects01

# Timezone
TZ=Europe/London

# --- Web server ports ---
NGINX_HTTP_PORT=80
NGINX_HTTPS_PORT=443

# --- Apache (secondary web server) ---
APACHE_HTTP_PORT=8080
APACHE_HTTPS_PORT=8443
```

**Note:** The header comment still says "Phase 3 — Nginx only". Cosmetic. Will be fixed in Phase 20 (`.env` consolidation).

**Result:** ✅ Pass.

---

### Step 4.4 — Apache `Dockerfile`

**File created:** `~/Projects01/docker-env/apache2/Dockerfile`

```dockerfile
FROM httpd:2.4-alpine

# Enable required modules
RUN sed -i \
    -e 's/^#\(LoadModule ssl_module\)/\1/' \
    -e 's/^#\(LoadModule socache_shmcb_module\)/\1/' \
    -e 's/^#\(LoadModule rewrite_module\)/\1/' \
    -e 's/^#\(LoadModule proxy_module\)/\1/' \
    -e 's/^#\(LoadModule proxy_fcgi_module\)/\1/' \
    -e 's/^#\(LoadModule headers_module\)/\1/' \
    /usr/local/apache2/conf/httpd.conf

# Replace default httpd.conf with our own base config
COPY vhost.conf /usr/local/apache2/conf/httpd.conf

# Copy vhost configs
COPY sites/ /usr/local/apache2/conf/sites/

EXPOSE 8080 8443

CMD ["httpd-foreground"]
```

**Design notes:**

- Base image: `httpd:2.4-alpine` (official Apache image, ~60 MB).
- Modules enabled: SSL, socache_shmcb, rewrite, proxy, proxy_fcgi, headers.
- **The `sed` step is redundant.** Our `COPY vhost.conf` overwrites `/usr/local/apache2/conf/httpd.conf` entirely, discarding any sed changes. Our `vhost.conf` explicitly loads every module by name — that's what actually loads them.
- Cleanup opportunity for Phase 20: remove the `sed` block.

**Result:** ✅ Created.

---

### Step 4.5 — Global `vhost.conf` (Apache's main config)

**File created:** `~/Projects01/docker-env/apache2/vhost.conf`

```apache
# ============================================================
# Apache global configuration (httpd.conf)
# Docker-based multi-site dev environment
# ============================================================

ServerRoot "/usr/local/apache2"

Listen 8080
Listen 8443

LoadModule mpm_event_module        modules/mod_mpm_event.so
LoadModule authn_core_module       modules/mod_authn_core.so
LoadModule authz_core_module       modules/mod_authz_core.so
LoadModule authz_host_module       modules/mod_authz_host.so
LoadModule unixd_module            modules/mod_unixd.so
LoadModule dir_module              modules/mod_dir.so
LoadModule mime_module             modules/mod_mime.so
LoadModule log_config_module       modules/mod_log_config.so
LoadModule env_module              modules/mod_env.so
LoadModule headers_module          modules/mod_headers.so
LoadModule rewrite_module          modules/mod_rewrite.so
LoadModule alias_module            modules/mod_alias.so
LoadModule ssl_module              modules/mod_ssl.so
LoadModule socache_shmcb_module    modules/mod_socache_shmcb.so
LoadModule proxy_module            modules/mod_proxy.so
LoadModule proxy_fcgi_module       modules/mod_proxy_fcgi.so

User  www-data
Group www-data

ServerName localhost

# --- Logging ---
LogFormat "%h %l %u %t \"%r\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\"" combined

# --- Default document behavior ---
<Directory />
    AllowOverride None
    Require all denied
</Directory>

<Directory "/var/www/html">
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>

# --- MIME types ---
TypesConfig conf/mime.types
AddType application/x-compress .Z
AddType application/x-gzip .gz .tgz

# --- Index files ---
<IfModule dir_module>
    DirectoryIndex index.html index.php
</IfModule>

# --- Hide server version ---
ServerTokens Prod
ServerSignature Off

# --- Load vhost configs ---
Include conf/sites/*.conf
```

**Key decisions:**

- `Listen 8080` / `Listen 8443` — Apache listens on 8080/8443 **inside** the container, matching host ports 1:1. Port mapping is trivial (`8080:8080`, `8443:8443`).
- `mpm_event_module` — modern event-driven MPM.
- `ServerTokens Prod` + `ServerSignature Off` — hides Apache version from headers and error pages.
- `AllowOverride All` under `/var/www/html` — enables `.htaccess` in site code.
- `Include conf/sites/*.conf` — loads our three vhost files.

**Result:** ✅ Created.

---

### Step 4.6 — Three Apache vhost configs

**Files created:**

- `~/Projects01/docker-env/apache2/sites/default.conf` (1099 bytes)
- `~/Projects01/docker-env/apache2/sites/site1.conf` (958 bytes)
- `~/Projects01/docker-env/apache2/sites/site2.conf` (958 bytes)

Each file contains two `<VirtualHost>` blocks (HTTP on 8080, HTTPS on 8443).

**Example — `default.conf`:**

```apache
# Default catch-all vhost for Apache
# Matches: dev.test, localhost, 127.0.0.1 on ports 8080/8443

<VirtualHost *:8080>
    ServerName dev.test
    ServerAlias localhost 127.0.0.1
    DocumentRoot /var/www/html/v3client

    <Directory /var/www/html/v3client>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

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

    ErrorLog  /var/log/apache2/default.error.log
    CustomLog /var/log/apache2/default.access.log combined
</VirtualHost>
```

**`site1.conf` and `site2.conf`** follow the same pattern with `ServerName site1.test` / `site2.test` and `DocumentRoot /var/www/html/site1` / `site2`.

**Key detail:** Apache expects the SSL certs at `/etc/apache2/certs/`, whereas Nginx uses `/etc/nginx/certs/`. Both paths are bind-mounted from the same host folder (`~/Projects01/docker-env/ssl_files/`) — but at different container paths. This keeps each web server's config looking native.

**Result:** ✅ All three created.

---

### Step 4.7 — Extend `docker-compose.yml`

**File rewritten:** `~/Projects01/docker-env/docker-compose.yml`

Added the `apache2` service alongside the existing `nginx` service.

**Full new compose file:**

```yaml
services:
  nginx:
    build:
      context: ./nginx
    container_name: dev-nginx
    restart: unless-stopped
    ports:
      - "${NGINX_HTTP_PORT}:80"
      - "${NGINX_HTTPS_PORT}:443"
    volumes:
      - ${PROJECTS_ROOT}/sites/site1:/var/www/html/site1
      - ${PROJECTS_ROOT}/sites/site2:/var/www/html/site2
      - ${PROJECTS_ROOT}/default-host/v3client:/var/www/html/v3client
      - ${PROJECTS_ROOT}/default-host/client_data:/home/client_data
      - ${PROJECTS_ROOT}/default-host/server_side:/home/server_side
      - ${PROJECTS_ROOT}/docker-env/ssl_files:/etc/nginx/certs:ro
      - ${PROJECTS_ROOT}/docker-env/logs/nginx:/var/log/nginx
    environment:
      - TZ=${TZ}
    networks:
      - dev-net

  apache2:
    build:
      context: ./apache2
    container_name: dev-apache2
    restart: unless-stopped
    ports:
      - "${APACHE_HTTP_PORT}:8080"
      - "${APACHE_HTTPS_PORT}:8443"
    volumes:
      - ${PROJECTS_ROOT}/sites/site1:/var/www/html/site1
      - ${PROJECTS_ROOT}/sites/site2:/var/www/html/site2
      - ${PROJECTS_ROOT}/default-host/v3client:/var/www/html/v3client
      - ${PROJECTS_ROOT}/default-host/client_data:/home/client_data
      - ${PROJECTS_ROOT}/default-host/server_side:/home/server_side
      - ${PROJECTS_ROOT}/docker-env/ssl_files:/etc/apache2/certs:ro
      - ${PROJECTS_ROOT}/docker-env/logs/apache2:/var/log/apache2
    environment:
      - TZ=${TZ}
    networks:
      - dev-net

networks:
  dev-net:
    driver: bridge
```

**Validated with:**

```bash
docker compose config
```

All variables substituted, both services defined, no errors.

**Result:** ✅ Pass.

---

### Step 4.8 — Build Apache image

**Command:**

```bash
docker compose build apache2
```

**Result:** ✅ Build succeeded in ~4.4s. Image tagged `dev-env-apache2:latest`.

Build steps:
- `[1/4] FROM httpd:2.4-alpine` — pulled (~24 MB of layers)
- `[2/4] RUN sed ...` — succeeded silently
- `[3/4] COPY vhost.conf /usr/local/apache2/conf/httpd.conf`
- `[4/4] COPY sites/ /usr/local/apache2/conf/sites/`

---

### Step 4.9 — Start both web servers

**Commands:**

```bash
docker compose up -d
docker compose ps
docker compose logs apache2
docker compose exec apache2 httpd -v
docker compose exec apache2 ps aux | grep httpd
```

**Output summary:**

- nginx: Up (already running, unchanged)
- apache2: Up (just started), ports 8080 and 8443 mapped
- `httpd -v`: `Apache/2.4.69 (Unix)`
- Process list: 1 root master (`httpd -DFOREGROUND`), 3 `www-data` workers
- `docker compose logs apache2`: empty

**About the empty logs:** The official `httpd` image logs to files by default, not to stdout. Our vhost config directs `ErrorLog` and `CustomLog` to `/var/log/apache2/...` (host-mounted). So Apache logs appear on the host, not via `docker compose logs`. Intentional — matches our design.

**Result:** ✅ Pass.

---

### Step 4.10 — HTTP tests (port 8080)

**Commands:**

```bash
curl -sS http://site1.test:8080
curl -sS http://site2.test:8080
curl -sS http://dev.test:8080
curl -sS http://localhost:8080
```

**Output:**

```
<h1>Site 1 — static placeholder</h1>
<h1>Site 2 — static placeholder</h1>
<h1>Default — v3client placeholder</h1>
<h1>Default — v3client placeholder</h1>
```

**Result:** ✅ Pass.

---

### Step 4.11 — HTTPS tests (port 8443)

**Commands:**

```bash
curl -sS https://site1.test:8443
curl -sS https://site2.test:8443
curl -sS https://dev.test:8443
curl -sS https://localhost:8443
```

**Output:** Same four bodies as Step 4.10.

**Note:** No `-k` flag needed. The `dev.test.crt` (wildcard + explicit SANs) is trusted by Apple SecureTransport. `.test` TLD choice continues to work.

**Result:** ✅ Pass.

---

### Step 4.12 — Nginx regression test

**Commands:**

```bash
curl -sS http://site1.test
curl -sS http://site2.test
curl -sS http://dev.test
curl -sS https://site1.test
curl -sS https://site2.test
curl -sS https://dev.test
```

**Output:** All six returned the correct placeholder.

**Result:** ✅ Pass — no regression. Nginx (80/443) and Apache (8080/8443) coexist without interference.

---

### Step 4.13 — Apache logs on host

**Commands:**

```bash
ls -la ~/Projects01/docker-env/logs/apache2/
cat ~/Projects01/docker-env/logs/apache2/site1.access.log
```

**Output (files):**

```
default.access.log   348 bytes
default.error.log      0 bytes
site1.access.log     174 bytes
site1.error.log        0 bytes
site2.access.log     174 bytes
site2.error.log        0 bytes
```

**Output (site1.access.log):**

```
192.168.65.1 - - [13/Oct/2026:09:17:01 +0000] "GET / HTTP/1.1" 200 39 "-" "curl/8.7.1"
192.168.65.1 - - [13/Oct/2026:09:18:00 +0000] "GET / HTTP/1.1" 200 39 "-" "curl/8.7.1"
```

**Result:** ✅ Pass.

**Notable observation — timezone difference:**

- Apache log timestamps: `+0000` (UTC)
- Nginx log timestamps: `+0100` (BST)

Apache on Alpine does not honor the container's `TZ` environment variable for log timestamps without `tzdata` installed and `/etc/localtime` set. **Cosmetic only** — the times are correct, just expressed in UTC rather than local time.

**Fix (if desired later):** add `RUN apk add --no-cache tzdata` to the Apache Dockerfile. Not urgent; noted here for reference.

**Client IP:** `192.168.65.1` — Docker Desktop VM gateway. Normal on macOS.

---

### Step 4.14 — Browser test

Manually opened in browser:

| URL | Result |
|---|---|
| `http://site1.test:8080` | ✅ Site 1 placeholder |
| `http://site2.test:8080` | ✅ Site 2 placeholder |
| `http://dev.test:8080` | ✅ Default placeholder |
| `https://site1.test:8443` | ✅ Site 1, padlock |
| `https://site2.test:8443` | ✅ Site 2, padlock |
| `https://dev.test:8443` | ✅ Default, padlock |

**Result:** ✅ All 6 URLs working perfectly in the browser. HTTPS padlocks shown (no cert warnings after initial per-host:port trust).

---

## Phase 4 Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | Ports 8080/8443 free before start | ✅ |
| 2 | `apache2/` and `logs/apache2/` created | ✅ |
| 3 | `.env` extended with Apache ports | ✅ |
| 4 | Apache `Dockerfile` created | ✅ |
| 5 | Global `vhost.conf` created | ✅ |
| 6 | Three Apache vhost configs created | ✅ |
| 7 | `docker-compose.yml` extended with `apache2` service | ✅ |
| 8 | `docker compose config` validates | ✅ |
| 9 | Apache image builds cleanly | ✅ |
| 10 | Both containers running simultaneously | ✅ |
| 11 | HTTP works on 8080 for all hostnames | ✅ |
| 12 | HTTPS works on 8443 for all hostnames | ✅ |
| 13 | Nginx still works (no regression) | ✅ |
| 14 | Apache logs land on host | ✅ |
| 15 | Browser test passes | ✅ |

**Phase 4 result: ✅ PASS**

---

## Carry-Forward Notes for Future Phases

1. **Both web servers share site code and SSL certs.** Editing files in `~/Projects01/sites/*` reflects immediately in both (via bind mounts). Editing `apache2/sites/*.conf` or `nginx/sites/*.conf` requires a rebuild + restart of that specific service.

2. **Apache logs to files, not stdout.**
   - `docker compose logs apache2` shows nothing.
   - Apache logs are in `~/Projects01/docker-env/logs/apache2/`.
   - Nginx logs are in `~/Projects01/docker-env/logs/nginx/`.
   - **Nginx still works with `docker compose logs nginx`** because it logs to stdout by default in the Alpine image.

3. **Apache log timestamps are in UTC** (`+0000`), Nginx log timestamps are in BST (`+0100`). Cosmetic difference. To fix, add `tzdata` to the Apache Dockerfile in a future cleanup phase.

4. **Redundant `sed` step in the Apache Dockerfile.** Since we overwrite `httpd.conf` with `vhost.conf`, the `sed` does nothing. Cleanup opportunity in Phase 20.

5. **URL pattern map after Phase 4:**

   | Pattern | Server |
   |---|---|
   | `http://*.test` | Nginx :80 |
   | `https://*.test` | Nginx :443 |
   | `http://*.test:8080` | Apache :8080 |
   | `https://*.test:8443` | Apache :8443 |

6. **Adding a new site later requires edits in BOTH web servers:**
   - `nginx/sites/<site>.conf` (rebuild nginx)
   - `apache2/sites/<site>.conf` (rebuild apache2)
   - Regenerate `dev.test.crt` with the new hostname as an explicit SAN (for Safari)
   - `/etc/hosts` entry + DNS flush

7. **Container ports equal host ports** for Apache (8080:8080, 8443:8443). This deviates from Nginx (which uses 80:80, 443:443 — same idea). Consistent and simple.

8. **Chrome trust per port:** Chrome's cert trust is keyed by `hostname:port`. Switching from Nginx (443) to Apache (8443) means Chrome may prompt once per host:port combination. It remembers after the first "Proceed anyway."

9. **Apache modules loaded:** SSL, socache_shmcb, rewrite, proxy, proxy_fcgi, headers, plus base modules. `proxy_fcgi` is already enabled for Phase 9 (PHP through Apache) — no Dockerfile change needed for that phase.

10. **Apache runs as `www-data`** for worker processes, matching the standard Debian/Ubuntu Apache behavior.

---

## Files and System Changes in Phase 4

**Files created:**

```
~/Projects01/docker-env/apache2/Dockerfile
~/Projects01/docker-env/apache2/vhost.conf
~/Projects01/docker-env/apache2/sites/default.conf
~/Projects01/docker-env/apache2/sites/site1.conf
~/Projects01/docker-env/apache2/sites/site2.conf
~/Projects01/docker-env/logs/apache2/           (folder)
```

**Files modified:**

```
~/Projects01/docker-env/.env
~/Projects01/docker-env/docker-compose.yml
```

**Docker objects created:**

- Image: `dev-env-apache2:latest`
- Container: `dev-apache2`

**Docker objects unchanged:**

- Image: `dev-env-nginx:latest`
- Container: `dev-nginx`
- Network: `dev-env_dev-net`

---

## Rollback Procedure

To fully undo Phase 4:

```bash
cd ~/Projects01/docker-env
docker compose down apache2
docker rmi dev-env-apache2:latest
```

To also remove the Apache config folders:

```bash
rm -rf ~/Projects01/docker-env/apache2
rm -rf ~/Projects01/docker-env/logs/apache2
```

To revert `.env` and `docker-compose.yml`:
- Remove the two `APACHE_*_PORT` lines from `.env`.
- Revert `docker-compose.yml` to the Phase 3 version (nginx service only).

No data loss risk — no databases yet.

---
## Summary of Phase 4:

- ✅ 15/15 checks passed
- ✅ Apache running concurrently with Nginx on ports 8080/8443
- ✅ Same site roots, same SSL cert, both work over HTTP + HTTPS
- ✅ No interference with Nginx (regression test passed)
- ✅ All URLs working in browser
- ✅ Apache logs land on host

---

Carry-forward items for future cleanup (Phase 20):
- Remove redundant `sed` from Apache Dockerfile
- Fix Apache log timezone (install tzdata)
- Fix stale `.env` header comment

---

## Next Phase

**Phase 5 — PHP 8.4 Base Image**

Goal: build the shared `php-base84` image and verify it contains everything expected:
- PHP 8.4 (Bookworm base)
- Full extension set (bcmath, bz2, curl, dba, dom, exif, fileinfo, ftp, gd, gettext, gmp, iconv, igbinary, imagick, imap, intl, ldap, mbstring, mongodb, mysqli, opcache, pcntl, pdo, pdo_mysql, pdo_pgsql, pgsql, phar, posix, readline, redis, shmop, simplexml, soap, sockets, sodium, sqlite3, sysvmsg, sysvsem, sysvshm, tokenizer, xml, xmlreader, xmlwriter, xsl, zip, zlib)
- Plus: apcu, amqp, memcached, zmq
- LibreOffice (headless)
- ImageMagick
- Xdebug (trigger mode)

**Phase 5 builds only an image** — no service is started. It's a pure image-quality check.

Phase 5 begins once this document is saved and confirmed.

---

**End of phase_04.md**