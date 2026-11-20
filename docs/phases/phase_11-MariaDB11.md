# Phase 11 — MariaDB 11

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM)
**Host project root:** `~/Projects01/`
**Date completed:** 2026-10-15
**Status:** ✅ Complete — all checks passed

---

## Objective

Add MariaDB 11 as the second database engine, running side by side with MySQL 8.0. Same pattern as MySQL, but:

- **Host port 3307** (MySQL takes 3306)
- **Data folder** `data_files/mariadb11/` (version-suffixed, isolated from MySQL)
- **Logs folder** `logs/mariadb/`

**This phase validates the pattern** — PostgreSQL (Phase 12) and MongoDB (Phase 13) follow the exact same recipe.

---

## Final State After Phase 11

| Component | Details |
|---|---|
| Container name | `dev-mariadb` |
| Image | `dev-env-mariadb:latest` (built FROM `mariadb:11` + `my.cnf`) |
| MariaDB version | 11.8.9-MariaDB (Ubuntu 24.04 base) |
| Host port | 3307 |
| Internal port | 3306 |
| Data directory (host) | `~/Projects01/docker-env/data_files/mariadb11/` |
| Logs (host) | `~/Projects01/docker-env/logs/mariadb/` |
| Root password | `root` (from `.env`) |
| Character set | `utf8mb4` / `utf8mb4_unicode_ci` |
| Timezone | UTC inside MariaDB |
| Healthcheck | `healthcheck.sh --connect --innodb_initialized` |
| Network | `dev-env_dev-net` |

---

## What We Built

```
~/Projects01/docker-env/
├── data_files/
│   ├── mysql80/                    (from Phase 10 — untouched)
│   └── mariadb11/                  (new — MariaDB data)
├── logs/
│   ├── mysql/                      (from Phase 10)
│   └── mariadb/                    (new)
├── mariadb/
│   ├── Dockerfile
│   └── my.cnf
└── docker-compose.yml              (extended: mariadb service)
```

---

## Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Image | Official `mariadb:11` | Well-maintained, Ubuntu-based (very stable) |
| Version control | `MARIADB_VERSION=11` in `.env` | Single source of truth |
| Data mount | `data_files/mariadb11/` → `/var/lib/mysql` | Version-suffixed; isolated from MySQL |
| Log mount | `logs/mariadb/` → `/var/log/mysql` | Host-visible logs; separate from MySQL's |
| Host port | **3307** (distinct from MySQL 3306) | Both engines run simultaneously |
| Internal port | 3306 (standard) | No conflict — different container |
| Auth plugin | Default (no override) | MariaDB's default is already client-compatible |
| Character set | `utf8mb4` | Same as MySQL |
| Healthcheck | `healthcheck.sh --connect --innodb_initialized` | MariaDB's official healthcheck script |
| SQL mode | Strict (same as MySQL) | Catch data issues early |
| Timezone | `'+00:00'` | Consistent with MySQL |

**Key insight:** The only host-side difference from MySQL is the port. MariaDB listens on 3306 internally (standard), but we publish it to 3307 on the host. Inside the Docker network, each engine is reachable by its service name (`mysql` or `mariadb`) on port 3306.

---

## The `mariadb/Dockerfile`

```dockerfile
# ============================================================
# MariaDB 11 container
# Version controlled via .env (MARIADB_VERSION)
# Base image: official MariaDB Docker image
# ============================================================
ARG MARIADB_VERSION=11
FROM mariadb:${MARIADB_VERSION}

# Copy custom config
COPY my.cnf /etc/mysql/conf.d/my.cnf

# Default entrypoint and command are inherited from the base image.
```

---

## The `mariadb/my.cnf`

```ini
# ============================================================
# MariaDB 11 custom configuration
# Local development environment
# ============================================================

[mysqld]
# Character set — modern default
character-set-server = utf8mb4
collation-server     = utf8mb4_unicode_ci

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

**Key differences from MySQL's `my.cnf`:**

1. **No `default_authentication_plugin = mysql_native_password`** — MariaDB doesn't have MySQL's caching_sha2_password issue; its default is already compatible with older PHP clients. Adding the line would be ignored or produce a warning.

2. **Same log paths** (`/var/log/mysql/...`) — MariaDB uses the same directory convention. The container's mount points to a *different host folder* (`logs/mariadb/`), so no cross-contamination.

3. **Everything else identical** — same charset, SQL mode, performance settings.

---

## The `docker-compose.yml` — MariaDB service

```yaml
  mariadb:
    build:
      context: ./mariadb
      args:
        MARIADB_VERSION: ${MARIADB_VERSION}
    image: dev-env-mariadb
    container_name: dev-mariadb
    restart: unless-stopped
    ports:
      - "${MARIADB_PORT}:3306"
    volumes:
      - ${PROJECTS_ROOT}/docker-env/data_files/mariadb11:/var/lib/mysql
      - ${PROJECTS_ROOT}/docker-env/logs/mariadb:/var/log/mysql
    environment:
      - MARIADB_ROOT_PASSWORD=${MARIADB_ROOT_PASSWORD}
      - TZ=${TZ}
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 30s
    networks:
      - dev-net
```

**Key points:**

- **Port mapping:** `${MARIADB_PORT}:3306` → `3307:3306` (host 3307 → container 3306).
- **Environment variable:** `MARIADB_ROOT_PASSWORD` (not `MYSQL_ROOT_PASSWORD`) — the official MariaDB image expects this name.
- **Healthcheck:** uses MariaDB's official `healthcheck.sh` script with `--connect --innodb_initialized` — more reliable than `mysqladmin ping` for MariaDB.

---

## The `.env` additions

```
# --- MariaDB ---
MARIADB_VERSION=11
MARIADB_PORT=3307
MARIADB_ROOT_PASSWORD=root
```

---

## Step-by-Step Record

### Step 11.1 — Port 3307 check

```bash
sudo lsof -iTCP:3307 -sTCP:LISTEN
```

**Output:** no output (port free).

**Result:** ✅ Pass.

---

### Step 11.2 — Folders created

```bash
mkdir -p ~/Projects01/docker-env/mariadb
mkdir -p ~/Projects01/docker-env/data_files/mariadb11
mkdir -p ~/Projects01/docker-env/logs/mariadb
```

**Result:** ✅ Pass. `mariadb11/` sits alongside `mysql80/` — version-suffixed isolation confirmed.

---

### Step 11.3 — `.env` extended

Three new variables added: `MARIADB_VERSION`, `MARIADB_PORT`, `MARIADB_ROOT_PASSWORD`.

**Result:** ✅ Pass.

---

### Step 11.4 — `mariadb/Dockerfile` created

Thin wrapper over `mariadb:11` with `ARG MARIADB_VERSION` and `COPY my.cnf`.

**Result:** ✅ Pass.

---

### Step 11.5 — `mariadb/my.cnf` created

Charset, SQL mode, log paths, performance — same as MySQL, minus the auth plugin line.

**Result:** ✅ Pass.

---

### Step 11.6 — Compose extended

Added `mariadb` service. `docker compose config` validated:

- Six services: nginx, apache2, php-fpm84, php-fpm74, mysql, mariadb.
- MariaDB port mapping: `3307:3306`.
- MariaDB data mount: `data_files/mariadb11/` → `/var/lib/mysql`.
- MariaDB log mount: `logs/mariadb/` → `/var/log/mysql`.
- MariaDB healthcheck present.
- MySQL block unchanged (port 3306).

**Result:** ✅ Pass. No port collision between MySQL (3306) and MariaDB (3307).

---

### Step 11.7 — Build

```bash
docker compose build mariadb
```

**Output:**
```
[+] Building 8.0s (10/10) FINISHED
 => [1/2] FROM docker.io/library/mariadb:11@sha256:6422478cb8e159f080fb1d8ccf65101e26fe51385787fde7d16c3b165a331f15
 => [2/2] COPY my.cnf /etc/mysql/conf.d/my.cnf
 => => naming to docker.io/library/dev-env-mariadb:latest
```

**Result:** ✅ Pass. Image built in 8.1s.

---

### Step 11.8 — Start

```bash
docker compose up -d mariadb
docker compose ps
```

**Output:**
- `dev-mariadb` Up (health: starting)
- Port mapping: `0.0.0.0:3307->3306/tcp`
- `dev-mysql` unchanged — still healthy on 3306
- All other containers unaffected

**Result:** ✅ Pass.

---

### Step 11.9 — Container healthy

```bash
docker compose ps mariadb
docker compose logs mariadb | tail -30
```

**Output (after ~30 seconds):**
```
NAME          IMAGE             COMMAND                  STATUS                    PORTS
dev-mariadb   dev-env-mariadb   "docker-entrypoint.s…"   Up 30 seconds (healthy)   0.0.0.0:3307->3306/tcp
```

**Logs:**
```
[Note] [Entrypoint]: Entrypoint script for MariaDB Server 1:11.8.9+maria~ubu2404 started.
[Warn] [Entrypoint]: /sys/fs/cgroup///memory.pressure not writable, functionality unavailable to MariaDB
[Note] [Entrypoint]: Initializing database files
[Note] [Entrypoint]: Database files initialized
[Note] [Entrypoint]: Starting temporary server
[Note] [Entrypoint]: Temporary server started.
[Note] [Entrypoint]: MariaDB init process done. Ready for start up.
```

**Warnings observed (all benign):**
- `/sys/fs/cgroup///memory.pressure not writable` — Docker Desktop cgroup setup doesn't expose memory pressure; cosmetic.
- `Setting lower_case_table_names=2 because file system for /var/lib/mysql/ is case insensitive` — macOS filesystem case-insensitivity; MariaDB adapts.

**Notably absent from MariaDB logs (present in MySQL):**
- No `mysql_native_password is deprecated` warning.
- No `--skip-host-cache deprecated` warning.

**Result:** ✅ Pass. MariaDB 11.8.9 healthy.

---

### Step 11.10 — Data directory on host verified

```bash
ls -la ~/Projects01/docker-env/data_files/mariadb11/ | head -20
```

**Output:** ~317 MB with these key files:

| File | Purpose |
|---|---|
| `aria_log_control`, `aria_log.00000001` | Aria storage engine (MariaDB-specific) |
| `ddl_recovery.log` | DDL crash recovery |
| `ib_buffer_pool`, `ibdata1`, `ibtmp1` | InnoDB files |
| `ib_logfile0` (100 MB) | InnoDB redo log — larger than MySQL's |
| `mariadb_upgrade_info` | Version tracking |
| `multi-master.info` | Replication config |
| `mysql/`, `performance_schema/`, `sys/` | System schemas |
| `tc.log` | Transaction coordinator log |
| `undo001`, `undo002`, `undo003` | 3 undo tablespaces (MySQL has 2) |
| `.my-healthcheck.cnf` | Auto-written by healthcheck.sh |

**Result:** ✅ Pass. Data is on host, isolated from MySQL's.

---

### Step 11.11 — MariaDB CLI accessible from container

```bash
docker compose exec mariadb mariadb -uroot -proot -e "SELECT VERSION();"
```

**Output:**
```
+----------------------------+
| VERSION()                  |
+----------------------------+
| 11.8.9-MariaDB-ubu2404-log |
+----------------------------+
```

**Notable:** No "password on command line is insecure" warning (unlike MySQL).

**Result:** ✅ Pass.

---

### Step 11.12 — Port 3307 reachable from host

```bash
nc -zv 127.0.0.1 3307
```

**Output:**
```
Connection to 127.0.0.1 port 3307 [tcp/opsession-prxy] succeeded!
```

**Result:** ✅ Pass. Port reachable.

---

### Step 11.13 — Data persistence test (Part A: create)

```bash
docker compose exec mariadb mariadb -uroot -proot -e "
CREATE DATABASE IF NOT EXISTS persistence_test;
USE persistence_test;
CREATE TABLE IF NOT EXISTS t (id INT PRIMARY KEY AUTO_INCREMENT, note VARCHAR(100));
INSERT INTO t (note) VALUES ('mariadb before restart');
SELECT * FROM t;
"
```

**Output:**
```
+----+------------------------+
| id | note                   |
+----+------------------------+
|  1 | mariadb before restart |
+----+------------------------+
```

**Result:** ✅ Pass.

---

### Step 11.14 — MySQL unaffected

```bash
docker compose ps mysql mariadb
docker compose exec mysql mysql -uroot -proot -e "SELECT VERSION();"
```

**Output:**
```
NAME          STATUS                    PORTS
dev-mariadb   Up 2 minutes (healthy)    0.0.0.0:3307->3306/tcp
dev-mysql     Up 27 minutes (healthy)   0.0.0.0:3306->3306/tcp

+-----------+
| VERSION() |
+-----------+
| 8.0.46    |
+-----------+
```

**Result:** ✅ Pass. Both engines healthy; MySQL unaffected by MariaDB coming online.

---

### Step 11.15 — Data persistence test (Parts B & C: restart)

```bash
docker compose restart mariadb
docker compose ps mariadb
docker compose exec mariadb mariadb -uroot -proot -e "
USE persistence_test;
SELECT * FROM t;
"
```

**Output after restart:**
```
NAME          STATUS
dev-mariadb   Up 12 seconds (healthy)

+----+------------------------+
| id | note                   |
+----+------------------------+
|  1 | mariadb before restart |
+----+------------------------+
```

**Result:** ✅ **Pass — data survived restart.**

Data persistence is confirmed: MariaDB's data lived in `data_files/mariadb11/` on the host; the container restart didn't touch it.

---

### Step 11.16 — Logs on host verified

```bash
ls -la ~/Projects01/docker-env/logs/mariadb/
cat ~/Projects01/docker-env/logs/mariadb/error.log | tail -20
```

**Output:**
- `error.log` — 8043 bytes
- `slow.log` — 525 bytes
- Content includes full InnoDB init, buffer pool loaded, "ready for connections. Version: '11.8.9-MariaDB-ubu2404-log' ... port: 3306"

**Notable log lines (all informational):**
- `create_uring failed: falling back to libaio` — io_uring unavailable in Docker Desktop; MariaDB falls back to libaio. Harmless.
- `Plugin 'FEEDBACK' is disabled` — MariaDB telemetry plugin (disabled by default in containers).
- `Plugin 'wsrep-provider' is disabled` — Galera Cluster plugin; not used.

**Result:** ✅ Pass. Logs on host; log paths from `my.cnf` honored.

---

### Step 11.17 — Clean up test database

```bash
docker compose exec mariadb mariadb -uroot -proot -e "DROP DATABASE persistence_test;"
docker compose exec mariadb mariadb -uroot -proot -e "SHOW DATABASES;"
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

## Phase 11 Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | Port 3307 free before start | ✅ |
| 2 | Folders created | ✅ |
| 3 | `.env` extended | ✅ |
| 4 | `mariadb/Dockerfile` created | ✅ |
| 5 | `mariadb/my.cnf` created | ✅ |
| 6 | Compose extended | ✅ |
| 7 | Image built | ✅ |
| 8 | Container healthy (MariaDB 11.8.9) | ✅ |
| 9 | Data directory on host | ✅ |
| 10 | CLI accessible from container | ✅ |
| 11 | Port 3307 reachable from host | ✅ |
| 12 | Data persistence — create | ✅ |
| 13 | MySQL unaffected | ✅ |
| 14 | Data persistence — survives restart | ✅ |
| 15 | Logs on host | ✅ |
| 16 | Test DB cleaned up | ✅ |

**Phase 11 result: ✅ PASS**

---

## Carry-Forward Notes for Future Phases

1. **Two databases running simultaneously:**

   | Container | Host port | Internal port | Version |
   |---|---|---|---|
   | `dev-mysql` | 3306 | 3306 | 8.0.46 |
   | `dev-mariadb` | 3307 | 3306 | 11.8.9-MariaDB |

2. **Connection details from other containers (PHP, workspace):**
   - MySQL: hostname `mysql`, port `3306`
   - MariaDB: hostname `mariadb`, port `3306`
   - Credentials: `root` / `root` for both

3. **Connection details from host (GUI tools):**
   - MySQL: `127.0.0.1:3306`
   - MariaDB: `127.0.0.1:3307`

4. **Data locations (host):**
   - MySQL: `~/Projects01/docker-env/data_files/mysql80/`
   - MariaDB: `~/Projects01/docker-env/data_files/mariadb11/`

5. **Log locations (host):**
   - MySQL: `~/Projects01/docker-env/logs/mysql/`
   - MariaDB: `~/Projects01/docker-env/logs/mariadb/`

6. **Version-suffixed folder pattern confirmed.** MariaDB and MySQL have zero data cross-contamination — each is isolated to its own folder.

7. **Healthcheck differences:**
   - MySQL: `mysqladmin ping -uroot -p<pw>`
   - MariaDB: `healthcheck.sh --connect --innodb_initialized` (official MariaDB healthcheck script)
   - MariaDB's script auto-writes `.my-healthcheck.cnf` to the datadir for credential handling.

8. **MariaDB CLI naming:** The binary is `mariadb`, but `mysql` also works as a symlink. Same for `mariadb-dump` / `mysqldump`.

9. **MariaDB has no password warning** when passing `-p<password>` on the CLI. Cleaner output than MySQL.

10. **MariaDB-specific log lines (informational only):**
    - `create_uring failed: falling back to libaio` — io_uring unavailable in Docker Desktop.
    - `Plugin 'wsrep-provider' is disabled` — Galera Cluster plugin.
    - `Plugin 'FEEDBACK' is disabled` — telemetry plugin.

11. **MariaDB data dir is bigger than MySQL's** (~317 MB vs ~200 MB) because of:
    - 3 undo tablespaces (MySQL has 2)
    - Aria engine files
    - 100 MB InnoDB redo log file (`ib_logfile0`)

12. **Container names:** `dev-mysql`, `dev-mariadb`.

13. **Image IDs:** `dev-env-mysql:latest`, `dev-env-mariadb:latest`.

14. **Pattern confirmed for the remaining DB engines.** PostgreSQL (Phase 12) and MongoDB (Phase 13) will follow this recipe:
    - Version-suffixed data folder
    - Distinct host port
    - Own log folder
    - Own Dockerfile with `ARG` for version
    - Own healthcheck
    - Own `.env` variables

15. **Backup placeholders for Phase 19:**
    - MySQL: `mysqldump -uroot -p<root> <db> > /backup/<db>.sql`
    - MariaDB: `mariadb-dump -uroot -p<root> <db> > /backup/<db>.sql`

---

## Files and System Changes in Phase 11

**Files created:**

```
~/Projects01/docker-env/mariadb/Dockerfile
~/Projects01/docker-env/mariadb/my.cnf
~/Projects01/docker-env/data_files/mariadb11/      (populated by MariaDB)
~/Projects01/docker-env/logs/mariadb/              (populated by MariaDB)
```

**Files modified:**

```
~/Projects01/docker-env/.env                    (MariaDB variables added)
~/Projects01/docker-env/docker-compose.yml      (mariadb service added)
```

**Docker objects created:**

- Image: `dev-env-mariadb:latest`
- Container: `dev-mariadb` (with healthcheck)
- Volume mounts: `data_files/mariadb11/` → `/var/lib/mysql`, `logs/mariadb/` → `/var/log/mysql`

**No changes to** MySQL, Nginx, Apache, or PHP containers.

---

## Rollback Procedure

To undo Phase 11:

```bash
cd ~/Projects01/docker-env
docker compose down mariadb
docker rmi dev-env-mariadb:latest
rm -rf ~/Projects01/docker-env/mariadb
rm -rf ~/Projects01/docker-env/data_files/mariadb11
rm -rf ~/Projects01/docker-env/logs/mariadb
# Remove MariaDB variables from .env
# Remove mariadb service from docker-compose.yml
```

**Warning:** `rm -rf data_files/mariadb11` permanently deletes all MariaDB data.

---
## Summary of Phase 11:

- ✅ 16/16 checks passed
- ✅ MariaDB 11.8.9 running alongside MySQL 8.0.46
- ✅ Data on host at data_files/mariadb11/ — isolated from MySQL
- ✅ Logs on host at logs/mariadb/
- ✅ Data persistence proven (survives restart)
- ✅ MySQL unaffected by MariaDB
- ✅ Ports: MySQL on 3306, MariaDB on 3307 — no conflict
- ✅ All MariaDB-specific log messages explained

*Two databases running side by side*. The multi-database pattern is now proven.

---

## Next Phase

**Phase 12 — PostgreSQL 15**

Goal: add PostgreSQL as the third database engine, following the same pattern as MySQL and MariaDB.

Phase 12 will:
- Create `docker-env/postgres/Dockerfile` + config
- Extend `docker-compose.yml` with `postgres` service
- Data on host: `data_files/postgres15/`
- Logs on host: `logs/postgres/`
- Host port: 5432 (PostgreSQL's standard)
- Verify connectivity from CLI and prove persistence

**Note on differences from MySQL/MariaDB:**
- PostgreSQL uses different env vars (`POSTGRES_PASSWORD`, not `MYSQL_/MARIADB_ROOT_PASSWORD`)
- The default database is `postgres` (not `mysql`)
- The default user is `postgres` (not `root`)
- CLI is `psql`, not `mysql` / `mariadb`
- Config files are `postgresql.conf` and `pg_hba.conf` (different structure)
- Data dir default is `/var/lib/postgresql/data` (not `/var/lib/mysql`)

Phase 12 begins once this document is saved and confirmed.

---

**End of phase_11.md**