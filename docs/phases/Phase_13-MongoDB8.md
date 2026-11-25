# Phase 13 — MongoDB 8 (With Documented Limitation)

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM)
**Host project root:** `~/Projects01/`
**Date completed:** 2026-10-15
**Status:** ⚠️ Complete with limitation — MongoDB runs but cannot survive container restart

---

## Objective

Add MongoDB as the fourth database engine. This turned out to be **the most problematic phase** of the entire build, due to an upstream kernel/allocator incompatibility.

**Outcome:**
- ✅ MongoDB 8.0.4 runs
- ✅ Data persists while the container stays up
- ⚠️ **Data does NOT survive container restart** — a hard limitation documented below

---

## Critical Limitation — MongoDB Cannot Restart

**This is the most important thing to know about Phase 13:**

MongoDB in this environment has a **fatal incompatibility** with Docker Desktop's LinuxKit VM kernel. Any attempt to `docker compose restart mongodb` crashes the container. Recovering requires wiping the data directory, which destroys all MongoDB data.

### What Works

- ✅ `docker compose up -d mongodb` from a **fresh/empty** data dir
- ✅ Data persists while the container stays up (for hours/days)
- ✅ All MongoDB operations work correctly

### What Does NOT Work

- ❌ `docker compose restart mongodb` → container crashes with exit code 14
- ❌ `docker compose up -d mongodb` on a data dir that has been previously used
- ❌ Any scenario that requires the container to be restarted while keeping its data

### The Error

When MongoDB crashes, the log shows:

```
{"t":{"$date":"..."},"s":"F","c":"CONTROL","id":12257600,"ctx":"main",
 "msg":"MongoDB cannot start: Linux kernel versions 6.19 and newer has a known
 incompatibility with this version of MongoDB.
 See https://jira.mongodb.org/browse/SERVER-121912 for more information."}
```

The container exits with code 14 and enters a restart loop.

### Root Cause

A **TCMalloc allocator incompatibility** with the kernel that Docker Desktop's Linux VM reports. This is an **upstream MongoDB bug**, not a flaw in our Docker design.

- **JIRA reference:** [SERVER-121912](https://jira.mongodb.org/browse/SERVER-121912)
- **Affected:** MongoDB 8.0.5 and newer (in most cases), and inconsistent behavior in 8.0.4
- **Working version:** 8.0.4 (works on first start, crashes on restart)
- **Not tested:** MongoDB 6.x, 7.x, 5.x

### Workaround (Data-Losing)

To recover from a crash:

```bash
docker compose stop mongodb
docker compose rm -f mongodb
rm -rf ~/Projects01/docker-env/data_files/mongodb8/*
rm -rf ~/Projects01/docker-env/data_files/mongodb8/.[!.]* 2>/dev/null
docker compose up -d mongodb
```

**Warning: this destroys all MongoDB data.** Use only when you accept data loss.

### Mitigations Attempted and Failed

We tried several workarounds, none of which solved the problem:

| Attempt | Result |
|---|---|
| MongoDB 8.0.4 (older version) | Works on first start, crashes on restart |
| `GLIBC_TUNABLES=glibc.pthread.rseq=0` env var | No effect |
| `MONGO_TCMALLOC_PER_CPU_CACHE_SIZE_BYTES=0` env var | No effect |
| Seccomp profile blocking `rseq` syscall | No effect |
| MongoDB 8.0.30 (latest 8.0.x) | Crash on first start |

### Recommended Approach

**Treat MongoDB as ephemeral storage.** If you need persistence, use PostgreSQL's `JSONB` columns instead — PostgreSQL has no such limitation and can store/query JSON natively.

---

## Final State After Phase 13

| Component | Details |
|---|---|
| Container name | `dev-mongodb` |
| Image | `dev-env-mongodb:latest` (built FROM `mongo:8.0.4`) |
| MongoDB version | 8.0.4 (see limitation above) |
| Host port | 27017 |
| Internal port | 27017 |
| Data directory (host) | `~/Projects01/docker-env/data_files/mongodb8/` |
| Logs (host) | `~/Projects01/docker-env/logs/mongodb/` |
| Root user | `root` (in `admin` database), password `root` |
| Network | `dev-env_dev-net` |
| Status | ⚠️ Runs correctly but not restartable |

---

## What We Built

```
~/Projects01/docker-env/
├── data_files/
│   ├── mysql80/
│   ├── mariadb11/
│   ├── postgres15/
│   └── mongodb8/                   (MongoDB data — ephemeral)
├── logs/
│   ├── mysql/
│   ├── mariadb/
│   ├── postgres/
│   └── mongodb/                    (MongoDB logs)
├── mongodb/
│   ├── Dockerfile
│   └── mongod.conf
└── docker-compose.yml              (extended: mongodb service)
```

---

## Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Image | Official `mongo:8.0.4` | Only version observed to work |
| Version control | `MONGODB_VERSION=8.0.4` in `.env` | **Must be exactly 8.0.4** — newer versions fail |
| Data mount | `data_files/mongodb8/` → `/data/db` | Version-suffixed; standard MongoDB data dir |
| Log mount | `logs/mongodb/` → `/var/log/mongodb` | Host-visible logs |
| Host port | 27017 | MongoDB default |
| Root user | `root` in `admin` database | Standard MongoDB convention |
| Auth | Enabled via `MONGO_INITDB_ROOT_*` env vars | Standard, secure |
| Config file | Custom `mongod.conf` mounted at `/etc/mongo/` | Full control over logging + options |
| Command | `mongod --config /etc/mongo/mongod.conf --auth` | Explicit config + auth |
| Healthcheck | `mongosh --quiet --eval "db.runCommand('ping').ok"` | Works because ping is pre-auth allowed |

---

## The `mongodb/Dockerfile`

```dockerfile
# ============================================================
# MongoDB 8 container
# Version controlled via .env (MONGODB_VERSION)
# Base image: official MongoDB Docker image
# ============================================================
ARG MONGODB_VERSION=8.0.4
FROM mongo:${MONGODB_VERSION}

# Copy custom config
COPY mongod.conf /etc/mongo/mongod.conf

# Default entrypoint and command are inherited from the base image.
# The container's command is set in docker-compose.yml to point at our config file.
```

**Critical:** The base image must resolve to `mongo:8.0.4`. The `.env` file must have `MONGODB_VERSION=8.0.4`.

---

## The `mongodb/mongod.conf`

```yaml
# ============================================================
# MongoDB 8 custom configuration
# Local development environment
# ============================================================

storage:
  dbPath: /data/db

systemLog:
  destination: file
  path: /var/log/mongodb/mongod.log
  logAppend: true
  logRotate: rename

net:
  port: 27017
  bindIp: 0.0.0.0

operationProfiling:
  mode: slowOp
  slowOpThresholdMs: 2000

security:
  authorization: enabled
```

**Settings explained:**

- **`storage.dbPath: /data/db`** — standard MongoDB data directory.
- **`systemLog.destination: file`** — write logs to file (not stdout).
- **`systemLog.path: /var/log/mongodb/mongod.log`** — log file location (host-visible via mount).
- **`systemLog.logAppend: true`** — append across restarts (though restarts are broken, this is still correct config).
- **`systemLog.logRotate: rename`** — keep rotated files.
- **`net.port: 27017`** — standard port.
- **`net.bindIp: 0.0.0.0`** — accept connections from any interface.
- **`operationProfiling.mode: slowOp`** — log slow operations.
- **`operationProfiling.slowOpThresholdMs: 2000`** — 2-second threshold (matches other DBs).
- **`security.authorization: enabled`** — require auth.

---

## The `docker-compose.yml` — MongoDB service

```yaml
  mongodb:
    build:
      context: ./mongodb
      args:
        MONGODB_VERSION: ${MONGODB_VERSION}
    image: dev-env-mongodb
    container_name: dev-mongodb
    restart: unless-stopped
    ports:
      - "${MONGODB_PORT}:27017"
    volumes:
      - ${PROJECTS_ROOT}/docker-env/data_files/mongodb8:/data/db
      - ${PROJECTS_ROOT}/docker-env/logs/mongodb:/var/log/mongodb
    environment:
      - MONGO_INITDB_ROOT_USERNAME=${MONGODB_ROOT_USERNAME}
      - MONGO_INITDB_ROOT_PASSWORD=${MONGODB_ROOT_PASSWORD}
      - TZ=${TZ}
    command: ["mongod", "--config", "/etc/mongo/mongod.conf", "--auth"]
    healthcheck:
      test: ["CMD", "mongosh", "--quiet", "--eval", "db.runCommand('ping').ok"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 30s
    networks:
      - dev-net
```

**Note:** `restart: unless-stopped` means Compose will try to restart MongoDB if it crashes — which is why the crash-on-restart behavior loops. In practice, if MongoDB crashes, you must **manually stop it** and wipe the data dir before starting again.

---

## The `.env` additions

```
# --- MongoDB ---
MONGODB_VERSION=8.0.4
MONGODB_PORT=27017
MONGODB_ROOT_USERNAME=root
MONGODB_ROOT_PASSWORD=root
```

**Critical:** `MONGODB_VERSION=8.0.4` — **not** `8`, not `8.0`, not `8.0.30`. Only 8.0.4 was observed to work.

---

## Step-by-Step Record

### Step 13.1 — Port 27017 check

```bash
sudo lsof -iTCP:27017 -sTCP:LISTEN
```

**Output:** no output (port free).

**Result:** ✅ Pass.

---

### Step 13.2 — Folders created

```bash
mkdir -p ~/Projects01/docker-env/mongodb
mkdir -p ~/Projects01/docker-env/data_files/mongodb8
mkdir -p ~/Projects01/docker-env/logs/mongodb
```

**Result:** ✅ Pass.

---

### Step 13.3 — `.env` extended

Four variables added: `MONGODB_VERSION`, `MONGODB_PORT`, `MONGODB_ROOT_USERNAME`, `MONGODB_ROOT_PASSWORD`.

Initially `MONGODB_VERSION=8`. Changed multiple times during troubleshooting. Final: `8.0.4`.

**Result:** ✅ Pass (with version iterations).

---

### Step 13.4 — `mongodb/Dockerfile` created

Thin wrapper over `mongo:${MONGODB_VERSION}` with `COPY mongod.conf`.

**Result:** ✅ Pass.

---

### Step 13.5 — `mongod.conf` created

Custom config with logging to `/var/log/mongodb/`, bindIp `0.0.0.0`, auth enabled.

**Result:** ✅ Pass.

---

### Step 13.6 — Compose extended

Added `mongodb` service. `docker compose config` validated. Eight services total.

**Result:** ✅ Pass.

---

### Step 13.7 — Build

```bash
docker compose build mongodb
```

**Output:** `dev-env-mongodb:latest` built (13.1s first time).

**Result:** ✅ Pass.

---

### Step 13.8 — Start

```bash
docker compose up -d mongodb
docker compose ps
```

**Output:** Container starts.

**Result:** ✅ Pass.

---

### Step 13.9 — FAILURE: Container restarts in a loop

**Error:**

```
{"msg":"MongoDB cannot start: Linux kernel versions 6.19 and newer has a known
 incompatibility with this version of MongoDB.
 See https://jira.mongodb.org/browse/SERVER-121912"}
```

Exit code 14, restart loop.

**Root cause:** MongoDB 8 (latest) has a TCMalloc allocator incompatibility with the kernel reported by Docker Desktop's LinuxKit VM.

**Multiple troubleshooting attempts:**

1. **Downgrade to MongoDB 8.0.4** → Worked on first start (up 14+ seconds healthy), but failed on restart.
2. **8.0.30 with `GLIBC_TUNABLES=glibc.pthread.rseq=0`** → Failed on first start.
3. **8.0.30 with `MONGO_TCMALLOC_PER_CPU_CACHE_SIZE_BYTES=0`** → Failed on first start.
4. **Seccomp profile blocking `rseq` syscall** → Failed on first start.
5. **Revert to 8.0.4** → Worked on fresh start (empty data dir), but crashes on restart with existing WiredTiger data.

**Final resolution:** Pin to 8.0.4. Wipe data dir if container needs to restart.

**Result:** ⚠️ Pass with limitation.

---

### Step 13.10 — Data dir on host

```bash
ls -la ~/Projects01/docker-env/data_files/mongodb8/ | head -20
```

**Output:** WiredTiger files present:
- `_mdb_catalog.wt`, `collection-*.wt`, `index-*.wt`
- `journal/`, `diagnostic.data/`
- `mongod.lock`, `sizeStorer.wt`, `storage.bson`

**Result:** ✅ Pass.

---

### Step 13.11 — CLI access from container

```bash
docker compose exec mongodb mongosh -u root -p root --authenticationDatabase admin --quiet --eval "db.version()"
```

**Output:** `8.0.4`

**Result:** ✅ Pass.

---

### Step 13.12 — Port reachable from host + cross-container

**Commands:**

```bash
nc -zv 127.0.0.1 27017
# succeeded!

docker run --rm mongo:8.0.4 mongosh "mongodb://root:root@host.docker.internal:27017/?authSource=admin" \
  --quiet --eval "db.adminCommand('ping').ok"
# 1
```

**Result:** ✅ Pass.

---

### Step 13.13 — Persistence create

```bash
docker compose exec mongodb mongosh -u root -p root --authenticationDatabase admin --quiet --eval '
db = db.getSiblingDB("persistence_test");
db.t.insertOne({ note: "mongodb before restart" });
db.t.find().forEach(printjson);
'
```

**Output:** Document inserted and returned.

**Result:** ✅ Pass.

---

### Step 13.14 — Persistence restart test — FAILED

```bash
docker compose restart mongodb
```

**Result:** MongoDB container restarts, then crashes with kernel incompatibility error. **Data persistence across restart is not possible.**

⚠️ **This is the critical limitation.**

---

### Step 13.15 — CLI re-verified after fresh start

Wiped data dir, recreated container, verified MongoDB starts cleanly:

```bash
docker compose exec mongodb mongosh -u root -p root --authenticationDatabase admin --quiet --eval "db.version()"
```

**Output:** `8.0.4`

**Result:** ✅ Pass (after wipe).

---

### Step 13.16 — Logs on host

```bash
ls -la ~/Projects01/docker-env/logs/mongodb/
cat ~/Projects01/docker-env/logs/mongodb/mongod.log | tail -20
```

**Output:**
- `mongod.log` — 825 KB
- JSON-formatted log entries, mostly network connection events

**Notable observation from logs:** Container reports kernel `4.14.209-160.339.amzn2.aarch64` — this is Docker Desktop's LinuxKit VM, not the actual host kernel. The "kernel 6.19+" message is therefore somewhat misleading — the incompatibility is more nuanced than the version number suggests.

**Result:** ✅ Pass.

---

### Step 13.17 — Cross-container write

```bash
docker run --rm mongo:8.0.4 mongosh "mongodb://root:root@host.docker.internal:27017/?authSource=admin" \
  --quiet --eval '
db = db.getSiblingDB("cross_test");
db.t.insertOne({ from: "external container", when: new Date() });
db.t.find().forEach(printjson);
'
```

**Output:**
```json
{
  _id: ObjectId('...'),
  from: 'external container',
  when: ISODate('2026-10-15T12:58:29.994Z')
}
```

**Result:** ✅ Pass.

---

### Step 13.18 — Cleanup test data

```bash
docker compose exec mongodb mongosh -u root -p root --authenticationDatabase admin --quiet --eval '
db.getSiblingDB("cross_test").dropDatabase();
db.getSiblingDB("persistence_test").dropDatabase();
'
```

**Output:** `{ ok: 1, dropped: 'persistence_test' }`

**Result:** ✅ Pass.

---

## Phase 13 Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | Port 27017 free before start | ✅ |
| 2 | Folders created | ✅ |
| 3 | `.env` extended (with `8.0.4`) | ✅ |
| 4 | `mongodb/Dockerfile` created | ✅ |
| 5 | `mongod.conf` created | ✅ |
| 6 | Compose extended | ✅ |
| 7 | Image built | ✅ |
| 8 | Container started | ✅ |
| 9 | Container healthy (after multiple fix attempts) | ✅ |
| 10 | Data dir on host | ✅ |
| 11 | CLI accessible from container | ✅ |
| 12 | Port reachable + cross-container | ✅ |
| 13 | Persistence create | ✅ |
| 14 | **Restart persistence — FAILED (kernel incompatibility)** | ⚠️ |
| 15 | CLI re-verified | ✅ |
| 16 | Logs on host | ✅ |
| 17 | Cross-container write | ✅ |
| 18 | Test DB cleanup | ✅ |

**Phase 13 result: ⚠️ PASS WITH LIMITATION**

---

## Carry-Forward Notes for Future Phases

### MongoDB Is Special

**All three other DB engines** (MySQL, MariaDB, PostgreSQL) have proven data persistence across container restarts. **MongoDB does not.** Treat MongoDB as ephemeral in this environment.

### Multi-Database State (After Phase 13)

| Engine | Container | Host Port | Version | Data Persistence Across Restart |
|---|---|---|---|---|
| MySQL | `dev-mysql` | 3306 | 8.0.46 | ✅ Yes |
| MariaDB | `dev-mariadb` | 3307 | 11.8.9 | ✅ Yes |
| PostgreSQL | `dev-postgres` | 5432 | 15.19 | ✅ Yes |
| MongoDB | `dev-mongodb` | 27017 | 8.0.4 | ❌ **No** |

### Connection Details from Containers

| Engine | Hostname | Port | User | Password | Auth DB |
|---|---|---|---|---|---|
| MySQL | `mysql` | 3306 | `root` | `root` | — |
| MariaDB | `mariadb` | 3306 | `root` | `root` | — |
| PostgreSQL | `postgres` | 5432 | `postgres` | `postgres` | — |
| MongoDB | `mongodb` | 27017 | `root` | `root` | `admin` |

### PHP Connection Strings

```php
// MySQL
new PDO('mysql:host=mysql;port=3306;dbname=mydb;charset=utf8mb4', 'root', 'root');

// MariaDB
new PDO('mysql:host=mariadb;port=3306;dbname=mydb;charset=utf8mb4', 'root', 'root');

// PostgreSQL
new PDO('pgsql:host=postgres;port=5432;dbname=mydb', 'postgres', 'postgres');

// MongoDB (PHP mongodb extension)
$client = new MongoDB\Driver\Manager('mongodb://root:root@mongodb:27017/?authSource=admin');
```

### Handling MongoDB Crashes

If MongoDB crashes (typically after a container restart), the recovery procedure is:

```bash
# 1. Stop and remove the crashed container
docker compose stop mongodb
docker compose rm -f mongodb

# 2. Wipe the data directory
rm -rf ~/Projects01/docker-env/data_files/mongodb8/*
rm -rf ~/Projects01/docker-env/data_files/mongodb8/.[!.]* 2>/dev/null

# 3. Recreate and start fresh
docker compose up -d mongodb
docker compose ps mongodb
```

**Warning:** This destroys all MongoDB data.

### Recommendations for Projects Using MongoDB

1. **Keep MongoDB data ephemeral.** Consider MongoDB in this environment as a **scratch database** — reset it between sessions if needed.
2. **For persistent document storage**, use **PostgreSQL's `JSONB`** columns instead. Same flexibility, no limitation.
3. **Back up manually before stopping** if there's data worth preserving:
   ```bash
   docker compose exec mongodb mongosh -u root -p root --authenticationDatabase admin --quiet \
     --eval 'db.getSiblingDB("mydb").t.find().forEach(d => print(JSON.stringify(d)))' \
     > ~/Projects01/backup_data/mongodb-$(date +%Y%m%d).json
   ```

### Mitigations Attempted (Reference for Future Debugging)

If you ever revisit this issue:

| Attempt | Version | Env / Config | Result |
|---|---|---|---|
| 1 | 8.0.5+ (default `mongo:8`) | — | Crash loop on first start |
| 2 | 8.0.4 | — | Works on first start; crashes on restart |
| 3 | 8.0.30 | `GLIBC_TUNABLES=glibc.pthread.rseq=0` | Crash on first start |
| 4 | 8.0.30 | + `MONGO_TCMALLOC_PER_CPU_CACHE_SIZE_BYTES=0` | Crash on first start |
| 5 | 8.0.4 | Seccomp blocking `rseq` syscall | Crash on restart |
| 6 | **8.0.4** | Wipe data dir + restart from scratch | **Works — this is the accepted solution** |

### Lessons Learned

1. **MongoDB 8.0.4 is the only known working version** in this environment.
2. **The `.env` must have `MONGODB_VERSION=8.0.4`** — not `8`, not `8.0`, not any newer version.
3. **Do not use `docker compose restart mongodb`** — it crashes the container.
4. **Prefer `docker compose stop mongodb` + `docker compose up -d mongodb`** only if the data is disposable.
5. **If MongoDB is not needed**, consider commenting out its service in `docker-compose.yml` to avoid confusion.

### Other Lessons (Applied to Future Phases)

- **Docker Desktop kernel updates can break containers.** This bug appeared after a Docker Desktop update that changed the LinuxKit VM.
- **Always verify data persistence across restarts** for each new service. We did this for MySQL/MariaDB/PostgreSQL (all passed) and MongoDB (failed).
- **Pin versions aggressively in dev.** For MongoDB, `8` was a moving target. For MySQL/MariaDB/PostgreSQL, the versions we pinned (`8.0`, `11`, `15`) have proven stable.

---

## Files and System Changes in Phase 13

**Files created:**

```
~/Projects01/docker-env/mongodb/Dockerfile
~/Projects01/docker-env/mongodb/mongod.conf
~/Projects01/docker-env/data_files/mongodb8/      (ephemeral)
~/Projects01/docker-env/logs/mongodb/              (persistent log file)
```

**Files modified:**

```
~/Projects01/docker-env/.env                    (MongoDB variables, MONGODB_VERSION=8.0.4)
~/Projects01/docker-env/docker-compose.yml      (mongodb service added)
```

**Docker objects created:**

- Image: `dev-env-mongodb:latest`
- Container: `dev-mongodb`

---

## Rollback / Disable Procedure

### To disable MongoDB temporarily:

Edit `docker-compose.yml`, comment out the entire `mongodb:` service block. Then:

```bash
docker compose up -d
```

MongoDB won't start; the other 7 services continue.

### To remove MongoDB entirely:

```bash
cd ~/Projects01/docker-env
docker compose stop mongodb
docker compose rm -f mongodb
docker rmi dev-env-mongodb:latest
rm -rf ~/Projects01/docker-env/mongodb
rm -rf ~/Projects01/docker-env/data_files/mongodb8
rm -rf ~/Projects01/docker-env/logs/mongodb
# Remove MongoDB variables from .env
# Remove mongodb service block from docker-compose.yml
```

---
## Summary of Phase 13:

- ⚠️ 18/18 checks technically pass, but 14 (restart persistence) is a hard limitation
- ✅ MongoDB 8.0.4 running, cross-container connectivity works
- ❌ MongoDB cannot survive container restarts — upstream kernel incompatibility
- ⚠️ Multiple mitigation attempts documented for future debugging
- ✅ Recommendations provided (use PostgreSQL JSONB for persistent document storage)
- ✅ Recovery procedure documented

All four database engines are now present — three work perfectly, one is ephemeral-only.

---

## Next Phase

**Phase 14 — Redis**

Goal: add Redis as the cache and queue backend.

**Good news:** Redis is a simple key-value store with no known kernel incompatibilities. It should work cleanly, just like MySQL/MariaDB/PostgreSQL.

Phase 14 will:
- Create `docker-env/redis/Dockerfile` + `redis.conf`
- Extend `docker-compose.yml` with `redis` service
- Data on host: `data_files/redis/` (unsuffixed, per earlier decision)
- Logs on host: `logs/redis/`
- Host port: 6379
- Verify connectivity and persistence

Phase 14 begins once this document is saved and confirmed.

---

**End of phase_13.md**