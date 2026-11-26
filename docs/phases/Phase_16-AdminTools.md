# Phase 16 — Admin Tools (Flag-Gated)

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM)
**Host project root:** `~/Projects01/`
**Date completed:** 2026-10-17
**Status:** ✅ Complete — all checks passed

---

## Objective

Add web-based admin UIs for the databases, each behind a `.env` flag so you can enable only what you need. Four new tools:

- **phpMyAdmin** — for MySQL + MariaDB
- **pgAdmin** — for PostgreSQL
- **mongo-express** — for MongoDB
- **Adminer** — multi-DB (MySQL, MariaDB, PostgreSQL, MongoDB, and more)

**Gating mechanism:** Docker Compose **profiles**. When a profile is not activated, its service is skipped entirely (no container created).

**CouchDB Fauxton** is already built-in (`http://localhost:5984/_utils/`) — no extra container needed.

---

## Final State After Phase 16

| Tool | Container | Host Port | Internal Port | Target DB(s) | Profile |
|---|---|---|---|---|---|
| phpMyAdmin | `dev-phpmyadmin` | 8081 | 80 | MySQL, MariaDB | `phpmyadmin` |
| pgAdmin | `dev-pgadmin` | 8082 | 80 | PostgreSQL | `pgadmin` |
| mongo-express | `dev-mongo-express` | 8083 | 8081 | MongoDB | `mongo-express` |
| Adminer | `dev-adminer` | 8084 | 8080 | All (multi-DB) | `adminer` |
| Fauxton (CouchDB) | (built-in) | 5984 | 5984 | CouchDB | always |

---

## What We Built

```
~/Projects01/docker-env/
├── phpmyadmin/
│   └── Dockerfile
├── pgadmin/
│   └── Dockerfile
├── mongo-express/
│   └── Dockerfile
├── adminer/
│   └── Dockerfile
├── data_files/
│   └── pgadmin/                 (pgAdmin config persistence)
└── docker-compose.yml           (extended: 4 new services with profiles)
```

---

## Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Gating mechanism | Docker Compose **profiles** | Native compose feature; disabled services don't appear in `docker compose up -d` |
| phpMyAdmin | Official `phpmyadmin:latest` | Lightweight, well-maintained |
| pgAdmin | Official `dpage/pgadmin4:latest` | Standard |
| mongo-express | Official `mongo-express:latest` | Standard |
| Adminer | Official `adminer:latest` | Single-file, lightweight |
| phpMyAdmin auth | **Manual login form** | Removed `PMA_USER`/`PMA_PASSWORD` so the login screen appears; `PMA_HOST` still pre-fills the server |
| pgAdmin mode | **Desktop mode (no login)** | `PGADMIN_CONFIG_SERVER_MODE=False` — fastest for local dev |
| mongo-express auth | Auto-auth via MongoDB credentials in URL | No separate login screen |
| Adminer auth | Login form with DB selector | Multi-DB — same UI for all engines |
| pgAdmin config persistence | `data_files/pgadmin/` mount | So registered servers survive recreation |
| All tools | No data mounts (except pgAdmin config) | UIs are stateless; DBs hold the data |

---

## The Four Dockerfiles

All four are minimal — the official images have everything.

### `phpmyadmin/Dockerfile`

```dockerfile
FROM phpmyadmin:latest
# Default entrypoint inherited
```

### `pgadmin/Dockerfile`

```dockerfile
FROM dpage/pgadmin4:latest
# Default entrypoint inherited
```

### `mongo-express/Dockerfile`

```dockerfile
FROM mongo-express:latest
# Default entrypoint inherited
```

### `adminer/Dockerfile`

```dockerfile
FROM adminer:latest
# Default entrypoint inherited
```

---

## The `.env` Additions

```ini
# --- Admin Tools (profiles) ---
# Enable by setting COMPOSE_PROFILES to a comma-separated list.
# Available: phpmyadmin, pgadmin, mongo-express, adminer
# Example: COMPOSE_PROFILES=phpmyadmin,adminer
COMPOSE_PROFILES=

# Ports
PHPMYADMIN_PORT=8081
PGADMIN_PORT=8082
MONGO_EXPRESS_PORT=8083
ADMINER_PORT=8084

# pgAdmin app login (not PostgreSQL credentials)
PGADMIN_DEFAULT_EMAIL=admin@admin.com
PGADMIN_DEFAULT_PASSWORD=admin
```

---

## The `docker-compose.yml` — Four Admin Services

```yaml
  phpmyadmin:
    build:
      context: ./phpmyadmin
    image: dev-env-phpmyadmin
    container_name: dev-phpmyadmin
    restart: unless-stopped
    profiles:
      - phpmyadmin
    ports:
      - "${PHPMYADMIN_PORT}:80"
    environment:
      - PMA_ARBITRARY=1
      - PMA_HOST=mysql
      - UPLOAD_LIMIT=128M
      - TZ=${TZ}
    depends_on:
      - mysql
    networks:
      - dev-net

  pgadmin:
    build:
      context: ./pgadmin
    image: dev-env-pgadmin
    container_name: dev-pgadmin
    restart: unless-stopped
    profiles:
      - pgadmin
    ports:
      - "${PGADMIN_PORT}:80"
    environment:
      - PGADMIN_DEFAULT_EMAIL=${PGADMIN_DEFAULT_EMAIL}
      - PGADMIN_DEFAULT_PASSWORD=${PGADMIN_DEFAULT_PASSWORD}
      - PGADMIN_CONFIG_SERVER_MODE=False
      - PGADMIN_CONFIG_MASTER_PASSWORD_REQUIRED=False
      - TZ=${TZ}
    volumes:
      - ${PROJECTS_ROOT}/docker-env/data_files/pgadmin:/var/lib/pgadmin
    depends_on:
      - postgres
    networks:
      - dev-net

  mongo-express:
    build:
      context: ./mongo-express
    image: dev-env-mongo-express
    container_name: dev-mongo-express
    restart: unless-stopped
    profiles:
      - mongo-express
    ports:
      - "${MONGO_EXPRESS_PORT}:8081"
    environment:
      - ME_CONFIG_MONGODB_URL=mongodb://${MONGODB_ROOT_USERNAME}:${MONGODB_ROOT_PASSWORD}@mongodb:27017/?authSource=admin
      - ME_CONFIG_BASICAUTH=false
      - ME_CONFIG_MONGODB_ADMINUSERNAME=${MONGODB_ROOT_USERNAME}
      - ME_CONFIG_MONGODB_ADMINPASSWORD=${MONGODB_ROOT_PASSWORD}
      - TZ=${TZ}
    depends_on:
      - mongodb
    networks:
      - dev-net

  adminer:
    build:
      context: ./adminer
    image: dev-env-adminer
    container_name: dev-adminer
    restart: unless-stopped
    profiles:
      - adminer
    ports:
      - "${ADMINER_PORT}:8080"
    environment:
      - ADMINER_DEFAULT_SERVER=mysql
      - TZ=${TZ}
    depends_on:
      - mysql
      - mariadb
      - postgres
      - mongodb
    networks:
      - dev-net
```

**Key points:**
- **`profiles:`** — each service belongs to its own profile. Without the profile being active in `COMPOSE_PROFILES`, the service is skipped entirely.
- **phpMyAdmin `PMA_ARBITRARY=1`** — lets you enter any server name in the login form (so you can log into MariaDB from the same phpMyAdmin, no rebuild needed).
- **phpMyAdmin** has **no `PMA_USER`/`PMA_PASSWORD`** — this forces the login form to appear.
- **pgAdmin** volume mount — `data_files/pgadmin/` → `/var/lib/pgadmin` so registered servers persist.
- **mongo-express** — uses the full MongoDB connection URL with credentials. No separate login screen.
- **Adminer** — multi-DB by nature; login form lets you select MySQL/MariaDB/PostgreSQL/MongoDB etc.

---

## Step-by-Step Record

### Step 16.1 — `.env` extended

Added:
- `COMPOSE_PROFILES=` (empty by default)
- Four ports (`PHPMYADMIN_PORT`, `PGADMIN_PORT`, `MONGO_EXPRESS_PORT`, `ADMINER_PORT`)
- pgAdmin app credentials

**Result:** ✅ Pass.

---

### Step 16.2 — Four Dockerfiles created

Each is a minimal `FROM <image>:latest` with a comment.

**Result:** ✅ Pass.

**Note:** During this step, a shell quirk occurred with `#` in heredoc comments — the `zsh` shell tried to execute `#` as a command. The files were still created correctly, just with the `#` lines as expected inside the Dockerfiles. Not an issue.

---

### Step 16.3 — Compose extended with 4 services

Created `data_files/pgadmin/` folder for pgAdmin config.

Added four services with `profiles:`.

**Validation:**
```bash
docker compose config --services
# 12 base services (admin tools hidden — no profile active)

docker compose config --profiles
# adminer
# mongo-express
# pgadmin
# phpmyadmin
```

**Result:** ✅ Pass — profile mechanism confirmed.

---

### Step 16.4 — Build four images

```bash
docker compose --profile phpmyadmin --profile pgadmin --profile mongo-express --profile adminer build phpmyadmin pgadmin mongo-express adminer
```

**Output:** All 4 images built in 17.6s (fast pull).

**Result:** ✅ Pass.

---

### Step 16.5 — Profile mechanism verified (disabled state)

```bash
docker compose ps
```

**Output:** Only the 12 base containers — admin tools absent. Confirms empty `COMPOSE_PROFILES` hides them.

**Result:** ✅ Pass.

---

### Step 16.6 — phpMyAdmin started via profile

```bash
sed -i '' 's/^COMPOSE_PROFILES=$/COMPOSE_PROFILES=phpmyadmin/' .env
docker compose up -d
docker compose ps
```

**Output:** `dev-phpmyadmin` started. 13 containers total.

**Result:** ✅ Pass.

---

### Step 16.7 — phpMyAdmin browser test — **auto-login issue**

**Initial configuration:** `PMA_USER=root` + `PMA_PASSWORD=root` → auto-login, no form.

**Observed issues:**
- Login page never appeared
- Logout didn't work (auto-re-authenticated)

**Root cause:** phpMyAdmin uses "cookie auto-auth" mode when both `PMA_USER` and `PMA_PASSWORD` are set. Sessions are meaningless because the credentials are re-read from env vars on each request.

**Decision:** Remove `PMA_USER` and `PMA_PASSWORD` from compose. Keep `PMA_HOST=mysql` so the login form's server field is pre-filled.

**Result:** ⚠️ Fix needed.

---

### Step 16.7-fix — phpMyAdmin login form restored

**Compose change:**
- Removed:
  ```yaml
  - PMA_USER=root
  - PMA_PASSWORD=${MYSQL_ROOT_PASSWORD}
  ```
- Kept:
  ```yaml
  - PMA_ARBITRARY=1
  - PMA_HOST=mysql
  - UPLOAD_LIMIT=128M
  ```

**Recreate:**
```bash
docker compose up -d --force-recreate phpmyadmin
```

**Result:** ✅ Pass — login form now appears on `http://localhost:8081`.

---

### Step 16.8 — All four profiles enabled

```bash
sed -i '' 's/^COMPOSE_PROFILES=.*/COMPOSE_PROFILES=phpmyadmin,pgadmin,mongo-express,adminer/' .env
docker compose up -d
docker compose ps
```

**Output:** 16 containers total:
- 12 base services (unchanged)
- `dev-phpmyadmin`, `dev-pgadmin`, `dev-mongo-express`, `dev-adminer`

**Port mappings:**
- phpMyAdmin: `8081 → 80`
- pgAdmin: `8082 → 80`
- mongo-express: `8083 → 8081` (internal)
- Adminer: `8084 → 8080` (internal)

**Result:** ✅ Pass.

---

### Step 16.9 — Browser test for all four

| Tool | Loads | Login | Browse | Notes |
|---|---|---|---|---|
| **phpMyAdmin** | ✅ | ✅ (login form) | ✅ | `root` / `root`; can browse all DBs |
| **pgAdmin** | ✅ | ⚠️ No login | ✅ | Desktop mode — see below |
| **mongo-express** | ✅ | N/A (auto-auth) | ✅ | Shows `admin`, `config`, `local` DBs |
| **Adminer** | ✅ | ✅ | ✅ | Multi-DB selector works |

**Result:** ✅ Pass.

---

## pgAdmin — Two Modes

pgAdmin supports two modes of operation, and this matters for how you use it.

### Current mode: **Desktop mode (no login)**

**Configuration:**
```yaml
- PGADMIN_CONFIG_SERVER_MODE=False
- PGADMIN_CONFIG_MASTER_PASSWORD_REQUIRED=False
```

**Behavior:**
- No login page — you go straight to the dashboard
- Single-user
- Best for **local development**
- Fastest experience

**Why we chose this:**
- Local dev only — no need for authentication
- The whole environment is on your Mac, not exposed to a network
- One click access — no friction

### Alternative mode: **Server mode (with login)**

**Configuration:**
```yaml
- PGADMIN_CONFIG_SERVER_MODE=True
- PGADMIN_CONFIG_MASTER_PASSWORD_REQUIRED=False  # optional — see below
- PGADMIN_DEFAULT_EMAIL=${PGADMIN_DEFAULT_EMAIL}
- PGADMIN_DEFAULT_PASSWORD=${PGADMIN_DEFAULT_PASSWORD}
```

**Behavior:**
- Login page appears
- Login with:
  - **Email:** `admin@admin.com` (from `PGADMIN_DEFAULT_EMAIL`)
  - **Password:** `admin` (from `PGADMIN_DEFAULT_PASSWORD`)
- Multi-user capable
- Matches production pgAdmin behavior

**Why you might switch:**
- To test pgAdmin's user management
- To simulate a production-like pgAdmin setup
- If you share the environment with others

### How to switch from Desktop → Server mode

**Step 1 — Edit `docker-compose.yml`:**

Find the `pgadmin:` service. Change:
```yaml
- PGADMIN_CONFIG_SERVER_MODE=False
```

To:
```yaml
- PGADMIN_CONFIG_SERVER_MODE=True
```

Leave the other pgAdmin env vars as-is. They already contain the credentials you'll use to log in.

**Step 2 — Recreate the pgAdmin container:**

```bash
cd ~/Projects01/docker-env
docker compose up -d --force-recreate pgadmin
```

**Step 3 — Visit `http://localhost:8082`:**

You'll now see a login page. Enter:
- **Email:** `admin@admin.com`
- **Password:** `admin`

**Step 4 — To switch back to Desktop mode:**

Change the flag back to `False`, then recreate the container again.

### About the `MASTER_PASSWORD` setting

`PGADMIN_CONFIG_MASTER_PASSWORD_REQUIRED` is a **secondary password** layer (independent of your login password). It:
- Protects saved server credentials (PostgreSQL passwords you store in pgAdmin)
- Is separate from your pgAdmin login email/password
- In **Desktop mode:** not needed (no login, no saved credentials concept)
- In **Server mode:** optional, but recommended in production

**We set it to `False`** — no secondary password. This means:
- In Desktop mode: no effect (doesn't apply)
- In Server mode (if you switch): you only type your login (email + password), no second master password

**If you want to test pgAdmin's master password behavior** (production-like), change to `True` and rebuild. You'll be prompted to set a master password on first login.

**For local dev, keeping it `False` is correct** — no friction, no confusion.

---

## How Profiles Work

Docker Compose profiles are a native feature that determines **which services to start** based on the `COMPOSE_PROFILES` environment variable.

### Without profiles active

```ini
# .env
COMPOSE_PROFILES=
```

**`docker compose up -d`** → only the 12 base services start. Admin tools don't exist.

### With one profile

```ini
# .env
COMPOSE_PROFILES=phpmyadmin
```

**`docker compose up -d`** → 12 base + `dev-phpmyadmin`. Others skipped.

### With all four

```ini
# .env
COMPOSE_PROFILES=phpmyadmin,pgadmin,mongo-express,adminer
```

**`docker compose up -d`** → 16 containers.

### Switching profiles

If you change `COMPOSE_PROFILES` and run `docker compose up -d`:
- Newly enabled services start
- Newly disabled services are **left running** (Compose doesn't stop them automatically)
- To stop disabled services, run `docker compose down` then `up -d` again

**Clean approach:** `docker compose down && docker compose up -d` after changing profiles.

---

## Admin Tool URLs and Credentials

| Tool | URL | Login | Notes |
|---|---|---|---|
| **phpMyAdmin** | `http://localhost:8081` | `root` / `root` | Manual login form |
| **pgAdmin** | `http://localhost:8082` | *(none)* | Desktop mode |
| **mongo-express** | `http://localhost:8083` | *(none)* | Auto-auth to MongoDB |
| **Adminer** | `http://localhost:8084` | Depends on DB | Multi-DB selector |
| **CouchDB Fauxton** | `http://localhost:5984/_utils/` | `admin` / `admin` | Built-in |

### Adminer multi-DB login examples

| DB | System | Server | Username | Password |
|---|---|---|---|---|
| MySQL | MySQL | `mysql` | `root` | `root` |
| MariaDB | MariaDB | `mariadb` | `root` | `root` |
| PostgreSQL | PostgreSQL | `postgres` | `postgres` | `postgres` |
| MongoDB | MongoDB | `mongodb` | `root` | `root` |

**Note:** MongoDB support in Adminer is via the `adminer-mongo` variant. The default Adminer image may not support MongoDB — use mongo-express for MongoDB.

---

## Phase 16 Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | `.env` extended with flags + ports | ✅ |
| 2 | Four Dockerfiles created | ✅ |
| 3 | Compose extended with four services (with profiles) | ✅ |
| 4 | Four images built | ✅ |
| 5 | Profile mechanism hides admin tools when disabled | ✅ |
| 6 | phpMyAdmin starts when profile activated | ✅ |
| 7 | phpMyAdmin auto-login issue discovered | ⚠️ |
| 8 | Fix applied (manual login form) | ✅ |
| 9 | All four profiles enabled → 16 containers | ✅ |
| 10 | phpMyAdmin browser test | ✅ |
| 11 | pgAdmin browser test | ✅ |
| 12 | mongo-express browser test | ✅ |
| 13 | Adminer browser test | ✅ |

**Phase 16 result: ✅ PASS**

---

## Final Environment State (After Phase 16)

**16 containers running:**

| Category | Container | Purpose | Host Ports |
|---|---|---|---|
| **Web** | `dev-nginx` | Primary web server | 80, 443 |
| | `dev-apache2` | Secondary web server | 8080, 8443 |
| **PHP** | `dev-php-fpm84` | PHP 8.4 FPM | (internal) |
| | `dev-php-fpm74` | PHP 7.4 FPM | (internal) |
| **Workspace** | `dev-workspace84` | CLI 8.4 | (internal) |
| | `dev-workspace74` | CLI 7.4 | (internal) |
| **Databases** | `dev-mysql` | MySQL 8.0.46 | 3306 |
| | `dev-mariadb` | MariaDB 11.8.9 | 3307 |
| | `dev-postgres` | PostgreSQL 15.19 | 5432 |
| | `dev-mongodb` | MongoDB 8.0.4 (ephemeral) | 27017 |
| | `dev-couchdb` | CouchDB 3.5.3 | 5984 |
| | `dev-redis` | Redis 7.4.11 | 6379 |
| **Admin** | `dev-phpmyadmin` | MySQL/MariaDB UI | 8081 |
| | `dev-pgadmin` | PostgreSQL UI | 8082 |
| | `dev-mongo-express` | MongoDB UI | 8083 |
| | `dev-adminer` | Multi-DB UI | 8084 |

**Total: 16 containers.**

---

## Carry-Forward Notes for Future Phases

1. **Profiles are additive.** Enabling more profiles never disables previously running ones. To remove containers for a disabled profile, `docker compose down` first.

2. **Default state (fresh clone):** `COMPOSE_PROFILES=` (empty) — no admin tools start. User must explicitly enable.

3. **pgAdmin Desktop mode** is fine for local dev. To add login, see the "Two Modes" section above — it's a one-flag change.

4. **phpMyAdmin manual login** means you type credentials each session. For convenience, you can prefill with `PMA_USER`/`PMA_PASSWORD` in compose — but that triggers auto-login (no form).

5. **mongo-express auto-auth** — no login screen. Uses the MongoDB credentials embedded in the `ME_CONFIG_MONGODB_URL` env var.

6. **Adminer supports** MySQL, MariaDB, PostgreSQL, MS SQL, Oracle, SQLite, and more. **MongoDB requires the `adminer-mongo` variant** — the standard Adminer image doesn't support it. Use mongo-express for MongoDB.

7. **CouchDB Fauxton** remains at `http://localhost:5984/_utils/` — built into CouchDB, no separate container.

8. **pgAdmin config persistence:** `data_files/pgadmin/` → `/var/lib/pgadmin`. If you add servers, they persist. If you `rm -rf data_files/pgadmin/`, pgAdmin resets to a clean state.

9. **All admin containers are on `dev-net`** — they reach the DBs by service name (`mysql`, `postgres`, etc.).

10. **`depends_on`** on each admin tool ensures the target DB is up before the tool starts. This is a soft guarantee (Compose doesn't wait for health), but adequate for dev.

11. **Admin tool images are not rebuilt often.** Their Dockerfiles only have `FROM`. If you want to update to a newer image version, run `docker compose build <service>` — it re-pulls the latest.

12. **Container names:** `dev-phpmyadmin`, `dev-pgadmin`, `dev-mongo-express`, `dev-adminer`.

13. **`docker compose ps` when profiles are enabled** shows all 16. When disabled, only the 12 base services.

---

## How to Enable / Disable Admin Tools

### Enable all four

```bash
cd ~/Projects01/docker-env
sed -i '' 's/^COMPOSE_PROFILES=.*/COMPOSE_PROFILES=phpmyadmin,pgadmin,mongo-express,adminer/' .env
docker compose up -d
```

### Disable all

```bash
sed -i '' 's/^COMPOSE_PROFILES=.*/COMPOSE_PROFILES=/' .env
docker compose down
docker compose up -d
```

### Enable only one

```bash
sed -i '' 's/^COMPOSE_PROFILES=.*/COMPOSE_PROFILES=phpmyadmin/' .env
docker compose up -d
```

### Pick and choose

```bash
sed -i '' 's/^COMPOSE_PROFILES=.*/COMPOSE_PROFILES=phpmyadmin,adminer/' .env
docker compose up -d
```

---

## Files and System Changes in Phase 16

**Files created:**

```
~/Projects01/docker-env/phpmyadmin/Dockerfile
~/Projects01/docker-env/pgadmin/Dockerfile
~/Projects01/docker-env/mongo-express/Dockerfile
~/Projects01/docker-env/adminer/Dockerfile
~/Projects01/docker-env/data_files/pgadmin/       (pgAdmin config persistence)
```

**Files modified:**

```
~/Projects01/docker-env/.env                    (COMPOSE_PROFILES + admin tool vars)
~/Projects01/docker-env/docker-compose.yml      (4 admin services with profiles)
```

**Docker objects created:**

- Images: `dev-env-phpmyadmin:latest`, `dev-env-pgadmin:latest`, `dev-env-mongo-express:latest`, `dev-env-adminer:latest`
- Containers: `dev-phpmyadmin`, `dev-pgadmin`, `dev-mongo-express`, `dev-adminer` (only when profiles active)

---

## Rollback Procedure

To undo Phase 16:

```bash
cd ~/Projects01/docker-env
sed -i '' 's/^COMPOSE_PROFILES=.*/COMPOSE_PROFILES=/' .env
docker compose down
docker rmi dev-env-phpmyadmin:latest dev-env-pgadmin:latest dev-env-mongo-express:latest dev-env-adminer:latest
rm -rf ~/Projects01/docker-env/phpmyadmin
rm -rf ~/Projects01/docker-env/pgadmin
rm -rf ~/Projects01/docker-env/mongo-express
rm -rf ~/Projects01/docker-env/adminer
rm -rf ~/Projects01/docker-env/data_files/pgadmin
# Remove admin tool section from .env
# Remove 4 admin service blocks from docker-compose.yml
```
---
## Summary of Phase 16:

- ✅ 13/13 checks passed
- ✅ 4 admin tools running via Docker Compose profiles
- ✅ phpMyAdmin with manual login (auto-login removed)
- ✅ pgAdmin in Desktop mode (no login) — full docs on how to switch to Server mode (with login)
- ✅ mongo-express with auto-auth
- ✅ Adminer for multi-DB access
- ✅ CouchDB Fauxton built-in (no extra container)
- ✅ Profile mechanism proven (12 vs 13 vs 16 containers based on flags)

**16 containers total**. The environment now has complete web-based admin tooling for all databases.

---

## Next Phase

**Phase 17 — PHP Workers (Queue/Background Jobs)**

Goal: add `php-worker84` and `php-worker74` — containers running Supervisor to manage queue workers (Laravel Horizon, custom workers, cron-like jobs).

Phase 17 will:
- Create `docker-env/php-worker84/Dockerfile` and `docker-env/php-worker74/Dockerfile`
- Configure `supervisord.conf` for each
- Extend `docker-compose.yml` with both services
- No host ports (internal only)
- Verify `supervisorctl status` shows programs running
- Verify workers can connect to Redis/DBs

**These are the last two containers before we move to backup (Phase 19) and cleanup (Phase 20).**

Phase 17 begins once this document is saved and confirmed.

---

**End of phase_16.md**