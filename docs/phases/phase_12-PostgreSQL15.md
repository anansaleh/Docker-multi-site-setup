# Phase 12 — PostgreSQL 15

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM)
**Host project root:** `~/Projects01/`
**Date completed:** 2026-10-15
**Status:** ✅ Complete — all checks passed

---

## Objective

Add PostgreSQL 15 as the third database engine, running side by side with MySQL 8.0 and MariaDB 11.

**This phase proves the multi-database pattern generalizes** to a fundamentally different DB engine (PostgreSQL is not MySQL-family). PostgreSQL uses different env var names, defaults, data layout, CLI, and config approach.

---

## Final State After Phase 12

| Component | Details |
|---|---|
| Container name | `dev-postgres` |
| Image | `dev-env-postgres:latest` (built FROM `postgres:15`) |
| PostgreSQL version | 15.19 (Debian 13 base) |
| Host port | 5432 |
| Internal port | 5432 |
| Data directory (host) | `~/Projects01/docker-env/data_files/postgres15/` |
| Logs (host) | `~/Projects01/docker-env/logs/postgres/` |
| Superuser | `postgres` (default), password `postgres` |
| Default database | `postgres` |
| Character encoding | UTF-8 (locale `en_US.utf8`) |
| Timezone | UTC inside PostgreSQL |
| Healthcheck | `pg_isready -U postgres -d postgres` |
| Network | `dev-env_dev-net` |

---

## What We Built

```
~/Projects01/docker-env/
├── data_files/
│   ├── mysql80/                    (Phase 10 — untouched)
│   ├── mariadb11/                  (Phase 11 — untouched)
│   └── postgres15/                 (new — PostgreSQL data)
├── logs/
│   ├── mysql/                      (Phase 10)
│   ├── mariadb/                    (Phase 11)
│   └── postgres/                   (new)
├── postgres/
│   └── Dockerfile                  (new — minimal, Option A)
└── docker-compose.yml              (extended: postgres service)
```

---

## Key Design Decision — Option A (`-c` flags in compose)

PostgreSQL's config file (`postgresql.conf`) normally lives **inside the data directory** (`$PGDATA/postgresql.conf`), not in a system location. This makes the "COPY a config file" pattern (used by MySQL and MariaDB) awkward for PostgreSQL.

**Two approaches were considered:**

- **Option A:** Pass config overrides as `-c` command-line flags in `docker-compose.yml`.
- **Option B:** Provide a `postgresql.conf` file via Dockerfile `COPY` or mounted volume.

**Option A was chosen** because:
1. It's the standard pattern in Docker/Kubernetes production PostgreSQL setups.
2. All settings are visible in one place (`docker-compose.yml`).
3. No entrypoint-timing hacks or wrapper scripts.
4. Postgres writes our `-c` overrides to `postgresql.auto.conf` in the data dir — so they persist correctly.
5. Covers ~95% of config needs (everything we need for dev).

**Option B would have been:**
- More complex (fighting the entrypoint timing — config must be in place before initdb).
- Scattered (config file lives in the data dir, which is version-suffixed).
- Rarely used in practice.

**Result:** Clean, minimal Dockerfile, all config in compose.

---

## Important Differences From MySQL/MariaDB

| Aspect | MySQL/MariaDB | PostgreSQL |
|---|---|---|
| Env var for password | `MYSQL_ROOT_PASSWORD` / `MARIADB_ROOT_PASSWORD` | `POSTGRES_PASSWORD` |
| Default superuser | `root` | `postgres` |
| Default database | (none — must create) | `postgres` |
| Data dir | `/var/lib/mysql` | `/var/lib/postgresql/data` |
| CLI | `mysql` / `mariadb` | `psql` |
| Config file | `my.cnf` (mounted) | `postgresql.conf` (in data dir) |
| Config approach | Mount `my.cnf` | `-c` flags in command |
| Data dir size (fresh) | ~200 MB (MySQL), ~317 MB (MariaDB) | ~40–80 MB |
| Healthcheck tool | `mysqladmin ping` / `healthcheck.sh` | `pg_isready` |
| Password warning on CLI | Yes (MySQL), No (MariaDB) | No |

---

## The `postgres/Dockerfile`

```dockerfile
# ============================================================
# PostgreSQL 15 container
# Version controlled via .env (POSTGRES_VERSION)
# Base image: official PostgreSQL Docker image
#
# Config overrides are passed via `-c` flags in docker-compose.yml.
# No custom config file is copied — PostgreSQL's defaults are kept,
# and only specific settings are overridden at container start.
# ============================================================
ARG POSTGRES_VERSION=15
FROM postgres:${POSTGRES_VERSION}

# Default entrypoint and command are inherited from the base image.
```

**This is the smallest Dockerfile in the environment.** Just selects the version. All configuration lives in compose.

---

## The `docker-compose.yml` — PostgreSQL service

```yaml
  postgres:
    build:
      context: ./postgres
      args:
        POSTGRES_VERSION: ${POSTGRES_VERSION}
    image: dev-env-postgres
    container_name: dev-postgres
    restart: unless-stopped
    ports:
      - "${POSTGRES_PORT}:5432"
    volumes:
      - ${PROJECTS_ROOT}/docker-env/data_files/postgres15:/var/lib/postgresql/data
      - ${PROJECTS_ROOT}/docker-env/logs/postgres:/var/log/postgresql
    environment:
      - POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
      - POSTGRES_USER=${POSTGRES_USER}
      - POSTGRES_DB=${POSTGRES_DB}
      - TZ=${TZ}
    command:
      - postgres
      - -c
      - listen_addresses=*
      - -c
      - max_connections=200
      - -c
      - shared_buffers=256MB
      - -c
      - logging_collector=on
      - -c
      - log_directory=/var/log/postgresql
      - -c
      - log_filename=postgresql-%Y-%m-%d.log
      - -c
      - log_rotation_age=1d
      - -c
      - log_rotation_size=100MB
      - -c
      - log_min_duration_statement=2000
      - -c
      - timezone=UTC
      - -c
      - log_timezone=UTC
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${POSTGRES_USER} -d ${POSTGRES_DB}"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 30s
    networks:
      - dev-net
```

### The `-c` flags explained

| Flag | Value | Purpose |
|---|---|---|
| `listen_addresses` | `*` | Accept connections from any interface (needed for containers + host) |
| `max_connections` | `200` | Match MySQL/MariaDB setting |
| `shared_buffers` | `256MB` | Match MySQL's `innodb_buffer_pool_size` |
| `logging_collector` | `on` | Enable file-based log collection |
| `log_directory` | `/var/log/postgresql` | Where logs go (matches mount) |
| `log_filename` | `postgresql-%Y-%m-%d.log` | Daily log rotation pattern |
| `log_rotation_age` | `1d` | Rotate daily |
| `log_rotation_size` | `100MB` | Rotate at 100 MB (whichever first) |
| `log_min_duration_statement` | `2000` | Log queries over 2s (matches MySQL/MariaDB slow log threshold) |
| `timezone` | `UTC` | Database timezone |
| `log_timezone` | `UTC` | Log timestamps in UTC |

**PostgreSQL saves these to `postgresql.auto.conf` in the data dir on first start.** They persist across restarts automatically.

---

## The `.env` additions

```
# --- PostgreSQL ---
POSTGRES_VERSION=15
POSTGRES_PORT=5432
POSTGRES_PASSWORD=postgres
POSTGRES_USER=postgres
POSTGRES_DB=postgres
```

**Note:** The user/db values are PostgreSQL's defaults (`postgres`/`postgres`). Changed only to make them explicit in `.env` and easy to modify later.

---

## Step-by-Step Record

### Step 12.1 — Port 5432 check

```bash
sudo lsof -iTCP:5432 -sTCP:LISTEN
```

**Output:** no output (port free).

**Result:** ✅ Pass.

---

### Step 12.2 — Folders created

```bash
mkdir -p ~/Projects01/docker-env/postgres
mkdir -p ~/Projects01/docker-env/data_files/postgres15
mkdir -p ~/Projects01/docker-env/logs/postgres
```

**Result:** ✅ Pass. All three database engines now have version-suffixed data folders.

---

### Step 12.3 — `.env` extended

Five new variables: `POSTGRES_VERSION`, `POSTGRES_PORT`, `POSTGRES_PASSWORD`, `POSTGRES_USER`, `POSTGRES_DB`.

**Result:** ✅ Pass.

---

### Step 12.4 — `postgres/Dockerfile` created

Minimal (Option A). Just `FROM postgres:15`, no config copy.

**Result:** ✅ Pass.

---

### Step 12.5 — Compose extended

Added `postgres` service with all `-c` config flags. `docker compose config` validated:
- Seven services total.
- PostgreSQL block correct: build arg, image, port, mounts, env, command array, healthcheck.
- No variable substitution errors.

**Result:** ✅ Pass.

---

### Step 12.6 — Build

```bash
docker compose build postgres
```

**Output:**
```
[+] Building 9.6s (8/8) FINISHED
 => [1/1] FROM docker.io/library/postgres:15@sha256:c961aa287d8698297cb26cdfadfbe9fd2cbaf77e53cfffe9636e8d8a1e4d842c
 => => naming to docker.io/library/dev-env-postgres:latest
```

**Result:** ✅ Image built in 9.6s.

---

### Step 12.7 — Start

```bash
docker compose up -d postgres
docker compose ps
```

**Output:**
- `dev-postgres` Up (health: starting)
- Port mapping: `0.0.0.0:5432->5432/tcp`
- All other containers healthy

**Result:** ✅ Pass.

---

### Step 12.8 — Container healthy

```bash
docker compose ps postgres
docker compose logs postgres | tail -40
```

**Output (after ~20 seconds):**
```
NAME           IMAGE              STATUS                    PORTS
dev-postgres   dev-env-postgres   Up 21 seconds (healthy)   0.0.0.0:5432->5432/tcp
```

**Key log lines:**
```
The database cluster will be initialized with locale "en_US.utf8".
The default database encoding has accordingly been set to "UTF8".
fixing permissions on existing directory /var/lib/postgresql/data ... ok
initdb: warning: enabling "trust" authentication for local connections
LOG:  redirecting log output to logging collector process
LOG:  Future log output will appear in directory "/var/log/postgresql".
PostgreSQL init process complete; ready for start up.
```

**Notable:**
- `initdb` warnings about `trust` auth are only for the bootstrap phase.
- **Our `logging_collector=on` is working** — logs go to files.

**Result:** ✅ Pass. PostgreSQL 15.19 healthy.

---

### Step 12.9 — Data directory on host verified

```bash
ls -la ~/Projects01/docker-env/data_files/postgres15/ | head -25
```

**Output:** Standard PG data layout:

| Entry | Purpose |
|---|---|
| `PG_VERSION` | Version stamp |
| `base/` | Database files |
| `global/` | Shared system catalogs |
| `pg_wal/` | Write-ahead logs |
| `pg_hba.conf` | Auth config |
| `pg_ident.conf` | User mapping |
| `postgresql.auto.conf` | Runtime overrides (our `-c` flags) |
| `postgresql.conf` | Main config |
| `pg_multixact/`, `pg_subtrans/`, `pg_stat/`, `pg_logical/`, etc. | PG system dirs |
| `current_logfiles` | Points to active log file |

**Permissions:** `drwx------` (700) — PostgreSQL requires strict perms on the data dir. Docker Desktop handles UID mapping transparently.

**Result:** ✅ Pass. Data is on host.

---

### Step 12.10 — CLI access from container

```bash
docker compose exec postgres psql -U postgres -c "SELECT version();"
```

**Output:**
```
 PostgreSQL 15.19 (Debian 15.19-1.pgdg13+2) on aarch64-unknown-linux-gnu, compiled by gcc (Debian 14.2.0-19) 14.2.0, 64-bit
```

**Notable:** No password prompt — local Unix socket uses `trust` auth.

**Result:** ✅ Pass.

---

### Step 12.11 — Port reachable + cross-container connection

**Part 1 — Port check from host:**
```bash
nc -zv 127.0.0.1 5432
```
**Output:** `Connection to 127.0.0.1 port 5432 [tcp/postgresql] succeeded!`

**Part 2 — Cross-container connection test:**
```bash
docker run --rm -e PGPASSWORD=postgres postgres:15 psql -h host.docker.internal -U postgres -d postgres -c "SELECT current_database(), current_user;"
```

**Output:**
```
 current_database | current_user 
------------------+--------------
 postgres         | postgres
(1 row)
```

**Result:** ✅ **Pass.** Proves password auth works over TCP from outside the container — exactly what PHP will use.

---

### Step 12.12 — Data persistence test (create)

```bash
docker compose exec postgres psql -U postgres -c "CREATE DATABASE persistence_test;"
docker compose exec postgres psql -U postgres -d persistence_test -c "
CREATE TABLE t (id SERIAL PRIMARY KEY, note VARCHAR(100));
INSERT INTO t (note) VALUES ('postgres before restart');
SELECT * FROM t;
"
```

**Output:**
```
CREATE DATABASE
CREATE TABLE
INSERT 0 1
 id |          note           
----+-------------------------
  1 | postgres before restart
(1 row)
```

**Result:** ✅ Pass.

---

### Step 12.13 — Restart + verify persistence

```bash
docker compose restart postgres
docker compose ps postgres
docker compose exec postgres psql -U postgres -d persistence_test -c "SELECT * FROM t;"
```

**Output:**
```
NAME           STATUS
dev-postgres   Up 7 seconds (healthy)

 id |          note           
----+-------------------------
  1 | postgres before restart
(1 row)
```

**Result:** ✅ **Pass — data survived restart.**

---

### Step 12.14 — Logs on host

```bash
ls -la ~/Projects01/docker-env/logs/postgres/
cat ~/Projects01/docker-env/logs/postgres/postgresql-*.log | tail -20
```

**Output:**
- File: `postgresql-2026-10-15.log` (3119 bytes) — **filename pattern matches our `log_filename=postgresql-%Y-%m-%d.log` setting.**
- Content includes:
  ```
  LOG:  starting PostgreSQL 15.19 ...
  LOG:  listening on IPv4 address "0.0.0.0", port 5432
  LOG:  listening on IPv6 address "::", port 5432
  LOG:  listening on Unix socket "/var/run/postgresql/.s.PGSQL.5432"
  LOG:  database system was shut down at ...
  LOG:  database system is ready to accept connections
  LOG:  received fast shutdown request
  LOG:  checkpoint starting: shutdown immediate
  LOG:  checkpoint complete: wrote 980 buffers ...
  ```

**Result:** ✅ Pass. Logs on host, timestamps in UTC.

---

### Step 12.15 — All three databases running

```bash
docker compose ps mysql mariadb postgres
```

**Output:**
```
NAME           STATUS                    PORTS
dev-mariadb    Up 20 minutes (healthy)   0.0.0.0:3307->3306/tcp
dev-mysql      Up 48 minutes (healthy)   0.0.0.0:3306->3306/tcp
dev-postgres   Up 57 seconds (healthy)   0.0.0.0:5432->5432/tcp
```

**Result:** ✅ Pass. All three engines healthy and independent.

---

### Step 12.16 — Clean up test database

```bash
docker compose exec postgres psql -U postgres -c "DROP DATABASE persistence_test;"
docker compose exec postgres psql -U postgres -c "SELECT datname FROM pg_database WHERE datistemplate = false;"
```

**Output:**
```
DROP DATABASE
 datname  
----------
 postgres
(1 row)
```

**Result:** ✅ Pass.

---

## Phase 12 Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | Port 5432 free before start | ✅ |
| 2 | Folders created | ✅ |
| 3 | `.env` extended | ✅ |
| 4 | `postgres/Dockerfile` created (Option A) | ✅ |
| 5 | Compose extended with postgres service | ✅ |
| 6 | Image built | ✅ |
| 7 | Container healthy (PostgreSQL 15.19) | ✅ |
| 8 | Data dir populated on host | ✅ |
| 9 | CLI accessible from container | ✅ |
| 10 | Port reachable from host | ✅ |
| 11 | Cross-container connection works | ✅ |
| 12 | Data persistence — create | ✅ |
| 13 | Data persistence — survives restart | ✅ |
| 14 | Logs on host | ✅ |
| 15 | All 3 databases running simultaneously | ✅ |
| 16 | Test DB cleaned up | ✅ |

**Phase 12 result: ✅ PASS**

---

## Carry-Forward Notes for Future Phases

### Multi-Database State (after Phase 12)

| Engine | Container | Host Port | Internal Port | Version | Data folder | Logs folder |
|---|---|---|---|---|---|---|
| MySQL | `dev-mysql` | 3306 | 3306 | 8.0.46 | `data_files/mysql80/` | `logs/mysql/` |
| MariaDB | `dev-mariadb` | 3307 | 3306 | 11.8.9 | `data_files/mariadb11/` | `logs/mariadb/` |
| PostgreSQL | `dev-postgres` | 5432 | 5432 | 15.19 | `data_files/postgres15/` | `logs/postgres/` |

### Connection Details from Other Containers (PHP, workspace)

| Engine | Hostname | Port | User | Password |
|---|---|---|---|---|
| MySQL | `mysql` | 3306 | `root` | `root` |
| MariaDB | `mariadb` | 3306 | `root` | `root` |
| PostgreSQL | `postgres` | 5432 | `postgres` | `postgres` |

### Connection Details from Host (GUI tools)

| Engine | Host | Port |
|---|---|---|
| MySQL | `127.0.0.1` | 3306 |
| MariaDB | `127.0.0.1` | 3307 |
| PostgreSQL | `127.0.0.1` | 5432 |

### PHP Connection Strings (reference)

```php
// MySQL
new PDO('mysql:host=mysql;port=3306;dbname=mydb;charset=utf8mb4', 'root', 'root');

// MariaDB
new PDO('mysql:host=mariadb;port=3306;dbname=mydb;charset=utf8mb4', 'root', 'root');

// PostgreSQL
new PDO('pgsql:host=postgres;port=5432;dbname=mydb', 'postgres', 'postgres');
```

### Key PostgreSQL Characteristics

1. **Env vars**: `POSTGRES_PASSWORD`, `POSTGRES_USER`, `POSTGRES_DB`.
2. **Default superuser**: `postgres` (not `root`).
3. **Default DB**: `postgres`.
4. **Data dir**: `/var/lib/postgresql/data`.
5. **CLI**: `psql`.
6. **Config approach**: Option A — `-c` flags in `command:`.
7. **Config persistence**: PG writes `-c` overrides to `postgresql.auto.conf` in the data dir.
8. **Healthcheck**: `pg_isready`.
9. **Data dir size**: ~40–80 MB fresh (much smaller than MySQL/MariaDB).
10. **Log file naming**: `postgresql-YYYY-MM-DD.log` (daily rotation).
11. **Log timezone**: UTC (via `log_timezone=UTC`).
12. **Authentication**: `trust` for Unix socket (from inside container); password auth (`scram-sha-256` / `md5`) for TCP.
13. **No `my.cnf` equivalent**: PG config is `postgresql.conf` (in data dir) + `postgresql.auto.conf` (runtime overrides).
14. **`initdb` warnings are informational**: "trust for local connections" is only during bootstrap; final `pg_hba.conf` is set up after.

### Pattern Confirmed for MongoDB (Phase 13)

The recipe we've now applied three times:
1. Create `docker-env/<engine>/` folder with `Dockerfile`.
2. Create `data_files/<engine><version>/` for persistent data.
3. Create `logs/<engine>/` for host-visible logs.
4. Add `<ENGINE>_VERSION`, `<ENGINE>_PORT`, `<ENGINE>_PASSWORD` to `.env`.
5. Add service to `docker-compose.yml` with:
   - Build context + args
   - Image name
   - Container name
   - Port mapping
   - Data + log volume mounts
   - Environment variables
   - Healthcheck
   - Network `dev-net`
6. Verify:
   - Container healthy
   - Data dir on host
   - CLI access from container
   - Port reachable from host
   - Data persistence across restart
   - Logs on host

### Deprecated Warnings You'll Keep Seeing

- **MySQL:** `mysql_native_password is deprecated` (intentional for compat); `--skip-host-cache deprecated` (vendor flag).
- **MariaDB:** `create_uring failed: falling back to libaio` (io_uring unavailable in Docker Desktop); `wsrep-provider is disabled` (Galera plugin).
- **PostgreSQL:** None significant.
- All benign and expected in containerized dev environments.

---

## Files and System Changes in Phase 12

**Files created:**

```
~/Projects01/docker-env/postgres/Dockerfile
~/Projects01/docker-env/data_files/postgres15/      (populated by PostgreSQL)
~/Projects01/docker-env/logs/postgres/              (populated by PostgreSQL)
```

**Files modified:**

```
~/Projects01/docker-env/.env                    (PostgreSQL variables added)
~/Projects01/docker-env/docker-compose.yml      (postgres service added)
```

**Docker objects created:**

- Image: `dev-env-postgres:latest`
- Container: `dev-postgres` (with healthcheck)

---

## Rollback Procedure

To undo Phase 12:

```bash
cd ~/Projects01/docker-env
docker compose down postgres
docker rmi dev-env-postgres:latest
rm -rf ~/Projects01/docker-env/postgres
rm -rf ~/Projects01/docker-env/data_files/postgres15
rm -rf ~/Projects01/docker-env/logs/postgres
# Remove PostgreSQL variables from .env
# Remove postgres service from docker-compose.yml
```

**Warning:** `rm -rf data_files/postgres15` permanently deletes all PostgreSQL data.

---
## Summary of Phase 12:

- ✅ 16/16 checks passed
- ✅ PostgreSQL 15.19 running alongside MySQL 8.0.46 and MariaDB 11.8.9
- ✅ Data on host at data_files/postgres15/ — isolated
- ✅ Logs on host at logs/postgres/ with daily rotation
- ✅ Data persistence proven
- ✅ Cross-container connection works with password auth
- ✅ Option A chosen for config (-c flags in compose) — clean and standard
- ✅ Three database engines now running side by side

---

## Next Phase

**Phase 13 — MongoDB 8**

Goal: add MongoDB as the fourth database engine, following the same pattern.

**Key differences from relational DBs:**
- MongoDB uses a document model (no tables/SQL) — CLI is `mongosh`, not `mysql`/`psql`.
- Env vars: `MONGO_INITDB_ROOT_USERNAME` / `MONGO_INITDB_ROOT_PASSWORD`.
- Default port: 27017 (does not conflict).
- Auth: `--auth` flag required on first start.
- Data directory: `/data/db` (not `/var/lib/*`).
- Logs: configurable.
- Healthcheck: `mongosh --eval "db.adminCommand('ping')"` or similar.

Phase 13 will:
- Create `docker-env/mongodb/Dockerfile`
- Extend `docker-compose.yml` with `mongodb` service
- Data on host: `data_files/mongodb8/`
- Logs on host: `logs/mongodb/`
- Host port: 27017
- Verify connectivity and persistence

Phase 13 begins once this document is saved and confirmed.

---

**End of phase_12.md**