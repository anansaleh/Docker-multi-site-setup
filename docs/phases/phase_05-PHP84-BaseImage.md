# Phase 05 — PHP 8.4 Base Image

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM)
**Host project root:** `~/Projects01/`
**Date completed:** 2026-10-13
**Status:** ✅ Complete — all checks passed

---

## Objective

Build the shared **`php-base:8.4`** image on Debian Bookworm containing:
- PHP 8.4 (from official `php:8.4-fpm-bookworm`)
- Full extension set (your list + apcu, amqp, memcached, zmq, ftp)
- LibreOffice (headless)
- ImageMagick
- Xdebug 3 (trigger mode)
- Composer (pinned at build time)

**No container runs in this phase.** We build the image and inspect it with throwaway `docker run` commands. Pure image-quality check.

**Consumed by (in later phases):**
- `php-fpm84` (Phase 7)
- `workspace84` (Phase 15)
- `php-worker84` (Phase 17)

---

## Key Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Base image | `php:8.4-fpm-bookworm` | Official PHP image with tooling pre-installed |
| Base OS | Debian Bookworm | Stable, best package availability |
| Shared base image | Yes — one for all three PHP containers | Compile extensions once, reuse everywhere |
| Xdebug mode | `debug` + `start_with_request=trigger` | Only connects when a trigger is present |
| Xdebug client host | `host.docker.internal` | Native to macOS Docker Desktop |
| Composer | Pinned via `composer:latest` at build time | Latest stable as of build |
| Timezone | `Europe/London` (hardcoded in `php.ini`) | Option A — change requires rebuild |
| ZMQ source | GitHub master (not PECL) | PECL `zmq-beta` incompatible with PHP 8.4 |

---

## Files Created in Phase 5

```
~/Projects01/docker-env/php-base84/
├── Dockerfile
├── php.ini
└── xdebug.ini
```

**Image tag:** `php-base:8.4`
**Image ID:** `9874174b1182`
**Disk usage:** 1.85 GB
**Content size:** 460 MB

---

## The `Dockerfile` — Final Version

```dockerfile
# ============================================================
# PHP 8.4 base image — shared by php-fpm84, workspace84, php-worker84
# Base: official PHP 8.4 FPM on Debian Bookworm
# ============================================================
FROM php:8.4-fpm-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Europe/London

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
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        bz2 \
        dba \
        exif \
        ftp \
        gd \
        gettext \
        gmp \
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

# --- PECL extensions (no external deps) ---
RUN pecl install \
        apcu \
        igbinary \
        imagick \
        memcached \
        mongodb \
        redis \
        xdebug \
    && docker-php-ext-enable \
        apcu \
        igbinary \
        imagick \
        memcached \
        mongodb \
        redis \
        xdebug

# --- AMQP (needs librabbitmq) ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        librabbitmq-dev \
    && printf "\n" | pecl install amqp \
    && docker-php-ext-enable amqp \
    && rm -rf /var/lib/apt/lists/*

# --- ZMQ (manual compile from GitHub master) ---
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

# Default CMD kept from php:8.4-fpm image (FPM daemon)
# Child images (workspace, worker) override CMD as needed.
```

---

## The `php.ini` — Final Version

```ini
; ============================================================
; PHP 8.4 base configuration
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

**Dev-friendly rationale:**
- `display_errors = On` + `error_reporting = E_ALL` — see every warning during development.
- `opcache.validate_timestamps = 1` + `revalidate_freq = 0` — pick up code changes on every request.
- `memory_limit = 512M` — a bit generous but fine for dev; avoids Composer ops hitting limits.
- `max_input_vars = 5000` — avoids silent truncation on large forms.

---

## The `xdebug.ini` — Final Version

```ini
; ============================================================
; Xdebug 3 configuration — PHP 8.4
; Local development environment
;
; NOTE: Do NOT add `zend_extension=xdebug` here.
; The Docker PHP image enables Xdebug via
; /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
; (created by `docker-php-ext-enable xdebug`).
; Adding it here would cause a "Cannot load Xdebug -
; it was already loaded" warning on every PHP invocation.
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

**Critical:** No `zend_extension=xdebug` line. The `docker-php-ext-enable` mechanism handles loading. Our file only sets options.

---

## Step-by-Step Record

### Step 5.1 — Create folder

```bash
mkdir -p ~/Projects01/docker-env/php-base84
```

**Result:** ✅ Pass.

---

### Step 5.2 — Create `php.ini`

**Result:** ✅ Pass. (See full content above.)

---

### Step 5.3 — Create `xdebug.ini`

**Result:** ✅ Pass. (See full content above.)

---

### Step 5.4 — Create initial `Dockerfile`

**Result:** ✅ Pass — but the initial version had 4 bugs that were discovered during the build attempts (see below).

---

### Step 5.5 — Build Attempt 1 — FAILED

**Error:**
```
configure: error: Cannot find ldap libraries in /usr/lib/x86_64-linux-gnu.
```

**Root cause:** Apple M4 = ARM64. Our Dockerfile used `--with-libdir=lib/x86_64-linux-gnu`, but on ARM64 Debian, libraries live in `lib/aarch64-linux-gnu` or `lib/`.

**Fix:** Removed `docker-php-ext-configure ldap --with-libdir=...` entirely. LDAP auto-detects.

---

### Step 5.5.2 — Build Attempt 2 — FAILED

**Error:**
```
configure: error: unrecognized options: --with-db
```

**Root cause:** PHP 8.4's `dba` extension no longer accepts `--with-db`.

**Fix:** Removed `docker-php-ext-configure dba --with-db` entirely. Just list `dba` in `docker-php-ext-install`; PHP auto-detects available backends from `libdb-dev`.

---

### Step 5.5.4 — Build Attempt 3 — FAILED

**Error:**
```
configure: error: memcached support requires libmemcached.
```

**Root cause:** The `memcached` PECL extension needs `libmemcached-dev` at build time.

**Fix:** Added `libmemcached-dev` to the apt-get install list.

---

### Step 5.5.6 — Build Attempt 4 — FAILED

**Error:**
```
zmq.c:238:34: error: lvalue required as left operand of assignment
zend_fcall_info has no member named 'no_separation'
```

**Root cause:** PECL `zmq-beta` (1.1.3, released 2016) uses PHP 5.x/7.x APIs that were removed in PHP 8.x. Source code fundamentally incompatible.

**Attempted fix 1:** Try `apt install php8.4-zmq` — failed, package not in Bookworm repos (only in testing/unstable).

**Attempted fix 2:** Manual compile from GitHub master branch (`mkoppanen/php-zmq`). Success.

**Final ZMQ section in Dockerfile:**
```dockerfile
# --- ZMQ (manual compile from GitHub master) ---
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
```

**Result:** ✅ Build succeeded.

---

### Step 5.6a — Verify PHP version — FAILED (warning)

**Command:** `docker run --rm php-base:8.4 php -v`

**Output:**
```
Cannot load Xdebug - it was already loaded
PHP 8.4.26 (cli) (built: Oct  6 2026 01:29:35) (NTS)
...
with Xdebug v3.5.3, Copyright (c) 2002-2026, by Derick Rethans
```

**Root cause:** Xdebug was being loaded twice:
1. Via `docker-php-ext-xdebug.ini` (created by `docker-php-ext-enable xdebug`)
2. Via our `zz-xdebug.ini` which also had `zend_extension=xdebug`

**Fix:** Removed `zend_extension=xdebug` from our `xdebug.ini`. Added a comment explaining why.

---

### Step 5.6a-fix — Rebuild — SUCCESS

**Result:** ✅ Clean `php -v` output. Xdebug v3.5.3 loaded once.

---

### Step 5.6b — Verify extension list — PASSED (with one missing)

**Command:** `docker run --rm php-base:8.4 php -m`

**Result:** All extensions present except `ftp`. Your original list included `ftp`, so we added it.

**Fix:** Added `ftp \` to the `docker-php-ext-install` list.

---

### Step 5.6b-fix — Rebuild with `ftp` — SUCCESS

**Result:** ✅ `ftp` present in `php -m`. No regressions.

---

### Step 5.7 — Verify LibreOffice — PASSED

**Command:** `docker run --rm php-base:8.4 soffice --version`

**Output:** `LibreOffice 7.4.7.2 40(Build:2)`

---

### Step 5.8 — Verify ImageMagick — PASSED

**Commands:**
- `docker run --rm php-base:8.4 convert --version` → `ImageMagick 6.9.11-60 Q16 aarch64`
- `docker run --rm php-base:8.4 magick --version` → `not found` (expected — `magick` is IM 7 syntax; Debian ships IM 6)

**Note:** The PHP `imagick` extension is what matters, and it's loaded. `convert` CLI is available for shell-outs.

---

### Step 5.9 — Verify Composer — PASSED

**Command:** `docker run --rm php-base:8.4 composer --version`

**Output:** `Composer version 2.10.3 2026-08-27 13:34:23`

---

### Step 5.10 — Verify Xdebug config — PASSED

**Command:** `docker run --rm php-base:8.4 php -i | grep -E "^xdebug\."`

**Confirmed:**
- `xdebug.mode => debug`
- `xdebug.start_with_request => trigger`
- `xdebug.client_host => host.docker.internal`
- `xdebug.client_port => 9003`
- `xdebug.idekey => PHPSTORM`
- `xdebug.discover_client_host => Off`
- `xdebug.log_level => 0`

---

### Step 5.10b — Verify `php.ini` settings — PASSED (with 3 CLI-only discrepancies)

**Command:** `docker run --rm php-base:8.4 php -i | grep -E "^..."`

**Confirmed:**
- `memory_limit => 512M`
- `upload_max_filesize => 128M`
- `post_max_size => 128M`
- `max_input_vars => 5000`
- `log_errors => On`
- `date.timezone => Europe/London`

**Discrepancies (all CLI-SAPI behaviour, not real bugs):**
- `display_errors => STDOUT` (base image's `docker-fpm.ini` overrides; STDOUT is fine for containers)
- `max_execution_time => 0` (CLI SAPI hardcodes unlimited; FPM will respect 300)
- `max_input_time => -1` (same reasoning; FPM will respect 300)

Will verify FPM respects `php.ini` values in Phase 7.

---

### Step 5.11 — Verify ZMQ extension — PASSED

**Command:**
```bash
docker run --rm php-base:8.4 php -r "echo extension_loaded('zmq') ? 'ZMQ loaded' : 'ZMQ NOT loaded';"
```

**Output:** `ZMQ loaded`

**Also:**
```bash
docker run --rm php-base:8.4 php -r "var_dump(class_exists('ZMQ'));"
```
**Output:** `bool(true)`

---

### Step 5.12 — Record image size and ID

**Commands:**
```bash
docker images php-base:8.4
docker image inspect php-base:8.4 --format '{{.Size}}'
```

**Output:**
- Image ID: `9874174b1182`
- Disk usage: `1.85 GB`
- Content size: `460 MB` (compressed)
- Inspect size: `438.637 MB`

---

## Final Extension List (verified)

```
amqp
apcu
bcmath
bz2
Core
ctype
curl
date
dba
dom
exif
fileinfo
filter
ftp
gd
gettext
gmp
hash
iconv
igbinary
imagick
intl
json
ldap
libxml
mbstring
memcached
mongodb
mysqli
mysqlnd
openssl
pcntl
pcre
PDO
pdo_mysql
pdo_pgsql
pdo_sqlite
pgsql
Phar
posix
random
readline
redis
Reflection
session
shmop
SimpleXML
soap
sockets
sodium
SPL
sqlite3
standard
sysvmsg
sysvsem
sysvshm
tokenizer
xdebug
xml
xmlreader
xmlwriter
xsl
Zend OPcache
zip
zlib
zmq
```

**Total: 62 extensions + 2 Zend modules (Xdebug, OPcache).**

**Deliberately excluded:**
- `xmlrpc` — removed from PHP core in 8.0; PECL version breaks on 8.4
- `imap` — removed from PHP core in 8.4; PECL version currently fragile

---

## Phase 5 Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | `php-base84/` folder created | ✅ |
| 2 | `php.ini` created | ✅ |
| 3 | `xdebug.ini` created | ✅ |
| 4 | `Dockerfile` created and iterated | ✅ |
| 5 | ARM64 LDAP issue fixed | ✅ |
| 6 | `dba` config issue fixed | ✅ |
| 7 | `memcached` dependency fixed | ✅ |
| 8 | ZMQ compiled from GitHub master | ✅ |
| 9 | Xdebug duplicate-load warning fixed | ✅ |
| 10 | `ftp` extension added | ✅ |
| 11 | PHP 8.4.26 verified | ✅ |
| 12 | LibreOffice 7.4.7.2 verified | ✅ |
| 13 | ImageMagick 6.9.11-60 verified | ✅ |
| 14 | Composer 2.10.3 verified | ✅ |
| 15 | Xdebug config verified | ✅ |
| 16 | `php.ini` settings applied | ✅ |
| 17 | ZMQ extension loaded | ✅ |
| 18 | Image size recorded | ✅ |

**Phase 5 result: ✅ PASS**

---

## Carry-Forward Notes for Future Phases

1. **`php-base:8.4` is now immutable.** Any edits require a rebuild. Cached layers make rebuilds fast:
   - ~1–2 seconds if only config files changed (last 3 layers).
   - ~2–3 minutes if extensions changed (compile layer + later).
   - ~10–20 minutes if system deps changed (first apt layer + everything after).

2. **Build issues and their fixes (lessons for Phase 6 — PHP 7.4):**
   - **ARM64:** never hardcode `--with-libdir=lib/x86_64-linux-gnu`. Let extensions auto-detect.
   - **`dba`:** do NOT use `--with-db` on PHP 8.x. Just list `dba` and include `libdb-dev`.
   - **`memcached`:** requires `libmemcached-dev` in the apt step.
   - **`zmq`:** PECL `zmq-beta` is incompatible with PHP 8.x. Compile from GitHub master.

3. **`xdebug.ini` must NOT contain `zend_extension=xdebug`.** Loading is handled by `docker-php-ext-enable`. The warning comment in the file prevents regressions.

4. **Image size: 1.85 GB on disk.** All three child containers (`php-fpm84`, `workspace84`, `php-worker84`) will reuse these layers via Docker cache — their deltas will be tiny.

5. **Composer pinned via `composer:latest`** — rebuilds may pull newer versions. Acceptable for dev.

6. **`display_errors = STDOUT`** — set by the base image's `docker-fpm.ini`. Overrides our `php.ini`. Fine for containers.

7. **`max_execution_time = 0`, `max_input_time = -1`** — CLI SAPI defaults. FPM will respect our `php.ini` values (300 each). Will verify in Phase 7.

8. **`ftp` was added to the original list** — one extra rebuild, done.

9. **Image ID:** `9874174b1182` — useful for reference.

10. **`.env` header comment still says "Phase 3"** — cosmetic, fix in Phase 20.

---

## Files and System Changes in Phase 5

**Files created:**

```
~/Projects01/docker-env/php-base84/Dockerfile
~/Projects01/docker-env/php-base84/php.ini
~/Projects01/docker-env/php-base84/xdebug.ini
```

**Docker objects created:**

- Image: `php-base:8.4` (ID: `9874174b1182`, 1.85 GB)

**No files modified, no containers created, no networks changed.**

---

## Rollback Procedure

To fully undo Phase 5:

```bash
docker rmi php-base:8.4
rm -rf ~/Projects01/docker-env/php-base84
```

No data loss — no databases or mounted volumes involved.

---
## Summary of Phase 5:

- ✅ 18/18 checks passed (with minor CLI-only discrepancies that are expected)
- ✅ php-base:8.4 built, verified, ready for Phase 7, 15, 17
- ✅ 4 build bugs found and fixed (ARM64 LDAP, dba, memcached, zmq)
- ✅ Xdebug duplicate-load warning fixed
- ✅ 62 extensions + 2 Zend modules
- ✅ Image is 1.85 GB (one-time cost, shared across 3 child containers)
- ✅ LibreOffice, ImageMagick, Composer, ZMQ all verified working

---

## Next Phase

**Phase 6 — PHP 7.4 Base Image**

Goal: build the shared **`php-base:7.4`** image, mirroring Phase 5, but on **Debian Bullseye** (Debian 11) because:
- PHP 7.4 is EOL and requires older OpenSSL/glibc than Bookworm provides.
- Bullseye has PHP 7.4 in a compatible toolchain.

**Anticipated differences from Phase 5:**
- Base: `php:7.4-fpm-bullseye`
- Some extensions may need older versions (imagick, mongodb, redis must match PHP 7.4 ABI).
- `imap` **is available** in PHP 7.4 core (we may include it here).
- `xmlrpc` **may compile** on 7.4 (PECL version that supports it exists).
- `sodium`, `zip`, etc. — mostly the same.
- Xdebug version for PHP 7.4 is **3.1.x** (not 3.5.x).

Phase 6 begins once this document is saved and confirmed.

---

**End of phase_05.md**