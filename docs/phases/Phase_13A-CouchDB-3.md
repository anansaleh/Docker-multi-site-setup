# Phase 13-a — CouchDB 3 (MongoDB Alternative)

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM)
**Host project root:** `~/Projects01/`
**Date completed:** 2026-10-16
**Status:** ✅ Complete — all checks passed

---

## Purpose

MongoDB was found to have a **fatal kernel incompatibility** (see `phase_13.md`) that prevents it from surviving container restarts. Since you need a working document database for local development, this phase adds **CouchDB** as a viable MongoDB alternative.

**CouchDB differs from MongoDB in fundamental ways**, but for document-oriented storage it fills the same role. Crucially, it works reliably in this environment — data survives restarts, it starts every time, and it includes a built-in GUI (Fauxton).

**MongoDB remains in the environment** — you chose to keep it. It is usable as ephemeral scratch storage only.

---

## Why CouchDB Instead of MongoDB

| Aspect | MongoDB (Phase 13) | CouchDB (this phase) |
|---|---|---|
| Version used | 8.0.4 (pinned) | 3.5.3 |
| Container restart | ❌ Crashes with kernel incompatibility | ✅ Restarts cleanly |
| Data persistence | ❌ Lost on restart | ✅ Preserved |
| Built-in GUI | ❌ No (needs Compass or similar) | ✅ **Yes — Fauxton** |
| Default port | 27017 | 5984 |
| Data model | BSON (binary JSON) | Pure JSON |
| Query | MongoDB Query Language | Mango (JSON) or JS MapReduce |
| API style | Wire protocol | **HTTP/REST-native** |
| Architecture | Master-slave | Master-master (multi-master) |
| Verdict | ⚠️ Ephemeral-only | ✅ **Fully usable** |

---

## Final State After Phase 13-a

| Component | Details |
|---|---|
| Container name | `dev-couchdb` |
| Image | `dev-env-couchdb:latest` (built FROM `couchdb:3`) |
| CouchDB version | 3.5.3 |
| Host port | 5984 |
| Internal port | 5984 |
| Data directory (host) | `~/Projects01/docker-env/data_files/couchdb34/` |
| Log destination | `stderr` (visible via `docker compose logs couchdb`) |
| Admin user | `admin` / `admin` |
| Admin database | `_users` |
| GUI | Fauxton (built-in) at `http://localhost:5984/_utils/` |
| Network | `dev-env_dev-net` |
| Healthcheck | `curl -f http://localhost:5984/_up` |
| System databases | `_users`, `_replicator`, `_global_changes` |

---

## What We Built

```
~/Projects01/docker-env/
├── data_files/
│   └── couchdb34/                  (CouchDB data — persists)
├── logs/
│   └── couchdb/                    (reserved for future file logging — empty)
├── couchdb/
│   ├── Dockerfile
│   └── local.ini
└── docker-compose.yml              (extended: couchdb service)
```

---

## Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Image | Official `couchdb:3` | Multi-arch (works on Apple Silicon), well-maintained |
| Version control | `COUCHDB_VERSION=3` in `.env` | Latest 3.x is fine |
| Data mount | `data_files/couchdb34/` → `/opt/couchdb/data` | Version-suffixed; standard CouchDB data dir |
| Log destination | `stderr` | Simpler than file logging; `docker compose logs couchdb` works |
| Host port | 5984 | CouchDB default |
| Auth | `COUCHDB_USER=admin`, `COUCHDB_PASSWORD=admin` | Required by image; dev credentials |
| Config file | `local.ini` mounted at `/opt/couchdb/etc/local.d/zz-custom.ini` | Loaded last (zz- prefix) so it overrides defaults |
| Admin user | `admin` in `_users` database | Standard CouchDB convention |
| System databases | Created on first run (or manually) | Required for full CouchDB functionality |
| Healthcheck | `curl -f http://localhost:5984/_up` | CouchDB's official "is it up" endpoint |

---

## The `couchdb/Dockerfile`

```dockerfile
# ============================================================
# CouchDB 3 container
# Version controlled via .env (COUCHDB_VERSION)
# Base image: official CouchDB Docker image
# ============================================================
ARG COUCHDB_VERSION=3
FROM couchdb:${COUCHDB_VERSION}

# Copy custom config
COPY local.ini /opt/couchdb/etc/local.d/zz-custom.ini

# Default entrypoint and command are inherited from the base image.
```

**Why `/opt/couchdb/etc/local.d/zz-custom.ini`:**
- CouchDB reads `/opt/couchdb/etc/local.ini` first.
- Then it loads all `.ini` files in `/opt/couchdb/etc/local.d/` in **alphabetical order**.
- Naming our file `zz-custom.ini` ensures it loads **last**, so its settings win.

---

## The `couchdb/local.ini`

```ini
; ============================================================
; CouchDB 3 custom configuration
; Local development environment
; ============================================================
; This file is loaded AFTER the base config in /opt/couchdb/etc/local.ini
; because it lives in local.d/ and is sorted last (zz- prefix).
; ============================================================

[chttpd]
; Bind to all interfaces so it's reachable from other containers and the host
bind_address = 0.0.0.0
port = 5984

; Development-friendly: allow unauthenticated root endpoint access
require_valid_user = false

[couchdb]
; Database directory
database_dir = /opt/couchdb/data
view_index_dir = /opt/couchdb/data

; Single node mode (no clustering)
single_node = true

; UUID (leave empty — CouchDB generates one)
uuid =

[log]
; Log level: debug, info, notice, warning, error
level = info

; Log to console (stdout) so `docker compose logs` works
writer = stderr

[chttpd_auth]
; Authentication timeout (seconds)
timeout = 600

[replicator]
; Interval between replicator job checks
interval = 60000

[admins]
; Admin user is set via COUCHDB_USER / COUCHDB_PASSWORD env vars at container start.
; Do NOT add a password here — env vars take precedence.
```

### Settings Explained

| Setting | Value | Purpose |
|---|---|---|
| `chttpd.bind_address` | `0.0.0.0` | Accept connections from any interface |
| `chttpd.port` | `5984` | Standard CouchDB port |
| `chttpd.require_valid_user` | `false` | Allow unauthenticated access to root endpoint (health checks) |
| `couchdb.database_dir` | `/opt/couchdb/data` | Database storage location |
| `couchdb.single_node` | `true` | Single-node mode (no clustering) |
| `log.level` | `info` | Moderate verbosity |
| `log.writer` | `stderr` | Logs visible via `docker compose logs` |

### Switching to File Logging (Optional)

If you prefer file logging (like MySQL/MariaDB/PostgreSQL), change the `[log]` section to:

```ini
[log]
writer = file
file = /var/log/couchdb/couchdb.log
level = info
```

The `logs/couchdb/` mount already exists (`~/Projects01/docker-env/logs/couchdb/` → `/var/log/couchdb`). Rebuild and restart CouchDB to apply.

---

## The `docker-compose.yml` — CouchDB Service

```yaml
  couchdb:
    build:
      context: ./couchdb
      args:
        COUCHDB_VERSION: ${COUCHDB_VERSION}
    image: dev-env-couchdb
    container_name: dev-couchdb
    restart: unless-stopped
    ports:
      - "${COUCHDB_PORT}:5984"
    volumes:
      - ${PROJECTS_ROOT}/docker-env/data_files/couchdb34:/opt/couchdb/data
      - ${PROJECTS_ROOT}/docker-env/logs/couchdb:/var/log/couchdb
    environment:
      - COUCHDB_USER=${COUCHDB_USER}
      - COUCHDB_PASSWORD=${COUCHDB_PASSWORD}
      - TZ=${TZ}
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:5984/_up"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 30s
    networks:
      - dev-net
```

---

## The `.env` Additions

```
# --- CouchDB ---
COUCHDB_VERSION=3
COUCHDB_PORT=5984
COUCHDB_USER=admin
COUCHDB_PASSWORD=admin
```

---

## Step-by-Step Record

### Step 13-a.1 — Port 5984 check

```bash
sudo lsof -iTCP:5984 -sTCP:LISTEN
```

**Output:** no output (port free).

**Result:** ✅ Pass.

---

### Step 13-a.2 — Folders created

```bash
mkdir -p ~/Projects01/docker-env/couchdb
mkdir -p ~/Projects01/docker-env/data_files/couchdb34
mkdir -p ~/Projects01/docker-env/logs/couchdb
```

**Result:** ✅ Pass.

---

### Step 13-a.3 — `.env` extended

Four variables added: `COUCHDB_VERSION`, `COUCHDB_PORT`, `COUCHDB_USER`, `COUCHDB_PASSWORD`.

**Result:** ✅ Pass.

---

### Step 13-a.4 — `couchdb/Dockerfile` created

Thin wrapper over `couchdb:3` with `COPY local.ini`.

**Result:** ✅ Pass.

---

### Step 13-a.5 — `couchdb/local.ini` created

Custom config with bind address, logging, and single-node mode.

**Result:** ✅ Pass.

---

### Step 13-a.6 — Compose extended

Added `couchdb` service. Nine services total now.

`docker compose config --services` returned:
```
mariadb
mysql
php-fpm74
php-fpm84
nginx
postgres
apache2
couchdb
mongodb
```

**Result:** ✅ Pass.

---

### Step 13-a.7 — Build

```bash
docker compose build couchdb
```

**Output:** Base image `couchdb:3` pulled (~120 MB). Image `dev-env-couchdb:latest` built in 6.7s.

**Result:** ✅ Pass.

---

### Step 13-a.8 — Start

```bash
docker compose up -d couchdb
docker compose ps
```

**Output:** Container started. Status: `Up ... (health: starting)`.

**Result:** ✅ Pass.

---

### Step 13-a.9 — Container healthy

```bash
docker compose ps couchdb
docker compose logs couchdb | tail -30
```

**Output:** `Up About a minute (healthy)`

**Key log lines:**
```
Apache CouchDB has started. Time to relax.
Apache CouchDB has started on http://0.0.0.0:5984/
config: [admins] admin set to '****'
config: [couchdb] uuid set to dc19e335a97a93368b561a5bc160f777
```

**Warnings (all benign):**
- `chttpd_auth_cache changes listener died because the _users database does not exist` — transient, resolved once CouchDB created the system DBs
- `Request to create N=3 DB but only 1 node(s)` — single-node mode vs 3-node default replication factor

**Result:** ✅ Pass. CouchDB 3.5.3 healthy.

---

### Step 13-a.10 — HTTP API verified

```bash
curl -sS -u admin:admin "http://localhost:5984/"
curl -sS -o /dev/null -w "%{http_code}\n" "http://localhost:5984/_utils/"
```

**Output:**
```json
{"couchdb":"Welcome","version":"3.5.3","git_sha":"7a1f97558","uuid":"dc19e335a97a93368b561a5bc160f777","features":["access-ready","partitioned","pluggable-storage-engines","reshard","scheduler"],"vendor":{"name":"The Apache Software Foundation"}}
```
And `200` for Fauxton.

**Result:** ✅ Pass. CouchDB 3.5.3 API responds.

---

### Step 13-a.11 — Fauxton GUI verified in browser

Manually opened in browser: `http://localhost:5984/_utils/`

- ✅ Login screen appeared
- ✅ Logged in with `admin` / `admin`
- ✅ Dashboard loaded
- ✅ Sidebar showed the three system databases
- ✅ Created a test database via the UI
- ✅ Test database appeared in the list

**Result:** ✅ Pass. Fauxton works as a phpMyAdmin-style GUI.

---

### Step 13-a.12 — System databases created

```bash
curl -sS -u admin:admin -X PUT "http://localhost:5984/_users"          # file_exists (already there)
curl -sS -u admin:admin -X PUT "http://localhost:5984/_replicator"     # file_exists (already there)
curl -sS -u admin:admin -X PUT "http://localhost:5984/_global_changes" # {"ok":true}
```

**Result:** ✅ Pass. All 3 system databases now exist.

**Note:** `_users` and `_replicator` had been auto-created by CouchDB after the initial startup warnings — the file_exists responses confirm this. Only `_global_changes` was actually missing.

---

### Step 13-a.13 — All databases listed

```bash
curl -sS -u admin:admin "http://localhost:5984/_all_dbs"
```

**Output:**
```json
["_global_changes","_replicator","_users"]
```

**Result:** ✅ Pass.

---

### Step 13-a.14 — Logs clean after restart

```bash
docker compose logs couchdb | tail -20
```

**Output includes:**
```
[notice] ... All system databases exist.
[notice] ... GET /_up 200 ok
[info] ... rexi_buffer : cluster stable
```

**Notable absence:** the `_users database does not exist` warnings are gone.

**Result:** ✅ Pass.

---

### Step 13-a.15 — Document create + read

**Create:**
```bash
curl -sS -u admin:admin -X POST "http://localhost:5984/test" \
  -H "Content-Type: application/json" \
  -d '{"note":"couchdb before restart","created":"now"}'
```

**Output:**
```json
{"ok":true,"id":"0716cadd4d359ee25581b72be6000258","rev":"1-7327255a7612be9d2be54a02e7252be0"}
```

**Read:**
```bash
curl -sS -u admin:admin "http://localhost:5984/test/_all_docs?include_docs=true"
```

**Output:**
```json
{"total_rows":1,"offset":0,"rows":[
{"id":"0716cadd4d359ee25581b72be6000258","key":"0716cadd4d359ee25581b72be6000258","value":{"rev":"1-7327255a7612be9d2be54a02e7252be0"},"doc":{"_id":"0716cadd4d359ee25581b72be6000258","_rev":"1-7327255a7612be9d2be54a02e7252be0","note":"couchdb before restart","created":"now"}}
]}
```

**Result:** ✅ Pass.

---

### Step 13-a.16 — **Restart persistence — THE CRITICAL TEST**

```bash
docker compose restart couchdb
docker compose ps couchdb
curl -sS -u admin:admin "http://localhost:5984/test/_all_docs?include_docs=true"
```

**Output:**
- Container: `Up 15 seconds (healthy)`
- Document returned with **same `_id` and `_rev`** — data survived!

**Result:** ✅ **Pass — CouchDB survives restart.** This is the key finding of Phase 13-a.

---

### Step 13-a.17 — Log destination confirmed

```bash
docker compose logs couchdb | tail -20
ls -la ~/Projects01/docker-env/logs/couchdb/
```

**Output:**
- Logs show full startup sequence + healthcheck calls
- `logs/couchdb/` folder is **empty** — as designed (stderr logging)

**Result:** ✅ Pass.

---

### Step 13-a.18 — Test data cleaned up

```bash
curl -sS -u admin:admin -X DELETE "http://localhost:5984/test"
curl -sS -u admin:admin -X DELETE "http://localhost:5984/test_fauxton"
curl -sS -u admin:admin "http://localhost:5984/_all_dbs"
```

**Output:**
- `{"ok":true}` for `test`
- `{"error":"not_found",...}` for `test_fauxton` (already gone)
- Final list: `["_global_changes","_replicator","_users"]`

**Result:** ✅ Pass.

---

## Phase 13-a Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | Port 5984 free before start | ✅ |
| 2 | Folders created | ✅ |
| 3 | `.env` extended | ✅ |
| 4 | `couchdb/Dockerfile` created | ✅ |
| 5 | `couchdb/local.ini` created | ✅ |
| 6 | Compose extended with `couchdb` service | ✅ |
| 7 | Image built | ✅ |
| 8 | Container started | ✅ |
| 9 | Container healthy (CouchDB 3.5.3) | ✅ |
| 10 | HTTP API verified | ✅ |
| 11 | Fauxton GUI verified in browser | ✅ |
| 12 | System databases created | ✅ |
| 13 | `_all_dbs` verified | ✅ |
| 14 | Logs clean after restart | ✅ |
| 15 | Document create + read | ✅ |
| 16 | **Restart persistence — PASSED** | ✅ |
| 17 | Log destination confirmed | ✅ |
| 18 | Test data cleaned up | ✅ |

**Phase 13-a result: ✅ PASS**

---

## Multi-Database State (After Phase 13-a)

| Engine | Container | Host Port | Internal Port | Version | Restart Persistence | Data Folder |
|---|---|---|---|---|---|---|
| MySQL | `dev-mysql` | 3306 | 3306 | 8.0.46 | ✅ Yes | `data_files/mysql80/` |
| MariaDB | `dev-mariadb` | 3307 | 3306 | 11.8.9 | ✅ Yes | `data_files/mariadb11/` |
| PostgreSQL | `dev-postgres` | 5432 | 5432 | 15.19 | ✅ Yes | `data_files/postgres15/` |
| MongoDB | `dev-mongodb` | 27017 | 27017 | 8.0.4 | ❌ **No** (kernel bug) | `data_files/mongodb8/` |
| **CouchDB** | `dev-couchdb` | 5984 | 5984 | **3.5.3** | ✅ **Yes** | `data_files/couchdb34/` |

**Five database engines running side by side.**

---

## Connection Details

### From Other Containers (PHP, workspace)

| Engine | Hostname | Port | Credentials |
|---|---|---|---|
| MySQL | `mysql` | 3306 | `root` / `root` |
| MariaDB | `mariadb` | 3306 | `root` / `root` |
| PostgreSQL | `postgres` | 5432 | `postgres` / `postgres` |
| MongoDB | `mongodb` | 27017 | `root` / `root` (auth DB: `admin`) |
| **CouchDB** | `couchdb` | **5984** | **`admin` / `admin`** |

### From Host (GUI tools, browser)

| Engine | URL / Host:Port |
|---|---|
| MySQL | `127.0.0.1:3306` |
| MariaDB | `127.0.0.1:3307` |
| PostgreSQL | `127.0.0.1:5432` |
| MongoDB | `127.0.0.1:27017` |
| **CouchDB HTTP API** | **`http://127.0.0.1:5984/`** |
| **CouchDB Fauxton** | **`http://localhost:5984/_utils/`** |

### PHP Connection Strings

```php
// MySQL
new PDO('mysql:host=mysql;port=3306;dbname=mydb;charset=utf8mb4', 'root', 'root');

// MariaDB
new PDO('mysql:host=mariadb;port=3306;dbname=mydb;charset=utf8mb4', 'root', 'root');

// PostgreSQL
new PDO('pgsql:host=postgres;port=5432;dbname=mydb', 'postgres', 'postgres');

// MongoDB
$mongo = new MongoDB\Driver\Manager('mongodb://root:root@mongodb:27017/?authSource=admin');

// CouchDB — via HTTP client (CouchDB is HTTP-native)
// Use Guzzle, Symfony HttpClient, or any HTTP client:
$client = new GuzzleHttp\Client(['base_uri' => 'http://admin:admin@couchdb:5984/']);
$response = $client->get('_all_dbs');
```

---

## Fauxton GUI — CouchDB's phpMyAdmin Equivalent

Fauxton is **built into CouchDB** — no separate container needed.

### Access

- **URL:** `http://localhost:5984/_utils/`
- **Username:** `admin`
- **Password:** `admin`

### What You Can Do

- **Browse databases** — sidebar list with all your databases
- **View/edit documents** — built-in JSON editor
- **Create databases** — green "Create Database" button
- **Run Mango queries** — visual query builder for JSON queries
- **Manage replication** — set up and monitor replication jobs
- **Configuration** — view and edit CouchDB server config
- **Active tasks** — see what's running (indexing, compaction, replication)

### CouchDB's Data Model vs MongoDB

**CouchDB concepts:**
- **Database** = bucket of documents (like MongoDB's collection, but CouchDB databases are heavier)
- **Document** = pure JSON object with `_id` and `_rev` fields
- **Revision (`_rev`)** = version hash; every edit creates a new revision
- **Design document** = special document with views (MapReduce) and indexes
- **View** = JavaScript MapReduce function producing a queryable index

**MongoDB concepts (for comparison):**
- **Database** → same idea
- **Collection** → CouchDB has no separate concept; each database is one namespace
- **Document** → same idea
- **Index** → CouchDB uses views; MongoDB uses B-tree indexes

---

## Recommendations for Using CouchDB in PHP Projects

1. **Use an HTTP client, not a dedicated driver.** CouchDB is HTTP-native. Any HTTP client works:
   - **`doctrine/couchdb`** — thin HTTP wrapper
   - **`guzzlehttp/guzzle`** — general-purpose HTTP client
   - **Symfony HttpClient** — if the project already uses Symfony

2. **Base URL from inside containers:** `http://couchdb:5984/`
3. **Base URL from host:** `http://127.0.0.1:5984/`
4. **Always include credentials:** `http://admin:admin@couchdb:5984/`
5. **Databases are cheap** — create per-project or per-module.
6. **Use Mango queries** — CouchDB's JSON query language (simpler than MapReduce for most cases).
7. **For replication:** CouchDB's killer feature is master-master replication — useful for offline-first apps.

---

## Comparison With MongoDB (for Reference)

| Need | MongoDB | CouchDB |
|---|---|---|
| Simple document storage | ✅ Native | ✅ Native |
| Persistence across restart | ❌ Broken in this env | ✅ Works |
| Built-in GUI | ❌ | ✅ Fauxton |
| Familiar SQL-like queries | MQL | Mango |
| Offline-first / replication | Complex (replica sets) | ✅ Native (master-master) |
| HTTP API as primary interface | No | ✅ Yes |

**For local development in this environment, CouchDB is the pragmatic choice.**

---

## Files and System Changes in Phase 13-a

**Files created:**

```
~/Projects01/docker-env/couchdb/Dockerfile
~/Projects01/docker-env/couchdb/local.ini
~/Projects01/docker-env/data_files/couchdb34/      (populated by CouchDB)
~/Projects01/docker-env/logs/couchdb/              (empty — reserved for file logging)
```

**Files modified:**

```
~/Projects01/docker-env/.env                    (CouchDB variables added)
~/Projects01/docker-env/docker-compose.yml      (couchdb service added)
```

**Docker objects created:**

- Image: `dev-env-couchdb:latest`
- Container: `dev-couchdb` (with healthcheck)

---

## Rollback Procedure

To undo Phase 13-a:

```bash
cd ~/Projects01/docker-env
docker compose down couchdb
docker rmi dev-env-couchdb:latest
rm -rf ~/Projects01/docker-env/couchdb
rm -rf ~/Projects01/docker-env/data_files/couchdb34
rm -rf ~/Projects01/docker-env/logs/couchdb
# Remove CouchDB variables from .env
# Remove couchdb service from docker-compose.yml
```

**Warning:** `rm -rf data_files/couchdb34` permanently deletes all CouchDB data.

---


## Summary of Phase 13-a:

- ✅ 18/18 checks passed
- ✅ CouchDB 3.5.3 running, healthy, restartable
- ✅ Data survives container restart — the key differentiator from MongoDB
- ✅ Fauxton GUI works (built-in, no separate container)
- ✅ System databases created (silences log warnings)
- ✅ HTTP API verified
- ✅ Document create/read verified
- ✅ Test data cleaned up

Five database engines now running. CouchDB is the working document store; MongoDB remains as ephemeral scratch.

---


## Next Phase

**Phase 14 — Redis**

Goal: add Redis as the cache and queue backend.

Phase 14 will:
- Create `docker-env/redis/Dockerfile` + `redis.conf`
- Extend `docker-compose.yml` with `redis` service
- Data on host: `data_files/redis/` (unsuffixed per earlier decision)
- Logs on host: `logs/redis/`
- Host port: 6379
- Verify connectivity and persistence

**Expected:** Redis is a simple key-value store. No known kernel or restart issues. Should work cleanly like MySQL/MariaDB/PostgreSQL/CouchDB.

Phase 14 begins once this document is saved and confirmed.

---

**End of phase_13a.md**