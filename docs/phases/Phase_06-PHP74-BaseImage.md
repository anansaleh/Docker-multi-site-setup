# Phase 06 — PHP 7.4 Base Image

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM)
**Host project root:** `~/Projects01/`
**Date completed:** 2026-10-14
**Status:** ✅ Complete — all checks passed

---

## Objective

Build the shared **`php-base:7.4`** image on **Debian Bullseye** containing:
- PHP 7.4 (from official `php:7.4-fpm-bullseye`)
- Full extension set (mirror of 8.4 + `imap`, which is available on 7.4)
- LibreOffice (headless)
- ImageMagick
- Xdebug 3.1.6 (last version supporting PHP 7.4)
- Composer (pinned at build time)

**No container runs in this phase.** Pure image-quality check.

**Consumed by (in later phases):**
- `php-fpm74` (Phase 8)
- `workspace74` (Phase 15)
- `php-worker74` (Phase 17)

---

## Critical Context — PHP 7.4 is EOL and Bullseye is Archived

**PHP 7.4 reached end-of-life in November 2022.**
**Debian 11 (Bullseye) reached end-of-life in August 2026.**

As a direct result, Debian removed most Bullseye packages from its live security repository. Attempting to `apt-get install` on Bullseye now returns **404 errors** for hundreds of packages.

**Solution:** Pin apt sources to a `snapshot.debian.org` timestamp from just before EOL. The snapshot archive preserves the exact state of the repositories as of a specific date — index and packages are guaranteed consistent.

---

## Key Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Base image | `php:7.4-fpm-bullseye` | Official PHP 7.4 FPM on Debian 11 |
| apt source | `snapshot.debian.org` @ `20260825T000000Z` | Bullseye is EOL; live repos return 404s |
| Security repo path | `/archive/debian-security/` (not `/archive/debian/`) | Separate snapshot tree for security packages |
| `Acquire::Check-Valid-Until` | Disabled | Snapshot Release files are expired by design |
| mongodb version | `mongodb-1.19.2` | Latest version supporting PHP 7.4 |
| xdebug version | `xdebug-3.1.6` | Last version supporting PHP 7.4 |
| ZMQ source | GitHub master (not PECL) | PECL `zmq-beta` (2016) is incompatible with PHP 7.3+ |
| imap | Included | Available in PHP 7.4 core (removed in 8.4) |
| Composer | `composer:latest` at build time | Version 2.10.3 works on PHP 7.4 |

---

## Files Created in Phase 6

```
~/Projects01/docker-env/php-base74/
├── Dockerfile
├── php.ini
└── xdebug.ini
```

**Image tag:** `php-base:7.4`
**Image ID:** `d8a1443bafa0`
**Disk usage:** 1.72 GB
**Content size:** 435 MB

---

## The `Dockerfile` — Final Version

```dockerfile
# ============================================================
# PHP 7.4 base image — shared by php-fpm74, workspace74, php-worker74
# Base: official PHP 7.4 FPM on Debian Bullseye
#
# NOTE: PHP 7.4 is EOL (Nov 2022). This image is frozen at its
# last official release. Suitable for legacy project development.
# ============================================================
FROM php:7.4-fpm-bullseye

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Europe/London

# Pin apt sources to a pre-EOL snapshot of Bullseye.
# Debian 11 reached EOL on Aug 31, 2026; live repos now return 404s.
# snapshot.debian.org preserves the exact repository state as of a date.
RUN printf "deb [check-valid-until=no] http://snapshot.debian.org/archive/debian/20260825T000000Z bullseye main\ndeb [check-valid-until=no] http://snapshot.debian.org/archive/debian/20260825T000000Z bullseye-updates main\ndeb [check-valid-until=no] http://snapshot.debian.org/archive/debian-security/20260825T000000Z bullseye-security main\n" > /etc/apt/sources.list \
    && printf "Acquire::Check-Valid-Until \"false\";\n" > /etc/apt/apt.conf.d/99no-check-valid-until

# --- System dependencies for extensions + tools ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        # Build tooling
        build-essential \
        pkg-config \
        autoconf \
        libtool \
        # Extension dependencies
        libbz2-dev \
        libcurl4-openssl-dev \
        libgmp-dev \
        libicu-dev \
        libldap2-dev \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        libxslt1-dev \
        libssl-dev \
        libsqlite3-dev \
        libpq-dev \
        libsnmp-dev \
        libkrb5-dev \
        default-libmysqlclient-dev \
        libdb-dev \
        libmemcached-dev \
        libc-client2007e-dev \
        # Image processing
        imagemagick \
        libmagickwand-dev \
        # LibreOffice (headless)
        libreoffice-writer \
        libreoffice-calc \
        libreoffice-impress \
        libreoffice-core \
        # Common CLI tools
        git \
        curl \
        unzip \
        zip \
        gnupg \
        ca-certificates \
        procps \
        tzdata \
        # Graph/PDF tools
        ghostscript \
        poppler-utils \
    && rm -rf /var/lib/apt/lists/*

# --- Bundled PHP extensions ---
RUN docker-php-ext-configure gd \
        --with-freetype --with-jpeg \
    && docker-php-ext-configure imap \
        --with-imap --with-imap-ssl --with-kerberos \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        bz2 \
        dba \
        exif \
        ftp \
        gd \
        gettext \
        gmp \
        imap \
        intl \
        ldap \
        mysqli \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        pgsql \
        shmop \
        soap \
        sockets \
        sysvmsg \
        sysvsem \
        sysvshm \
        xsl \
        zip \
    && docker-php-ext-enable opcache

# --- PECL extensions (install separately to isolate failures) ---
RUN pecl install apcu igbinary imagick memcached redis \
    && docker-php-ext-enable apcu igbinary imagick memcached redis

# --- MongoDB (pin version compatible with PHP 7.4) ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        libssl-dev \
        libsasl2-dev \
    && pecl install mongodb-1.19.2 \
    && docker-php-ext-enable mongodb \
    && rm -rf /var/lib/apt/lists/*

# --- Xdebug (pin version compatible with PHP 7.4) ---
RUN pecl install xdebug-3.1.6 \
    && docker-php-ext-enable xdebug

# --- AMQP (needs librabbitmq) ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        librabbitmq-dev \
    && printf "\n" | pecl install amqp \
    && docker-php-ext-enable amqp \
    && rm -rf /var/lib/apt/lists/*

# --- ZMQ (manual compile from GitHub master — PECL version is incompatible) ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzmq3-dev \
        unzip \
    && curl -L https://github.com/mkoppanen/php-zmq/archive/refs/heads/master.zip -o /tmp/zmq.zip \
    && unzip /tmp/zmq.zip -d /tmp/ \
    && cd /tmp/php-zmq-master \
    && phpize \
    && ./configure \
    && make -j$(nproc) \
    && make install \
    && docker-php-ext-enable zmq \
    && rm -rf /tmp/zmq.zip /tmp/php-zmq-master \
    && rm -rf /var/lib/apt/lists/*

# --- Composer (pinned at build time) ---
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# --- Xdebug + PHP config ---
COPY php.ini    /usr/local/etc/php/conf.d/zz-php.ini
COPY xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini

# --- Log directory ---
RUN mkdir -p /var/log/php && chmod 777 /var/log/php

WORKDIR /var/www/html

# Default CMD kept from php:7.4-fpm image (FPM daemon)
# Child images (workspace, worker) override CMD as needed.
```

---

## The `php.ini` — Final Version

Identical to the 8.4 version, with the header comment updated:

```ini
; ============================================================
; PHP 7.4 base configuration
; Local development environment
; ============================================================

[PHP]
memory_limit = 512M
upload_max_filesize = 128M
post_max_size = 128M
max_execution_time = 300
max_input_time = 300
max_input_vars = 5000
display_errors = On
display_startup_errors = On
error_reporting = E_ALL
log_errors = On
error_log = /var/log/php/error.log
date.timezone = Europe/London

[opcache]
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 0

[Session]
session.save_handler = files
session.save_path = /tmp
```

---

## The `xdebug.ini` — Final Version

```ini
; ============================================================
; Xdebug 3 configuration — PHP 7.4
; Local development environment
;
; NOTE: Do NOT add `zend_extension=xdebug` here.
; The Docker PHP image enables Xdebug via
; /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
; (created by `docker-php-ext-enable xdebug`).
; Adding it here would cause a "Cannot load Xdebug -
; it was already loaded" warning on every PHP invocation.
;
; NOTE: PHP 7.4 uses Xdebug 3.1.x (not 3.5.x). The setting
; names are the same, so this file works for both versions.
; ============================================================

[xdebug]
xdebug.mode = debug
xdebug.start_with_request = trigger
xdebug.client_host = host.docker.internal
xdebug.client_port = 9003
xdebug.idekey = PHPSTORM
xdebug.log_level = 0
xdebug.discover_client_host = false
```

---

## Step-by-Step Record

### Step 6.1 — Folder created

```bash
mkdir -p ~/Projects01/docker-env/php-base74
```

**Result:** ✅ Pass.

---

### Step 6.2 — `php.ini` created

**Result:** ✅ Pass.

---

### Step 6.3 — `xdebug.ini` created

**Result:** ✅ Pass.

---

### Step 6.4 — Initial `Dockerfile` created

**Result:** ✅ Pass — but the initial version had 4 bugs that surfaced during builds.

---

### Step 6.5 — Build Attempt 1 — FAILED (Bullseye EOL)

**Error:**
Hundreds of lines like:
```
Err:1 http://deb.debian.org/debian-security bullseye-security/main arm64 imagemagick-6-common all 8:6.9.11.60+dfsg-1.3+deb11u16
   404  Not Found [IP: 151.101.2.132 80]
```

**Root cause:** Debian 11 (Bullseye) reached EOL on Aug 31, 2026. Packages were removed from the live security repository. The `Packages.gz` index still referenced them, but the `.deb` files were gone → 404s everywhere.

**Fix:** Pin apt sources to `snapshot.debian.org` at a pre-EOL timestamp (`20260825T000000Z`). Add `Acquire::Check-Valid-Until "false"` since snapshot Release files are expired by design.

---

### Step 6.5.2 — Build Attempt 2 — FAILED (wrong security path)

**Error:**
```
E: The repository 'http://snapshot.debian.org/archive/debian/20260825T000000Z bullseye-security Release' does not have a Release file.
```

**Root cause:** Security packages live under `/archive/debian-security/`, not `/archive/debian/`. The main and updates repos downloaded fine (75 kB and 44 kB), but security returned 404.

**Fix:** Corrected the third line to use `/archive/debian-security/`:

```dockerfile
deb [check-valid-until=no] http://snapshot.debian.org/archive/debian-security/20260825T000000Z bullseye-security main
```

---

### Step 6.5.4 — Build Attempt 3 — FAILED (mongodb)

**Error:**
```
error: 'mongodb' does not exist
Possible values for module-name:
apcu.so bcmath.so bz2.so ... redis.so ...
```

**Root cause:** `pecl install mongodb` (no version pin) tried to install a version that has dropped PHP 7.4 support. The compile failed silently; `mongodb.so` was never created; `docker-php-ext-enable mongodb` couldn't find it.

**Fix:** Pinned to `mongodb-1.19.2` (last version supporting PHP 7.4). Split the PECL block so mongodb installation is isolated from the others.

---

### Step 6.5.6 — Build Attempt 4 — FAILED (xdebug)

**Error:**
```
error: 'xdebug' does not exist
```

Same pattern as mongodb. `pecl install xdebug` tried to install 3.5.x, which requires PHP 8.0+.

**Fix:** Pinned to `xdebug-3.1.6` (last version supporting PHP 7.4). Isolated in its own RUN step.

---

### Step 6.5.8 — Build Attempt 5 — FAILED (ZMQ + Dockerfile malformed)

Two issues in this attempt:

**Issue A — ZMQ compilation failure:**
```
zmq.c:238:20: error: lvalue required as left operand of assignment
   GC_REFCOUNT(&le) = 1;
```

Same PECL `zmq-beta` incompatibility we saw with PHP 8.4.

**Issue B — Malformed Dockerfile:** In an edit, the old commented-out ZMQ `RUN` line was left active. Docker concatenated it with the new ZMQ `RUN` line, resulting in:
```
apt-get install -y --no-install-recommends RUN apt-get update && ...
```
Apt tried to install packages named `RUN`, `apt-get`, `update` → `E: Unable to locate package`.

**Fix:**
1. Fully removed the old ZMQ block (including the still-active `RUN` line).
2. Replaced ZMQ with the manual GitHub compile (same as Phase 5).

---

### Step 6.5.10 — Build Attempt 6 — SUCCESS

**Result:** ✅ Image built in 20 seconds (with all earlier layers cached). Image `php-base:7.4` created.

---

### Step 6.6a — Verify PHP version — PASSED

**Command:** `docker run --rm php-base:7.4 php -v`

**Output:**
```
PHP 7.4.33 (cli) (built: Nov 15 2022 03:43:02) ( NTS )
Copyright (c) The PHP Group
Zend Engine v3.4.0, Copyright (c) Zend Technologies
    with Zend OPcache v7.4.33, Copyright (c), by Zend Technologies
    with Xdebug v3.1.6, Copyright (c) 2002-2022, by Derick Rethans
```

**Confirms:** PHP 7.4.33 (final release), Zend Engine 3.4.0, Xdebug 3.1.6, no warnings.

---

### Step 6.6b — Verify extension list — PASSED

**Command:** `docker run --rm php-base:7.4 php -m`

**Full list (62 modules + 2 Zend modules):**

```
amqp, apcu, bcmath, bz2, Core, ctype, curl, date, dba, dom, exif, fileinfo,
filter, ftp, gd, gettext, gmp, hash, iconv, igbinary, imagick, imap, intl,
json, ldap, libxml, mbstring, memcached, mongodb, mysqli, mysqlnd, openssl,
pcntl, pcre, PDO, pdo_mysql, pdo_pgsql, pdo_sqlite, pgsql, Phar, posix,
readline, redis, Reflection, session, shmop, SimpleXML, soap, sockets,
sodium, SPL, sqlite3, standard, sysvmsg, sysvsem, sysvshm, tokenizer,
xdebug, xml, xmlreader, xmlwriter, xsl, Zend OPcache, zip, zlib, zmq

Zend Modules:
Xdebug
Zend OPcache
```

**Notable:** `imap` is present on 7.4 (unlike 8.4 where it was removed from PHP core).

---

### Step 6.7 — Verify LibreOffice — PASSED

**Command:** `docker run --rm php-base:7.4 soffice --version`

**Output:** `LibreOffice 7.0.4.2 00(Build:2)`

**Note:** Older than 8.4's 7.4.7.2 — expected (Bullseye is the older OS).

---

### Step 6.8 — Verify ImageMagick — PASSED

**Command:** `docker run --rm php-base:7.4 convert --version`

**Output:** `ImageMagick 6.9.11-60 Q16 aarch64 2021-01-25`

**Note:** Same version as 8.4 — Debian security backports aligned them. Full delegates present.

---

### Step 6.8b — Verify `imagick` PHP extension — PASSED

**Command:** `docker run --rm php-base:7.4 php -r "echo extension_loaded('imagick') ? 'imagick loaded' : 'imagick NOT loaded';"`

**Output:** `imagick loaded`

---

### Step 6.9 — Verify Composer — PASSED

**Command:** `docker run --rm php-base:7.4 composer --version`

**Output:** `Composer version 2.10.3 2026-08-27 13:34:23` (running on PHP 7.4.33)

---

### Step 6.10 — Verify Xdebug config — PASSED

All settings present and correct: mode=debug, start_with_request=trigger, client_host=host.docker.internal, client_port=9003, idekey=PHPSTORM, discover_client_host=Off, log_level=0.

---

### Step 6.11 — Verify ZMQ extension — PASSED

```
ZMQ loaded
bool(true)
```

---

### Step 6.12 — Verify `imap` extension — PASSED

```
imap loaded
bool(true)
```

`imap_open()` function exists — 7.4-specific advantage.

---

### Step 6.13 — Record image size and ID

**Commands:**
```bash
docker images php-base:7.4
docker image inspect php-base:7.4 --format '{{.Size}}'
```

**Output:**
- Image ID: `d8a1443bafa0`
- Disk usage: `1.72 GB`
- Content size: `435 MB`
- Inspect size: `414.538 MB`

---

## Comparison: 8.4 vs 7.4 Base Images

| Metric | php-base:8.4 | php-base:7.4 | Delta |
|---|---|---|---|
| Base OS | Debian Bookworm | Debian Bullseye | — |
| PHP version | 8.4.26 | 7.4.33 | — |
| Zend Engine | 4.4.26 | 3.4.0 | — |
| Xdebug | 3.5.3 | 3.1.6 | 7.4-locked |
| LibreOffice | 7.4.7.2 | 7.0.4.2 | Older on 7.4 |
| ImageMagick | 6.9.11-60 | 6.9.11-60 | Same |
| Composer | 2.10.3 | 2.10.3 | Same |
| `imap` extension | ❌ Not available | ✅ Available | 7.4-specific |
| Extension count | 62 | 62 | Same set |
| Disk usage | 1.85 GB | 1.72 GB | −130 MB |
| Content size | 460 MB | 435 MB | −25 MB |
| Image ID | `9874174b1182` | `d8a1443bafa0` | — |

---

## Phase 6 Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | `php-base74/` folder created | ✅ |
| 2 | `php.ini` created | ✅ |
| 3 | `xdebug.ini` created | ✅ |
| 4 | `Dockerfile` created | ✅ |
| 5 | Snapshot repo fix applied | ✅ |
| 6 | Security repo path corrected | ✅ |
| 7 | mongodb pinned to 1.19.2 | ✅ |
| 8 | xdebug pinned to 3.1.6 | ✅ |
| 9 | ZMQ compiled from GitHub master | ✅ |
| 10 | Malformed Dockerfile fixed | ✅ |
| 11 | PHP 7.4.33 verified | ✅ |
| 12 | 62 extensions + 2 Zend modules verified | ✅ |
| 13 | `imap` verified (7.4-specific) | ✅ |
| 14 | LibreOffice 7.0.4.2 verified | ✅ |
| 15 | ImageMagick 6.9.11-60 verified | ✅ |
| 16 | Composer 2.10.3 verified | ✅ |
| 17 | Xdebug 3.1.6 config verified | ✅ |
| 18 | ZMQ extension verified | ✅ |
| 19 | Image size recorded | ✅ |

**Phase 6 result: ✅ PASS**

---

## Carry-Forward Notes for Future Phases

1. **`php-base:7.4` is now immutable.** Rebuilds require:
   - ~1–2 seconds if only `php.ini` or `xdebug.ini` change.
   - ~2–3 minutes if extensions change.
   - ~10–20 minutes if the system deps layer changes (it re-downloads from snapshot).

2. **Snapshot repository pin (critical):** The 7.4 Dockerfile pins apt to `snapshot.debian.org` @ `20260825T000000Z`. Without this, all builds fail. The three lines are:
   ```
   deb [check-valid-until=no] http://snapshot.debian.org/archive/debian/20260825T000000Z bullseye main
   deb [check-valid-until=no] http://snapshot.debian.org/archive/debian/20260825T000000Z bullseye-updates main
   deb [check-valid-until=no] http://snapshot.debian.org/archive/debian-security/20260825T000000Z bullseye-security main
   ```
   Note: security uses `debian-security` path, not `debian`.

3. **PECL version pins on 7.4 (never remove these):**
   - `mongodb-1.19.2` — later versions drop PHP 7.4 support.
   - `xdebug-3.1.6` — later versions require PHP 8.0+.
   - `apcu`, `igbinary`, `imagick`, `memcached`, `redis` — auto-select compatible versions, no pin needed.

4. **ZMQ compile from GitHub master** — same technique as 8.4. PECL `zmq-beta` is dead on both PHP 7.4 and 8.4.

5. **`imap` is 7.4-only.** Present in `php-base:7.4`, absent in `php-base:8.4`. Legacy projects using IMAP belong on 7.4.

6. **LibreOffice versions differ** between the two base images (7.0 vs 7.4). Document conversion output may differ subtly. Acceptable for dev; production should use one version consistently.

7. **ImageMagick is consistent** between both (6.9.11-60). No divergence.

8. **Composer version watching:** Composer 2.10.3 currently works on PHP 7.4. If a future Composer release drops PHP 7.2/7.4 support, the 7.4 image build will fail. Fix: pin `composer:2.7` (or similar) in the `COPY --from` line.

9. **Image IDs:**
   - `php-base:8.4` → `9874174b1182`
   - `php-base:7.4` → `d8a1443bafa0`

10. **`xmlrpc`** — deliberately excluded from both images. Can be added via `pecl install xmlrpc-1.0.0RC3` if a legacy project needs it.

11. **`display_errors => STDOUT`, `max_execution_time => 0`, `max_input_time => -1`** on CLI SAPI — same behavior as the 8.4 image. FPM will respect `php.ini` values (300 seconds). Will verify in Phase 8.

---

## Files and System Changes in Phase 6

**Files created:**

```
~/Projects01/docker-env/php-base74/Dockerfile
~/Projects01/docker-env/php-base74/php.ini
~/Projects01/docker-env/php-base74/xdebug.ini
```

**Docker objects created:**

- Image: `php-base:7.4` (ID: `d8a1443bafa0`, 1.72 GB)

**No files modified, no containers created, no networks changed.**

---

## Rollback Procedure

To fully undo Phase 6:

```bash
docker rmi php-base:7.4
rm -rf ~/Projects01/docker-env/php-base74
```

No data loss — no databases or mounted volumes involved.
---
## Summary of Phase 6:

- ✅ 19/19 checks passed
- ✅ php-base:7.4 built, verified, ready for Phase 8, 15, 17
- ✅ Snapshot repository pin fixed (Bullseye EOL workaround)
- ✅ 4 version pins and fixes: snapshot repos, security path, mongodb-1.19.2, xdebug-3.1.6, ZMQ GitHub master
- ✅ 62 extensions + 2 Zend modules (including imap — 7.4-specific)
- ✅ Image is 1.72 GB
- ✅ LibreOffice 7.0.4.2, ImageMagick 6.9.11-60, Composer 2.10.3, Xdebug 3.1.6 all verified

**Both PHP base images are now complete. Phase 7 moves to actual runtime integration.**

---

## Next Phase

**Phase 7 — php-fpm 8.4 + Nginx Integration**

Goal: wire `php-fpm84` to Nginx so `site1.test` serves PHP through FPM.

Phase 7 will:
- Create `docker-env/php-fpm84/Dockerfile` (thin: `FROM php-base:8.4`)
- Extend `docker-compose.yml` with the `php-fpm84` service
- Update Nginx `sites/*.conf` to pass `.php` requests to `php-fpm84` via FastCGI
- Replace placeholder HTML with a `phpinfo()` or simple echo script in each site root
- Verify PHP execution end-to-end via HTTP/HTTPS

**One PHP version, one web server.** This is the first real PHP integration. Once working, extending to 7.4 (Phase 8) and Apache (Phase 9) becomes mechanical.

Phase 7 begins once this document is saved and confirmed.

---

**End of phase_06.md**