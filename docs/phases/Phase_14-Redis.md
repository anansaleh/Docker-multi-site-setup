# Phase 14 — Redis

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM)
**Host project root:** `~/Projects01/`
**Date completed:** 2026-10-17
**Status:** ✅ Complete — all checks passed

---

## Objective

Add Redis as the cache and queue backend. Redis is the simplest database service in the environment — a key-value store with AOF persistence, password auth, and no kernel compatibility issues.

**Expected outcome:** Redis runs reliably, survives restarts, and is ready for PHP applications to use as cache or queue.

**Actual outcome:** ✅ All expectations met. This was the smoothest database phase.

---

## Final State After Phase 14

| Component | Details |
|---|---|
| Container name | `dev-redis` |
| Image | `dev-env-redis:latest` (built FROM `redis:7`) |
| Redis version | 7.4.11 |
| Host port | 6379 |
| Internal port | 6379 |
| Data directory (host) | `~/Projects01/docker-env/data_files/redis/` |
| Logs (host) | `~/Projects01/docker-env/logs/redis/` (empty — using stderr) |
| Password | `root` (from `.env`) |
| Persistence | AOF (append-only file) |
| Accessible from | PHP containers (hostname `redis`), host (`127.0.0.1:6379`) |
| Network | `dev-env_dev-net` |
| Healthcheck | `redis-cli -a <pw> --no-auth-warning ping` |

---

## What We Built

```
~/Projects01/docker-env/
├── data_files/
│   └── redis/                     (unsuffixed — per design decision)
│       └── appendonlydir/         (AOF files)
├── logs/
│   └── redis/                     (empty — stderr logging)
├── redis/
│   ├── Dockerfile
│   └── redis.conf
└── docker-compose.yml             (extended: redis service)
```

---

## Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Image | Official `redis:7` | Lightweight (~19 MB), well-maintained |
| Version control | `REDIS_VERSION=7` in `.env` | Latest 7.x stable |
| Data mount | `data_files/redis/` → `/data` | **Unsuffixed** — per original design (Redis data format is version-tolerant) |
| Log destination | `stderr` | `docker compose logs redis` works; simpler than file logging |
| Host port | 6379 | Redis default |
| Password | `root` (from `.env`) | Dev credentials |
| Persistence | **AOF** (append-only file) | Durable — logs every write, survives restart with data intact |
| Auth | Via `--requirepass` on command line | Password comes from `.env`, not hardcoded in `redis.conf` |
| Dangerous commands | `FLUSHALL` and `FLUSHDB` disabled | Safety measure — prevents accidental data wipes |
| Healthcheck | `redis-cli -a <pw> ping` | Proves connectivity + auth |

**Why unsuffixed data folder:** Redis's RDB/AOF formats are typically forward-compatible across minor versions. Unlike MySQL/MariaDB/PostgreSQL where a version change means format incompatibility, Redis 7.4 data can be loaded by Redis 7.2 or 8.0 in most cases. Since the RDB format is designed for cross-version upgrades, the version-suffixed pattern isn't strictly needed. However, if we ever bump major versions (e.g., 7 → 8), a data migration may be required — see `phase_14.md` carry-forward notes.

---

## The `redis/Dockerfile`

```dockerfile
# ============================================================
# Redis 7 container
# Version controlled via .env (REDIS_VERSION)
# Base image: official Redis Docker image
# ============================================================
ARG REDIS_VERSION=7
FROM redis:${REDIS_VERSION}

# Copy custom config
COPY redis.conf /usr/local/etc/redis/redis.conf

# Default entrypoint and command are inherited from the base image.
```

---

## The `redis/redis.conf`

```ini
# ============================================================
# Redis 7 custom configuration
# Local development environment
# ============================================================

# Network
bind 0.0.0.0
port 6379
protected-mode no
tcp-backlog 511
timeout 0
tcp-keepalive 300

# General
daemonize no
supervised no
pidfile /var/run/redis_6379.pid
loglevel notice
logfile ""
databases 16

# Snapshotting (RDB)
# Periodic snapshots — fast to load but may lose recent writes on crash
save 900 1
save 300 10
save 60 10000
stop-writes-on-bgsave-error yes
rdbcompression yes
rdbchecksum yes
dbfilename dump.rdb
dir /data

# Replication
replica-serve-stale-data yes
replica-read-only yes
repl-diskless-sync yes
repl-diskless-sync-delay 5

# Security
# Password is injected via --requirepass on the command line (from .env)
# Do NOT set requirepass here — we override it in docker-compose command.
rename-command FLUSHALL ""
rename-command FLUSHDB ""

# Clients
maxclients 10000

# Append Only File (AOF)
# More durable than RDB — logs every write, survives restart with data intact
appendonly yes
appendfilename "appendonly.aof"
appendfsync everysec
no-appendfsync-on-rewrite no
auto-aof-rewrite-percentage 100
auto-aof-rewrite-min-size 64mb

# Lua scripting
lua-time-limit 5000

# Slow log
slowlog-log-slower-than 10000
slowlog-max-len 128
```

**Key settings explained:**

| Setting | Value | Purpose |
|---|---|---|
| `bind 0.0.0.0` | All interfaces | Accept from containers + host |
| `port 6379` | Standard | Redis default |
| `protected-mode no` | Disabled | Required since we bind to all interfaces; auth via password instead |
| `daemonize no` | Foreground | Required for Docker |
| `dir /data` | `/data` | Data directory (matches mount) |
| `save 900 1` etc. | RDB snapshots | Every 15 min if ≥1 key changed, etc. |
| `appendonly yes` | AOF enabled | **Primary persistence — survives restart** |
| `appendfsync everysec` | 1/sec | Balance between durability and speed |
| `rename-command FLUSHALL ""` | Disabled | Prevents accidental wipe of all data |
| `rename-command FLUSHDB ""` | Disabled | Same |
| `logfile ""` | Empty | Log to stdout (visible via `docker compose logs`) |
| `loglevel notice` | Moderate | Good balance |

**Security note:** The password (`REDIS_PASSWORD=root`) is **not in this file**. It's passed via `--requirepass` on the Docker command line. This keeps the committed config file password-free.

---

## The `docker-compose.yml` — Redis Service

```yaml
  redis:
    build:
      context: ./redis
      args:
        REDIS_VERSION: ${REDIS_VERSION}
    image: dev-env-redis
    container_name: dev-redis
    restart: unless-stopped
    ports:
      - "${REDIS_PORT}:6379"
    volumes:
      - ${PROJECTS_ROOT}/docker-env/data_files/redis:/data
      - ${PROJECTS_ROOT}/docker-env/logs/redis:/var/log/redis
    environment:
      - TZ=${TZ}
    command: >
      redis-server
      /usr/local/etc/redis/redis.conf
      --requirepass ${REDIS_PASSWORD}
    healthcheck:
      test: ["CMD", "redis-cli", "-a", "${REDIS_PASSWORD}", "--no-auth-warning", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 10s
    networks:
      - dev-net
```

**Command note:** The YAML `>` fold combines into:
```
redis-server /usr/local/etc/redis/redis.conf --requirepass root
```

---

## The `.env` Additions

```
# --- Redis ---
REDIS_VERSION=7
REDIS_PORT=6379
REDIS_PASSWORD=root
```

---

## Step-by-Step Record

### Step 14.1 — Port 6379 check

```bash
sudo lsof -iTCP:6379 -sTCP:LISTEN
```

**Output:** no output (port free).

**Result:** ✅ Pass.

---

### Step 14.2 — Folders created

```bash
mkdir -p ~/Projects01/docker-env/redis
mkdir -p ~/Projects01/docker-env/data_files/redis
mkdir -p ~/Projects01/docker-env/logs/redis
```

**Result:** ✅ Pass.

---

### Step 14.3 — `.env` extended

Three variables added: `REDIS_VERSION`, `REDIS_PORT`, `REDIS_PASSWORD`. File now has 54 lines total.

**Result:** ✅ Pass.

---

### Step 14.4 — `redis/Dockerfile` created

Thin wrapper with `ARG REDIS_VERSION=7` and `COPY redis.conf`.

**Result:** ✅ Pass.

---

### Step 14.5 — `redis/redis.conf` created

Config with AOF persistence, disabled `FLUSHALL`/`FLUSHDB`, network settings.

**Result:** ✅ Pass.

---

### Step 14.6 — Compose extended

Added `redis` service. `docker compose config` validated. **Ten services total** now.

**Result:** ✅ Pass.

---

### Step 14.7 — Build

```bash
docker compose build redis
```

**Output:** Base image `redis:7` pulled (~19 MB). Image `dev-env-redis:latest` built in 3.4s.

**Result:** ✅ Pass. This is the smallest DB image in the environment.

---

### Step 14.8 — Start

```bash
docker compose up -d redis
docker compose ps
```

**Output:** Container started. Port `6379:6379` mapped. All 9 other services running.

**Result:** ✅ Pass.

---

### Step 14.9 — Container healthy

```bash
docker compose ps redis
docker compose logs redis | tail -20
```

**Output:** `Up 43 seconds (healthy)` — Redis 7.4.11 running.

**Key log lines:**
```
Redis version=7.4.11
Configuration loaded
Running mode=standalone, port=6379.
Server initialized
Creating AOF base file appendonly.aof.1.base.rdb on server start
Creating AOF incr file appendonly.aof.1.incr.aof on server start
Ready to accept connections tcp
```

**One warning (cosmetic):**
```
1:M ... # Failed to write PID file: Permission denied
```
Cause: `redis.conf` has `pidfile /var/run/redis_6379.pid`, but the Redis user can't write there. **Impact: none** — Docker tracks the process directly, PID file is only used by init systems.
**Fix scheduled for Phase 20:** change to `pidfile /tmp/redis_6379.pid`.

**Result:** ✅ Pass.

---

### Step 14.10 — Data directory on host

```bash
ls -la ~/Projects01/docker-env/data_files/redis/
```

**Output:** `appendonlydir/` folder present (Redis 7 uses a directory for AOF, not a single file).

**Result:** ✅ Pass.

---

### Step 14.11 — CLI access from container

```bash
docker compose exec redis redis-cli -a root --no-auth-warning ping
docker compose exec redis redis-cli -a root --no-auth-warning info server | head -15
```

**Output:**
- `PONG` — auth works
- `redis_version:7.4.11`
- `redis_mode:standalone`
- `os:Linux 7.0.12-linuxkit aarch64` ← **IMPORTANT — see below**

**Result:** ✅ Pass.

---

### 🚨 KEY DISCOVERY — Kernel version confirmed

The `INFO` output revealed:
```
os:Linux 7.0.12-linuxkit aarch64
```

**This is the Linux kernel version running inside Docker Desktop's VM on macOS.**

**Implications:**
- **7.0.12** is well above the **6.19** threshold that triggers MongoDB's kernel guard (see Phase 13).
- This conclusively explains **why MongoDB fails** in this environment: MongoDB refuses to start on kernel ≥ 6.19, but Docker Desktop uses 7.0.12.
- **This should be documented in `phase_13.md` as an addendum.**
- Redis, MySQL, MariaDB, PostgreSQL, and CouchDB do not have such a guard, so they work on this kernel.

**Result:** ✅ Discovery — important for future troubleshooting and MongoDB's failure explanation.

---

### Step 14.12 — AOF files verified

```bash
ls -la ~/Projects01/docker-env/data_files/redis/appendonlydir/
```

**Output:**
```
appendonly.aof.1.base.rdb     89 bytes
appendonly.aof.1.incr.aof      0 bytes
appendonly.aof.manifest       88 bytes
```

**Result:** ✅ Pass. Base file (initial snapshot), incr file (empty — no writes yet), manifest.

---

### Step 14.13 — Cross-container + host reachability

```bash
nc -zv 127.0.0.1 6379
# succeeded!

docker run --rm redis:7 redis-cli -h host.docker.internal -p 6379 -a root --no-auth-warning ping
# PONG
```

**Result:** ✅ Pass. Port reachable from host, auth works over TCP from external container.

---

### Step 14.14 — Data persistence test (write)

First attempt with heredoc failed because `docker compose exec` allocates a TTY by default. **Fix:** add `-T` flag to disable TTY.

**Final command:**
```bash
docker compose exec -T redis redis-cli -a root --no-auth-warning <<'EOF'
SET app:name "dev-env"
SET app:version "1.0"
SET session:user:1 "anansaleh"
HSET config:web port 80
HSET config:web ssl yes
LPUSH queue:tasks "task1" "task2" "task3"
EOF
```

**Output:**
```
OK
OK
OK
1
1
3
```

**Verified with:**
```bash
docker compose exec redis redis-cli -a root --no-auth-warning --scan
# "app:version", "queue:tasks", "app:name", "config:web", "session:user:1"

docker compose exec redis redis-cli -a root --no-auth-warning GET app:name
# "dev-env"

docker compose exec redis redis-cli -a root --no-auth-warning HGETALL config:web
# 1) "port" 2) "80" 3) "ssl" 4) "yes"

docker compose exec redis redis-cli -a root --no-auth-warning LRANGE queue:tasks 0 -1
# 1) "task3" 2) "task2" 3) "task1"
```

**Result:** ✅ Pass. All 5 keys written and verified.

**Note:** `LRANGE` shows `task3, task2, task1` because `LPUSH` prepends to the list — last pushed is at index 0. That's expected Redis behavior.

---

### Step 14.15 — **Restart persistence — THE CRITICAL TEST**

```bash
docker compose restart redis
docker compose ps redis
docker compose exec redis redis-cli -a root --no-auth-warning --scan
docker compose exec redis redis-cli -a root --no-auth-warning GET app:name
docker compose exec redis redis-cli -a root --no-auth-warning LRANGE queue:tasks 0 -1
```

**Output:**
- Container: `Up 11 seconds (healthy)`
- All 5 keys still present after restart
- `GET app:name` → `"dev-env"`
- `LRANGE queue:tasks 0 -1` → `task3, task2, task1`

**Result:** ✅ **Pass — AOF persistence proven.**

---

### Step 14.16 — Logs on host + AOF loading verified

```bash
ls -la ~/Projects01/docker-env/logs/redis/
docker compose logs redis | tail -15
```

**Output:**
- `logs/redis/` is **empty** — stderr logging, no file logs (expected)
- Logs confirm AOF loading:
  ```
  * Reading RDB base file on AOF loading...
  * Loading RDB produced by version 7.4.11
  * RDB is base AOF
  * DB loaded from base file appendonly.aof.1.base.rdb: 0.001 seconds
  * DB loaded from incr file appendonly.aof.1.incr.aof: 0.001 seconds
  * DB loaded from append only file: 0.002 seconds
  * Ready to accept connections tcp
  ```

**Result:** ✅ Pass. AOF replay on restart confirmed.

---

### Step 14.17 — AOF file growth verified

```bash
ls -la ~/Projects01/docker-env/data_files/redis/appendonlydir/
```

**Output:**
```
appendonly.aof.1.base.rdb     89 bytes (unchanged)
appendonly.aof.1.incr.aof    316 bytes (grew from 0)
appendonly.aof.manifest       88 bytes (unchanged)
```

**Result:** ✅ Pass. **The incr AOF file grew from 0 to 316 bytes** — records our SET/HSET/LPUSH operations. This is direct file-level proof of AOF persistence.

---

### Step 14.18 — Test keys cleaned up

```bash
docker compose exec redis redis-cli -a root --no-auth-warning DEL app:name app:version session:user:1 config:web queue:tasks
# (integer) 5

docker compose exec redis redis-cli -a root --no-auth-warning --scan
# (empty output)
```

**Result:** ✅ Pass. Redis is back to a clean empty state.

---

## Phase 14 Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | Port 6379 free before start | ✅ |
| 2 | Folders created | ✅ |
| 3 | `.env` extended | ✅ |
| 4 | `redis/Dockerfile` created | ✅ |
| 5 | `redis.conf` created | ✅ |
| 6 | Compose extended with `redis` service | ✅ |
| 7 | Image built (Redis 7.4.11) | ✅ |
| 8 | Container started | ✅ |
| 9 | Container healthy | ✅ |
| 10 | Data directory on host (`appendonlydir/`) | ✅ |
| 11 | CLI access + INFO | ✅ |
| 12 | **Kernel version discovery** (`7.0.12-linuxkit`) | ✅ |
| 13 | AOF files present | ✅ |
| 14 | Port reachable + cross-container | ✅ |
| 15 | Data write (5 keys) | ✅ |
| 16 | **Restart persistence — PASSED** | ✅ |
| 17 | Logs verified (AOF loading) | ✅ |
| 18 | AOF incr file grew (0 → 316 bytes) | ✅ |
| 19 | Test keys cleaned up | ✅ |

**Phase 14 result: ✅ PASS**

---

## Multi-Database State (After Phase 14)

| Engine | Container | Host Port | Internal Port | Version | Restart Persistence | Data Folder | GUI |
|---|---|---|---|---|---|---|---|
| MySQL | `dev-mysql` | 3306 | 3306 | 8.0.46 | ✅ Yes | `data_files/mysql80/` | phpMyAdmin (later) |
| MariaDB | `dev-mariadb` | 3307 | 3306 | 11.8.9 | ✅ Yes | `data_files/mariadb11/` | phpMyAdmin (later) |
| PostgreSQL | `dev-postgres` | 5432 | 5432 | 15.19 | ✅ Yes | `data_files/postgres15/` | pgAdmin (later) |
| MongoDB | `dev-mongodb` | 27017 | 27017 | 8.0.4 | ❌ **No** (kernel bug) | `data_files/mongodb8/` | mongo-express (later) |
| CouchDB | `dev-couchdb` | 5984 | 5984 | 3.5.3 | ✅ Yes | `data_files/couchdb34/` | Fauxton (built-in) |
| **Redis** | `dev-redis` | 6379 | 6379 | **7.4.11** | ✅ **Yes** | `data_files/redis/` (unsuffixed) | (none — CLI only) |

---

## Connection Details

### From Other Containers (PHP, workspace)

| Engine | Hostname | Port | Auth |
|---|---|---|---|
| Redis | `redis` | 6379 | password: `root` |

### From Host

| Client | Host | Port | Command |
|---|---|---|---|
| `redis-cli` (Docker) | `127.0.0.1` | 6379 | `redis-cli -h 127.0.0.1 -a root` |
| GUI tools | `127.0.0.1` | 6379 | Port 6379, password `root` |

### PHP Connection

**Using the `redis` extension (phpredis):**
```php
$redis = new Redis();
$redis->connect('redis', 6379);
$redis->auth('root');
$redis->set('key', 'value');
echo $redis->get('key');  // "value"
```

**Using Predis library:**
```php
$redis = new Predis\Client([
    'scheme' => 'tcp',
    'host'   => 'redis',
    'port'   => 6379,
    'password' => 'root',
]);
$redis->set('key', 'value');
```

**Laravel (config/database.php):**
```php
'redis' => [
    'client' => 'phpredis',
    'default' => [
        'host' => env('REDIS_HOST', 'redis'),
        'password' => env('REDIS_PASSWORD', 'root'),
        'port' => env('REDIS_PORT', 6379),
        'database' => 0,
    ],
],
```

---

## Redis Persistence — How It Works

Redis has two persistence mechanisms, and we're using **both**:

### RDB (Redis Database snapshot)
- Periodic snapshots of the entire dataset to a `.rdb` file
- Fast to load, small file size
- May lose recent writes if Redis crashes between snapshots
- Triggered by `save 900 1` (15 min, ≥1 change), etc.

### AOF (Append-Only File)
- Logs every write command to a file
- More durable — replay the file to recover state
- Slower startup (must replay commands)
- **This is the primary persistence for our setup**
- Enabled via `appendonly yes`

### AOF Structure (Redis 7+)
The `appendonlydir/` contains:
- `appendonly.aof.1.base.rdb` — snapshot at AOF initialization
- `appendonly.aof.1.incr.aof` — incremental writes since that snapshot
- `appendonly.aof.manifest` — tracks segment files

**Auto-rewrite:** When the incr file grows beyond 100% of the base file (and exceeds 64 MB), Redis compacts them. That's `auto-aof-rewrite-percentage 100` + `auto-aof-rewrite-min-size 64mb`.

---

## Carry-Forward Notes for Future Phases

1. **Redis is up, empty, ready for use.** All test keys cleaned up.

2. **Connection:** `redis:6379` from containers, `127.0.0.1:6379` from host. Password: `root`.

3. **PHP has both `redis` extension (phpredis) and `igbinary`** in the base image — see `phase_05.md`. Uses `phpredis` or `predis` interchangeably.

4. **Dangerous commands disabled:**
   - `FLUSHALL` and `FLUSHDB` are renamed to empty strings — attempting to call them will fail with "unknown command."
   - This is a safety measure. If you need them for testing, comment out the `rename-command` lines in `redis.conf` and rebuild.

5. **PID file warning is cosmetic.** Fix scheduled for Phase 20:
   - Change `pidfile /var/run/redis_6379.pid` → `pidfile /tmp/redis_6379.pid`
   - Rebuild + restart
   - Log line `Failed to write PID file` will disappear

6. **No GUI.** Redis has no built-in web UI (unlike CouchDB's Fauxton). Tools for browsing Redis:
   - **`redis-cli`** — CLI, already available in the container
   - **RedisInsight** — third-party GUI, requires separate install
   - **TablePlus / Sequel Ace** — support Redis
   
   For dev, `redis-cli` is usually sufficient.

7. **Data is unsuffixed:** `data_files/redis/` (no version suffix). This differs from the other 5 DBs.
   - **Why:** Redis's RDB/AOF format is version-tolerant. A Redis 7.4 data dir can be loaded by 7.2 or 8.0 in most cases.
   - **When to version-suffix:** If you ever run two Redis versions simultaneously (e.g., test 7 vs 8 migration), you'd need separate data dirs. For a single Redis, unsuffixed is fine.

8. **No `.env` variables needed for AOF** — AOF is fully configured in `redis.conf`.

9. **Container name:** `dev-redis`

10. **Image ID:** `dev-env-redis:latest` (~19 MB base + config layer)

11. **Container restarts cleanly** — tested and proven.

12. **The kernel discovery from Step 14.11** (`7.0.12-linuxkit`) is a critical finding for MongoDB. It should be added as an addendum to `phase_13.md` and `phase_13a.md`:
    > Docker Desktop on this Mac uses Linux kernel 7.0.12 inside its LinuxKit VM. MongoDB 8.x refuses to start on kernels ≥ 6.19, which is why MongoDB fails.

13. **Phase 20 cleanup tasks** (running list):
    - Redis: change `pidfile` to `/tmp/redis_6379.pid`
    - MySQL/MariaDB/PostgreSQL: consolidate `.env` header comment
    - Apache: remove redundant `sed` step from Dockerfile (from Phase 4)
    - Apache: install `tzdata` for log timestamps (from Phase 4)
    - `.env` header comment: update to reflect current state

---

## Files and System Changes in Phase 14

**Files created:**

```
~/Projects01/docker-env/redis/Dockerfile
~/Projects01/docker-env/redis/redis.conf
~/Projects01/docker-env/data_files/redis/           (populated by Redis)
~/Projects01/docker-env/data_files/redis/appendonlydir/  (AOF files)
~/Projects01/docker-env/logs/redis/                 (empty — reserved)
```

**Files modified:**

```
~/Projects01/docker-env/.env                    (Redis variables added)
~/Projects01/docker-env/docker-compose.yml      (redis service added)
```

**Docker objects created:**

- Image: `dev-env-redis:latest`
- Container: `dev-redis` (with healthcheck)

---

## Rollback Procedure

To undo Phase 14:

```bash
cd ~/Projects01/docker-env
docker compose stop redis
docker compose rm -f redis
docker rmi dev-env-redis:latest
rm -rf ~/Projects01/docker-env/redis
rm -rf ~/Projects01/docker-env/data_files/redis
rm -rf ~/Projects01/docker-env/logs/redis
# Remove Redis variables from .env
# Remove redis service from docker-compose.yml
```

**Warning:** `rm -rf data_files/redis` permanently deletes all Redis data.

---
## Summary of Phase 14:

- ✅ 19/19 checks passed
- ✅ Redis 7.4.11 running, healthy, restart-survivable
- ✅ AOF persistence verified at file level (0 → 316 bytes)
- ✅ Data survives restart (proven)
- ✅ Clean state (no test data)
- ✅ Critical discovery: Kernel 7.0.12-linuxkit confirmed — explains MongoDB's failure
- ✅ PID file warning noted for Phase 20 fix

**Six data services running**. Five survive restarts; only MongoDB is ephemeral (kernel bug, not our design).

**Reminder to update:**

- `phase_13.md` — add an addendum noting the kernel version discovery: 7.0.12-linuxkit, well above MongoDB's 6.19 guard.

- `phase_13A.md` — mention the same.

---

## Next Phase

**Phase 15 — Workspaces (CLI Containers)**

Goal: add `workspace84` and `workspace74` — CLI containers with PHP, Composer, Node (via nvm), Git, and full development tooling. These replace the need for installing PHP/Composer/Node on the host.

Phase 15 will:
- Create `docker-env/workspace84/Dockerfile` and `docker-env/workspace74/Dockerfile`
- Configure nvm with Node 24, 22, 20
- Mount all sites + `/home` + `/backup`
- Enable connecting to all databases from inside the workspace
- Verify PHP CLI, Composer, Node, Git all work

**Note:** Workspaces depend on the base images built in Phases 5 and 6. Both are ready.

Phase 15 begins once this document is saved and confirmed.

---

**End of phase_14.md**