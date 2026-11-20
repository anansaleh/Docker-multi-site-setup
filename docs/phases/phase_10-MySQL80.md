# Phase 10 — MySQL 8.0 (First Database)

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM)
**Host project root:** `~/Projects01/`
**Date completed:** 2026-10-15
**Status:** ✅ Complete — all checks passed

---

## Objective

Bring up MySQL 8.0 as the first database service. Establish the pattern that MariaDB, PostgreSQL, MongoDB, and Redis will follow:

- One container per engine
- Version-suffixed data folder on the host
- Version selected via `.env`
- Logs land on the host
- Data survives container restart
- Reachable from PHP containers (by hostname) and from the host (by port)

---

## Final State After Phase 10

| Component | Details |
|---|---|
| Container name | `dev-mysql` |
| Image | `dev-env-mysql:latest` (built `FROM mysql:8.0` + `my.cnf`) |
| MySQL server version | 8.0.46 |
| Host port | 3306 |
| Internal port | 3306 |
| Data directory (host) | `~/Projects01/docker-env/data_files/mysql80/` |
| Log directory (host) | `~/Projects01/docker-env/logs/mysql/` |
| Root user password | `root` (from `.env`) |
| Character set | `utf8mb4` / `utf8mb4_unicode_ci` |
| Timezone | UTC inside MySQL |
| Healthcheck | `mysqladmin ping`, every 10s |
| Network | `dev-env_dev-net` |

---

## What We Built

```
~/Projects01/docker-env/
├── data_files/
│   └── mysql80/                   (persistent MySQL data — on host)
├── logs/
│   └── mysql/                     (error.log, slow.log — on host)
├── mysql/
│   ├── Dockerfile
│   └── my.cnf
└── docker-compose.yml             (extended: mysql service)
```

---

## Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Image | Official `mysql:8.0` + custom `my.cnf` | No custom Dockerfile logic needed; only config |
| Version control | `MYSQL_VERSION=8.0` in `.env` → `ARG` in Dockerfile | Single source of truth; enables version bump |
| Data mount | `data_files/mysql80/` → `/var/lib/mysql` | Version-suffixed, so version changes don't touch existing data |
| Log mount | `logs/mysql/` → `/var/log/mysql` | Logs visible on host, cleanup-able, tail-able |
| Host port | 3306 (1:1 with internal) | Standard MySQL port; no conflict with MariaDB (3307) |
| Auth plugin | `mysql_native_password` | Backwards-compatible with older PHP clients & tools |
| Character set | `utf8mb4` / `utf8mb4_unicode_ci` | Full Unicode support including emoji |
| SQL mode | Strict (`STRICT_TRANS_TABLES`, etc.) | Catch data issues early during dev |
| Healthcheck | `mysqladmin ping -uroot -p<pw>` | Compose waits for actual readiness |
| Timezone | `'+00:00'` inside MySQL | Predictable log timestamps; app-side timezone handled by PHP |

---

## The `mysql/Dockerfile`

```dockerfile
# ============================================================
# MySQL 8.0 container
# Version controlled via .env (MYSQL_VERSION)
# Base image: official MySQL Docker image
# ============================================================
ARG MYSQL_VERSION=8.0
FROM mysql:${MYSQL_VERSION}

# Copy custom config
COPY my.cnf /etc/mysql/conf.d/my.cnf

# Default entrypoint and command are inherited from the base image.
```

**Why the ARG:** Allows the version to be overridden at build time from `.env`. Changing `MYSQL_VERSION=8.4` in `.env` and running `docker compose build mysql` would rebuild using the new base image.

---

## The `mysql/my.cnf` (final version after log fix)

```ini
# ============================================================
# MySQL 8.0 custom configuration
# Local development environment
# ============================================================

[mysqld]
# Character set — modern default
character-set-server = utf8mb4
collation-server     = utf8mb4_unicode_ci

# Default authentication plugin — keeps compatibility with older PHP clients
default_authentication_plugin = mysql_native_password

# SQL mode — strict but not overly aggressive
sql_mode = STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION

# Logging — explicit paths so logs land in the mounted folder
log_error           = /var/log/mysql/error.log
slow_query_log      = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time     = 2
general_log         = 0

# Performance (safe for dev — not tuned for production)
max_connections         = 200
innodb_buffer_pool_size = 256M

# Timezone
default-time-zone = '+00:00'

[client]
default-character-set = utf8mb4

[mysql]
default-character-set = utf8mb4
```

**Key settings explained:**
- **`character-set-server = utf8mb4`** — modern default; supports all Unicode.
- **`default_authentication_plugin = mysql_native_password`** — MySQL 8.0's default (`caching_sha2_password`) breaks older PHP clients/tools. This restores compatibility.
- **`sql_mode = STRICT_TRANS_TABLES, ...`** — strict validation; bad data triggers errors rather than silent truncation.
- **`log_error = /var/log/mysql/error.log`** — explicit path so errors appear on host (the default was stdout).
- **`slow_query_log_file = /var/log/mysql/slow.log`** — same for slow queries.
- **`long_query_time = 2`** — anything over 2 seconds gets logged.
- **`innodb_buffer_pool_size = 256M`** — larger than default (128M), more comfortable for dev.
- **`default-time-zone = '+00:00'`** — forces UTC inside MySQL. App-level timezone is PHP's job.

---

## The `docker-compose.yml` — MySQL service

```yaml
  mysql:
    build:
      context: ./mysql
      args:
        MYSQL_VERSION: ${MYSQL_VERSION}
    image: dev-env-mysql
    container_name: dev-mysql
    restart: unless-stopped
    ports:
      - "${MYSQL_PORT}:3306"
    volumes:
      - ${PROJECTS_ROOT}/docker-env/data_files/mysql80:/var/lib/mysql
      - ${PROJECTS_ROOT}/docker-env/logs/mysql:/var/log/mysql
    environment:
      - MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
      - TZ=${TZ}
    command: --default-authentication-plugin=mysql_native_password
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-uroot", "-p${MYSQL_ROOT_PASSWORD}"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 30s
    networks:
      - dev-net
```

---

## The `.env` additions

```
# --- MySQL ---
MYSQL_VERSION=8.0
MYSQL_PORT=3306
MYSQL_ROOT_PASSWORD=root
```

---

## Step-by-Step Record

### Step 10.1 — Port 3306 check

```bash
sudo lsof -iTCP:3306 -sTCP:LISTEN
```

**Output:** no output (port free).

**Result:** ✅ Pass.

---

### Step 10.2 — Folders created

```bash
mkdir -p ~/Projects01/docker-env/mysql
mkdir -p ~/Projects01/docker-env/data_files/mysql80
mkdir -p ~/Projects01/docker-env/logs/mysql
```

**Result:** ✅ Pass.

---

### Step 10.3 — `.env` extended

Three new variables added: `MYSQL_VERSION`, `MYSQL_PORT`, `MYSQL_ROOT_PASSWORD`.

**Result:** ✅ Pass.

**Note:** `.env` header still says "Phase 3 — Nginx only". Cosmetic. Scheduled for fix in Phase 20.

---

### Step 10.4 — `mysql/Dockerfile` created

Thin wrapper over `mysql:8.0` with `ARG MYSQL_VERSION` and `COPY my.cnf`.

**Result:** ✅ Pass.

---

### Step 10.5 — `mysql/my.cnf` created (v1)

Initial version set character set, auth plugin, SQL mode, slow query log — but **did not set `log_error` or `slow_query_log_file` paths.**

**Result:** ✅ Pass (but log location issue would surface in Step 10.10).

---

### Step 10.6 — Compose extended

Added `mysql` service. `docker compose config` validated.

**Result:** ✅ Pass.

---

### Step 10.7 — Build and start

```bash
docker compose build mysql
docker compose up -d mysql
docker compose ps
```

**Output:**
- Image built in 12.9s (`dev-env-mysql:latest`)
- Container `dev-mysql` Up, status `health: starting`
- Port 3306 mapped

**Result:** ✅ Pass.

---

### Step 10.8 — Wait for healthy, check logs

```bash
docker compose ps
docker compose logs mysql | tail -30
```

**Output:**
- MySQL `Up ... (healthy)` after ~58 seconds
- Logs showed: `MySQL init process done. Ready for start up.`, InnoDB initialized, `ready for connections. Version: '8.0.46' ... port: 3306`

**Result:** ✅ Pass.

**Warnings observed (all benign):**
- `mysql_native_password is deprecated` — we set it intentionally.
- `CA certificate ca.pem is self signed` — normal for official image.
- `'default_authentication_plugin' is deprecated` — MySQL 8.0.34+.
- `Unable to load '/usr/share/zoneinfo/*'` — minimal tzdata in container; we set `default-time-zone = '+00:00'` so tz tables aren't needed.
- `Insecure configuration for --pid-file` — standard for Docker.

---

### Step 10.9 — Data directory on host verified

```bash
ls -la ~/Projects01/docker-env/data_files/mysql80/ | head -30
```

**Output:** ~200 MB of files:
- `ibdata1` (12 MB), `mysql.ibd` (32 MB), `undo_001`, `undo_002` (16 MB each)
- Directories: `mysql/`, `performance_schema/`, `sys/`, `#innodb_redo/`, `#innodb_temp/`
- Binlogs: `binlog.000001`, `binlog.000002`
- SSL certs: `ca.pem`, `server-cert.pem`, `server-key.pem`, etc.
- Slow log inside datadir: `a5aabf69ea71-slow.log` ← **signaled the log-location problem**

**Result:** ✅ Pass — bind mount works; data is physically on the host.

---

### Step 10.10 — Log folder inspection — discovered issue

```bash
ls -la ~/Projects01/docker-env/logs/mysql/
```

**Output:** folder empty.

**Diagnosis:** The official MySQL image logs to **stdout** by default. Also, slow log and general log default to the **data directory**, not `/var/log/mysql/`.

**Result:** ⚠️ Pass with design note.

---

### Step 10.11 — `my.cnf` updated (v2) — log paths

Added:
```ini
log_error           = /var/log/mysql/error.log
slow_query_log_file = /var/log/mysql/slow.log
```

**Result:** ✅ Pass.

---

### Step 10.12 — Rebuild and restart MySQL

```bash
docker compose build mysql      # 1.1s (only my.cnf layer)
docker compose up -d mysql
```

Container restarted. Startup logs (initial entrypoint messages) still appeared on stdout, then MySQL server logs started flowing to `error.log` on host.

**Result:** ✅ Pass.

---

### Step 10.13 — Logs on host verified

```bash
docker compose ps mysql
ls -la ~/Projects01/docker-env/logs/mysql/
cat ~/Projects01/docker-env/logs/mysql/error.log
```

**Output:**
- MySQL `Up About a minute (healthy)`
- `error.log` — 1900 bytes
- `slow.log` — 181 bytes (header only; no slow queries yet)
- `error.log` content shows full startup sequence including `ready for connections. Version: '8.0.46' ... port: 3306`

**Result:** ✅ Pass — log redirection works.

---

### Step 10.14 — MySQL accessible from inside container

```bash
docker compose exec mysql mysql -uroot -proot -e "SELECT VERSION();"
```

**Output:**
```
+-----------+
| VERSION() |
+-----------+
| 8.0.46    |
+-----------+
```

**Note:** `mysql: [Warning] Using a password on the command line interface can be insecure.` is expected when passing `-p<password>`.

**Result:** ✅ Pass.

---

### Step 10.15 — MySQL not on host; port reachable

```bash
which mysql           # → mysql not found
nc -zv 127.0.0.1 3306 # → succeeded
```

**Result:** ✅ Pass.

**Note:** No `mysql` client on the host. GUI tools (TablePlus, Sequel Ace, DBeaver) can connect via `127.0.0.1:3306`. Native `mysql` client is optional: `brew install mysql-client`.

---

### Step 10.16 — Data persistence test

**Part A — Create DB, table, insert row:**

```bash
docker compose exec mysql mysql -uroot -proot -e "
CREATE DATABASE IF NOT EXISTS persistence_test;
USE persistence_test;
CREATE TABLE IF NOT EXISTS t (id INT PRIMARY KEY AUTO_INCREMENT, note VARCHAR(100));
INSERT INTO t (note) VALUES ('before restart');
SELECT * FROM t;
"
```

**Output:**
```
+----+----------------+
| id | note           |
+----+----------------+
|  1 | before restart |
+----+----------------+
```

**Part B — Restart MySQL:**

```bash
docker compose restart mysql
docker compose ps mysql
```

**Output:** `dev-mysql` Up, `(healthy)` after ~7 seconds.

**Part C — Verify row survived:**

```bash
docker compose exec mysql mysql -uroot -proot -e "
USE persistence_test;
SELECT * FROM t;
"
```

**Output:**
```
+----+----------------+
| id | note           |
+----+----------------+
|  1 | before restart |
+----+----------------+
```

**Result:** ✅ **Pass — data persistence proven.** Data written to MySQL survives container restart because it lives in `data_files/mysql80/` on the host.

---

### Step 10.17 — Clean up test database

```bash
docker compose exec mysql mysql -uroot -proot -e "DROP DATABASE persistence_test;"
docker compose exec mysql mysql -uroot -proot -e "SHOW DATABASES;"
```

**Output:**
```
+--------------------+
| Database           |
+--------------------+
| information_schema |
| mysql              |
| performance_schema |
| sys                |
+--------------------+
```

**Result:** ✅ Pass.

---

## Phase 10 Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | Port 3306 free before start | ✅ |
| 2 | Folders created | ✅ |
| 3 | `.env` extended | ✅ |
| 4 | `mysql/Dockerfile` created | ✅ |
| 5 | `mysql/my.cnf` created | ✅ |
| 6 | Compose extended with mysql service | ✅ |
| 7 | Image built cleanly | ✅ |
| 8 | Container healthy | ✅ |
| 9 | MySQL 8.0.46 version confirmed | ✅ |
| 10 | Data directory populated on host | ✅ |
| 11 | Log location fixed (file paths explicit) | ✅ |
| 12 | `error.log` + `slow.log` on host | ✅ |
| 13 | MySQL CLI accessible from container | ✅ |
| 14 | Port 3306 reachable from host | ✅ |
| 15 | Data survives container restart | ✅ |
| 16 | Test database cleaned up | ✅ |

**Phase 10 result: ✅ PASS**

---

## Carry-Forward Notes for Future Phases

1. **MySQL is reachable by other containers** via hostname `mysql` on `dev-env_dev-net`:
   - Host: `mysql`
   - Port: `3306`
   - User: `root`
   - Password: from `.env` (`root`)

2. **MySQL is reachable from the host** at `127.0.0.1:3306`. GUI clients connect directly.

3. **PHP connection string (will be used in later phases):**
   ```php
   $pdo = new PDO('mysql:host=mysql;port=3306;dbname=mydb;charset=utf8mb4', 'root', 'root');
   ```

4. **Data lives at** `~/Projects01/docker-env/data_files/mysql80/`. Version-suffixed. Bumping `MYSQL_VERSION` in `.env` and rebuilding would create `data_files/mysql84/` (or similar) — 8.0 data stays untouched.

5. **Logs live at** `~/Projects01/docker-env/logs/mysql/`:
   - `error.log` — server messages, warnings, errors
   - `slow.log` — queries taking >2 seconds

6. **Deprecated warnings (all harmless, expected):**
   - `mysql_native_password is deprecated` — MySQL 8.0.34+ notice.
   - `'default_authentication_plugin' is deprecated` — will become `authentication_policy` in future.
   - `--skip-host-cache` deprecated — vendor flag.
   - `Setting lower_case_table_names=2` — macOS filesystem is case-insensitive; MySQL adapts.
   - `CA certificate ca.pem is self signed` — auto-generated CA for TLS connections.
   - `Unable to load '/usr/share/zoneinfo/*'` — minimal tzdata; not needed due to `default-time-zone` setting.
   - `Insecure configuration for --pid-file` — standard for official Docker image.

7. **Version-suffixed data folder pattern** — MariaDB, PostgreSQL, MongoDB follow the same:
   - `data_files/mariadb11/`
   - `data_files/postgres15/`
   - `data_files/mongodb8/`
   - `data_files/redis/` (unsuffixed per earlier decision)

8. **Container names and ports (state after Phase 10):**

   | Container | Host port | Internal port |
   |---|---|---|
   | `dev-nginx` | 80, 443 | 80, 443 |
   | `dev-apache2` | 8080, 8443 | 8080, 8443 |
   | `dev-php-fpm84` | — | 9000 |
   | `dev-php-fpm74` | — | 9000 |
   | `dev-mysql` | **3306** | 3306 |

9. **Healthcheck timing:** Fresh start → ~30 seconds to `healthy` (init). Restart → ~7 seconds.

10. **Backup strategy placeholder** (Phase 19): `backup_data/` will be mounted at `/backup` in MySQL. Dumps via `mysqldump -uroot -proot <db> > /backup/<db>.sql` land on the host. Not yet wired.

11. **MySQL server version:** 8.0.46 (the latest 8.0.x as of Phase 10).

12. **Image ID:** `dev-env-mysql:latest` — build again if `my.cnf` changes (~1 second).

---

## Files and System Changes in Phase 10

**Files created:**

```
~/Projects01/docker-env/mysql/Dockerfile
~/Projects01/docker-env/mysql/my.cnf
~/Projects01/docker-env/data_files/mysql80/      (folder — populated by MySQL)
~/Projects01/docker-env/logs/mysql/              (folder — populated by MySQL)
```

**Files modified:**

```
~/Projects01/docker-env/.env                    (MySQL variables added)
~/Projects01/docker-env/docker-compose.yml      (mysql service added)
~/Projects01/docker-env/mysql/my.cnf            (log paths added in Step 10.11)
```

**Docker objects created:**

- Image: `dev-env-mysql:latest`
- Container: `dev-mysql` (with healthcheck)
- Volume: bind mount `data_files/mysql80` → `/var/lib/mysql`
- Volume: bind mount `logs/mysql` → `/var/log/mysql`

---

## Rollback Procedure

To undo Phase 10:

```bash
cd ~/Projects01/docker-env
docker compose down mysql
docker rmi dev-env-mysql:latest
rm -rf ~/Projects01/docker-env/mysql
rm -rf ~/Projects01/docker-env/data_files/mysql80
rm -rf ~/Projects01/docker-env/logs/mysql
# Remove MySQL variables from .env
# Remove mysql service from docker-compose.yml
```

**Warning:** `rm -rf data_files/mysql80` permanently deletes all MySQL data. Only do this if you intend to wipe the database.

---

## Next Phase

**Phase 11 — MariaDB 11**

Goal: add MariaDB as a second database engine, mirroring the MySQL pattern, but on a different host port (3307) so both can run simultaneously.

Phase 11 will:
- Create `docker-env/mariadb/Dockerfile` + `my.cnf`
- Extend `docker-compose.yml` with `mariadb` service
- Data on host: `data_files/mariadb11/`
- Logs on host: `logs/mariadb/`
- Verify connectivity from CLI and (in later phases) from PHP
- Prove MariaDB and MySQL can run side-by-side without conflict

Phase 11 begins once this document is saved and confirmed.

---

**End of phase_10.md**