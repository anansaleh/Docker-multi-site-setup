# Phase 15 — Workspaces (CLI Containers)

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM)
**Host project root:** `~/Projects01/`
**Date completed:** 2026-10-17
**Status:** ✅ Complete — all checks passed

---

## Objective

Add `workspace84` and `workspace74` — CLI development containers with PHP, Composer, Git, and Node (via nvm). These containers replace the need to install PHP/Composer/Node/Git on the host, giving you a fully self-contained development environment.

**After Phase 15**, all development work happens inside one of these two workspaces:
- `docker compose exec workspace84 bash` → PHP 8.4 + Node 24 environment
- `docker compose exec workspace74 bash` → PHP 7.4 + Node 24 environment

---

## Final State After Phase 15

| Component | workspace84 | workspace74 |
|---|---|---|
| Container name | `dev-workspace84` | `dev-workspace74` |
| Image | `dev-env-workspace84:latest` | `dev-env-workspace74:latest` |
| Base image | `php-base:8.4` | `php-base:7.4` |
| PHP version | 8.4.26 | 7.4.33 |
| Composer | 2.10.3 | 2.10.3 |
| Git | 2.39.5 (Bookworm) | 2.30.2 (Bullseye) |
| Node versions | 24.22.0 (default), 22.23.3, 20.20.2 | 24.22.0 (default), 22.23.3, 20.20.2 |
| npm | 11.19.1 | 11.19.1 |
| yarn | 1.22.22 | 1.22.22 |
| pnpm | 12.14.0 | 12.14.0 |
| Working directory | `/var/www/html` | `/var/www/html` |
| Command | `tail -f /dev/null` (idle) | `tail -f /dev/null` (idle) |
| Host ports | None (internal-only) | None (internal-only) |
| Site mounts | ✅ Yes (all 3) | ✅ Yes (all 3) |
| `/home` mounts | ✅ Yes (client_data, server_side) | ✅ Yes |
| `/backup` mount | ✅ Yes | ✅ Yes |
| DB connectivity | ✅ All 6 services | ✅ All 6 services |

---

## What We Built

```
~/Projects01/docker-env/
├── workspace84/
│   └── Dockerfile
├── workspace74/
│   └── Dockerfile
└── docker-compose.yml             (extended: workspace84, workspace74)
```

---

## Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Base image | `php-base:8.4` / `php-base:7.4` | Inherits PHP, extensions, Composer, php.ini |
| Node install | nvm with Node 24, 22, 20 | Matches earlier design decision |
| Node default | 24 | Latest LTS |
| Node binary accessibility | **Symlinks in `/usr/local/bin/`** | Version-agnostic; works from any `docker compose exec` |
| Site mounts | Same as FPM containers | Full project visibility |
| `/home` mounts | Same as FPM containers | Shared library access |
| `/backup` mount | Added (new in Phase 15) | For DB dump/restore operations |
| Command | `tail -f /dev/null` | Container stays alive for `exec` |
| Container user | `root` | Dev convenience — no permission issues |
| Container ports | None | Internal-only; not exposed to host |
| Auto-source nvm | Yes (via `.bashrc`) | Allows `nvm use 22` in interactive shell |

**Note on `root` user:** Running as root inside the workspace is standard for local dev. Production PHP-FPM runs as `www-data`. We're keeping root for convenience — the workspace runs no untrusted code, only your own commands.

---

## The `workspace84/Dockerfile` (Final — After Fix)

```dockerfile
# ============================================================
# Workspace 8.4 — CLI development container
# Inherits from php-base:8.4 (PHP, Composer, extensions)
# Adds: nvm, Node 24/22/20, Yarn, pnpm
# ============================================================
FROM php-base:8.4

# Install Node version manager (nvm) and Node versions
RUN curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash

# Set up nvm environment variables
ENV NVM_DIR=/root/.nvm
ENV NODE_DEFAULT_VERSION=24

# Install Node versions (24, 22, 20) and set default
RUN bash -lc 'source $NVM_DIR/nvm.sh && \
    nvm install 24 && \
    nvm install 22 && \
    nvm install 20 && \
    nvm alias default 24 && \
    nvm use default'

# Install Yarn and pnpm globally (for Node 24)
RUN bash -lc 'source $NVM_DIR/nvm.sh && nvm use default && \
    npm install -g yarn pnpm'

# Create stable symlinks for all Node binaries (node, npm, npx, yarn, pnpm)
# so they work in any `docker compose exec` without sourcing nvm.
# This is version-agnostic — no hardcoded version number.
RUN bash -lc 'source $NVM_DIR/nvm.sh && nvm use default && \
    for bin in node npm npx yarn pnpm; do \
        ln -sf $(which $bin) /usr/local/bin/$bin; \
    done'

# Set nvm to auto-source in login shells (so users can switch versions with `nvm use 22` etc.)
RUN echo 'source $NVM_DIR/nvm.sh' >> /root/.bashrc

WORKDIR /var/www/html

# Keep the container alive
CMD ["tail", "-f", "/dev/null"]
```

**`workspace74/Dockerfile`** is identical except for the first line: `FROM php-base:7.4`.

### The PATH Fix Explained

**Initial bug:** The first version had this line:
```dockerfile
ENV PATH=$NVM_DIR/versions/node/v24.11.0/bin:$PATH
```

**Problem:** `v24.11.0` was a guess. The actual installed Node version (as of build) was **`v24.22.0`**. So `$PATH` pointed to a directory that didn't exist, and `node -v` failed with "executable file not found."

**Fix:** Symlinks:
```dockerfile
RUN bash -lc 'source $NVM_DIR/nvm.sh && nvm use default && \
    for bin in node npm npx yarn pnpm; do \
        ln -sf $(which $bin) /usr/local/bin/$bin; \
    done'
```

**Why this works:**
- `$(which $bin)` resolves to the actual installed path (e.g., `/root/.nvm/versions/node/v24.22.0/bin/node`)
- `ln -sf` creates a symlink at `/usr/local/bin/node` pointing there
- `/usr/local/bin` is always in the default PATH
- **No hardcoded version → survives any Node version bump**
- `nvm use 22` still works for interactive shells (symlinks won't change, but you can `bash -lc 'nvm use 22 && node -v'`)

---

## The `docker-compose.yml` — Workspace Services

```yaml
  workspace84:
    build:
      context: ./workspace84
    image: dev-env-workspace84
    container_name: dev-workspace84
    restart: unless-stopped
    volumes:
      - ${PROJECTS_ROOT}/sites/site1:/var/www/html/site1
      - ${PROJECTS_ROOT}/sites/site2:/var/www/html/site2
      - ${PROJECTS_ROOT}/default-host/v3client:/var/www/html/v3client
      - ${PROJECTS_ROOT}/default-host/client_data:/home/client_data
      - ${PROJECTS_ROOT}/default-host/server_side:/home/server_side
      - ${PROJECTS_ROOT}/backup_data:/backup
    environment:
      - TZ=${TZ}
    command: ["tail", "-f", "/dev/null"]
    networks:
      - dev-net

  workspace74:
    build:
      context: ./workspace74
    image: dev-env-workspace74
    container_name: dev-workspace74
    restart: unless-stopped
    volumes:
      - ${PROJECTS_ROOT}/sites/site1:/var/www/html/site1
      - ${PROJECTS_ROOT}/sites/site2:/var/www/html/site2
      - ${PROJECTS_ROOT}/default-host/v3client:/var/www/html/v3client
      - ${PROJECTS_ROOT}/default-host/client_data:/home/client_data
      - ${PROJECTS_ROOT}/default-host/server_side:/home/server_side
      - ${PROJECTS_ROOT}/backup_data:/backup
    environment:
      - TZ=${TZ}
    command: ["tail", "-f", "/dev/null"]
    networks:
      - dev-net
```

**Key points:**
- **No `ports:`** — workspaces are internal-only.
- **`command: ["tail", "-f", "/dev/null"]`** — overrides the base image's CMD (which would be `php-fpm`). Keeps the container alive waiting for `exec`.
- **`/backup` mount** — added in Phase 15. Needed for Phase 19 (backup/restore testing).

---

## Step-by-Step Record

### Step 15.1 & 15.2 — Dockerfiles created

Both `workspace84/Dockerfile` and `workspace74/Dockerfile` written initially with the buggy `ENV PATH=$NVM_DIR/versions/node/v24.11.0/bin` line (later fixed in Step 15.7-fix).

**Result:** ✅ Pass (with a fix needed).

---

### Step 15.3 — Compose extended

Added `workspace84` and `workspace74` services. `docker compose config --services` showed **12 services total**.

**Result:** ✅ Pass.

---

### Step 15.4 — Build

```bash
docker compose build workspace84 workspace74
```

**Output:** Both images built in 32 seconds total. Faster than expected (Node downloads were quick).

**Result:** ✅ Pass.

---

### Step 15.5 — Start

```bash
docker compose up -d workspace84 workspace74
docker compose ps
```

**Output:** Both containers started. **12 containers total** now running.

**Note:** Both workspaces show `9000/tcp` in the PORTS column — inherited from `php-fpm` image's `EXPOSE 9000`. Harmless since we don't use FPM in workspaces.

**Result:** ✅ Pass.

---

### Step 15.6 — PHP, Composer, Git verification

```bash
# workspace84
php -v       → PHP 8.4.26, Xdebug 3.5.3
composer -V  → 2.10.3
git --version → 2.39.5

# workspace74
php -v       → PHP 7.4.33, Xdebug 3.1.6
composer -V  → 2.10.3
git --version → 2.30.2
```

**Notable:** Git version differs — 2.39.5 (Bookworm) vs 2.30.2 (Bullseye). Both work.

**Result:** ✅ Pass.

---

### Step 15.7 — Node verification — **FAILED**

```bash
docker compose exec workspace84 node -v
# OCI runtime exec failed: exec: "node": executable file not found in $PATH
```

**Root cause:** The `ENV PATH=$NVM_DIR/versions/node/v24.11.0/bin` line pointed to a version directory that didn't exist. nvm installed **Node 24.22.0**, not 24.11.0.

**The `nvm ls` output confirmed this:**
```
       v20.20.2
       v22.23.3
->     v24.22.0     ← actual installed version
default -> 24 (-> v24.22.0)
```

**Result:** ⚠️ Failed — requiring fix.

---

### Step 15.7-fix — Fix both Dockerfiles

**Changes:**
- ❌ Removed: `ENV PATH=$NVM_DIR/versions/node/v24.11.0/bin:$PATH`
- ✅ Added: symlinks for `node`, `npm`, `npx`, `yarn`, `pnpm` → `/usr/local/bin/`

The symlink step:
```dockerfile
RUN bash -lc 'source $NVM_DIR/nvm.sh && nvm use default && \
    for bin in node npm npx yarn pnpm; do \
        ln -sf $(which $bin) /usr/local/bin/$bin; \
    done'
```

**Verification:**
```bash
grep -c "v24.11.0" workspace74/Dockerfile   # → 0
grep -c "for bin in node" workspace74/Dockerfile  # → 1
```

**Result:** ✅ Pass.

---

### Step 15.7-fix-2 & 15.7-fix-3 — Rebuild and recreate

```bash
docker compose build workspace84 workspace74
# 5.9s — earlier layers cached, only symlink layer re-ran

docker compose up -d --force-recreate workspace84 workspace74
# Both containers up
```

**Result:** ✅ Pass.

---

### Step 15.7-fix-4 — **Node verification (retry) — SUCCESS**

```bash
# workspace84
node -v  → v24.22.0
npm -v   → 11.19.1
yarn -v  → 1.22.22
pnpm -v  → 12.14.0

# workspace74
node -v  → v24.22.0
npm -v   → 11.19.1
yarn -v  → 1.22.22
pnpm -v  → 12.14.0
```

`nvm ls` on both:
```
       v20.20.2
       v22.23.3
->     v24.22.0
default -> 24 (-> v24.22.0)
```

**Result:** ✅ **Pass — the fix works.** All Node tools accessible via `docker compose exec` without sourcing nvm.

---

### Step 15.8 — Volume mounts verified

```bash
docker compose exec workspace84 ls /var/www/html/
# site1, site2, v3client ✅

docker compose exec workspace84 ls /home/
# client_data, server_side ✅

docker compose exec workspace84 ls /backup/
# (empty — no backups yet) ✅
```

**Result:** ✅ Pass.

**Note:** Site folders show as `root:root` inside containers (Docker Desktop UID mapping). PHP can read/write anyway — confirmed by earlier phases where PHP error logs worked.

---

### Step 15.9 — Database connectivity from workspace84

```bash
docker compose exec workspace84 php -r "new PDO('mysql:host=mysql;port=3306', 'root', 'root'); echo 'MySQL: OK';"
# → MySQL: OK
```

All 6 tests passed:

| Engine | Output |
|---|---|
| MySQL | `MySQL: OK` |
| MariaDB | `MariaDB: OK` |
| PostgreSQL | `PostgreSQL: OK` |
| MongoDB | `MongoDB: OK` |
| Redis | `Redis: 1` |
| CouchDB | `CouchDB: Welcome` |

**Note on Redis:** `$redis->ping()` in PHP's phpredis extension returns `true` (echoes as `1`), not `PONG`. Both mean "connected and authenticated."

**Result:** ✅ Pass.

---

### Step 15.10 — Database connectivity from workspace74

Same test suite — all 6 passed:

| Engine | Output |
|---|---|
| MySQL | `MySQL: OK` |
| MariaDB | `MariaDB: OK` |
| PostgreSQL | `PostgreSQL: OK` |
| MongoDB | `MongoDB: OK` |
| Redis | `Redis: 1` |
| CouchDB | `CouchDB: Welcome` |

**Result:** ✅ Pass.

---

### Step 15.11 — Shell environment verified

```bash
docker compose exec workspace84 bash -c 'pwd && whoami'
# /var/www/html
# root

docker compose exec workspace74 bash -c 'pwd && whoami'
# /var/www/html
# root
```

Sites visible in both.

**Note on directory permissions:**
- `workspace84`: `drwxrwxrwt` (1777 — sticky bit + rwx)
- `workspace74`: `drwxrwxrwx` (777 — full rwx)

Different base OS behavior. Doesn't affect functionality.

**Result:** ✅ Pass.

---

## Phase 15 Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | `workspace84/Dockerfile` created | ✅ |
| 2 | `workspace74/Dockerfile` created | ✅ |
| 3 | Compose extended with both services | ✅ |
| 4 | Both images built | ✅ |
| 5 | Both containers started | ✅ |
| 6 | PHP, Composer, Git verified in both | ✅ |
| 7 | Node/npm/yarn/pnpm — initial FAILED (PATH) | ⚠️ |
| 8 | Fix applied (symlinks) | ✅ |
| 9 | Rebuilt + recreated | ✅ |
| 10 | **Node 24.22.0 + npm 11.19.1 + yarn 1.22.22 + pnpm 12.14.0 all work** | ✅ |
| 11 | Volume mounts verified | ✅ |
| 12 | DB connectivity from workspace84 (6 services) | ✅ |
| 13 | DB connectivity from workspace74 (6 services) | ✅ |
| 14 | Shell environment verified | ✅ |

**Phase 15 result: ✅ PASS**

---

## How to Use the Workspaces

### Enter a workspace

```bash
# PHP 8.4 workspace (Node 24 default)
docker compose exec workspace84 bash

# PHP 7.4 workspace
docker compose exec workspace74 bash
```

### Common commands inside

```bash
# PHP
php -v
php artisan ...               # if Laravel
phpunit                       # if PHPUnit installed
php -m                        # extensions

# Composer
composer install
composer require vendor/package
composer dump-autoload

# Node
node -v                       # v24.22.0
nvm use 22                    # switch to Node 22 (interactive shell)
nvm use 20                    # switch to Node 20
nvm use default               # back to Node 24
npm install
yarn install
pnpm install

# Git
git status
git commit -m "message"
git push

# Databases
mysql -h mysql -uroot -proot
mariadb -h mariadb -uroot -proot
psql -h postgres -Upostgres
mongosh mongodb://root:root@mongodb:27017/?authSource=admin
redis-cli -h redis -a root
curl -u admin:admin http://couchdb:5984/

# Site navigation
cd /var/www/html/site1
cd /var/www/html/site2
cd /var/www/html/v3client
```

### One-liners (no need to enter shell)

```bash
docker compose exec workspace84 composer install --working-dir=/var/www/html/site1
docker compose exec workspace84 npm install --prefix /var/www/html/site1
```

---

## Node Version Switching

**Symlinks vs nvm:**
- `/usr/local/bin/node` → symlink to Node 24 (default)
- `nvm use 22` inside a shell changes the shell's PATH but **doesn't update the symlink**
- So plain `node -v` always shows 24.22.0
- To use Node 22 for a project, either:
  - Run `bash -lc 'nvm use 22 && npm install'` (one-off)
  - Or create a shell wrapper script that sources nvm and runs the command

**For most projects, the default Node 24 is fine.** `.nvmrc` files still work if you `nvm use` in an interactive shell.

---

## DB Connection Details From Workspaces

| Engine | Hostname | Port | Credentials |
|---|---|---|---|
| MySQL | `mysql` | 3306 | `root` / `root` |
| MariaDB | `mariadb` | 3306 | `root` / `root` |
| PostgreSQL | `postgres` | 5432 | `postgres` / `postgres` |
| MongoDB | `mongodb` | 27017 | `root` / `root` (auth DB: `admin`) |
| CouchDB | `couchdb` | 5984 | `admin` / `admin` |
| Redis | `redis` | 6379 | password: `root` |

---

## Final Environment State (After Phase 15)

**12 containers running:**

| Category | Container | Purpose | Host Ports |
|---|---|---|---|
| **Web** | `dev-nginx` | Primary web server | 80, 443 |
| | `dev-apache2` | Secondary web server | 8080, 8443 |
| **PHP** | `dev-php-fpm84` | PHP 8.4 FPM | (internal) |
| | `dev-php-fpm74` | PHP 7.4 FPM | (internal) |
| **Workspace** | `dev-workspace84` | CLI 8.4 (Node 24/22/20) | (internal) |
| | `dev-workspace74` | CLI 7.4 (Node 24/22/20) | (internal) |
| **Databases** | `dev-mysql` | MySQL 8.0.46 | 3306 |
| | `dev-mariadb` | MariaDB 11.8.9 | 3307 |
| | `dev-postgres` | PostgreSQL 15.19 | 5432 |
| | `dev-mongodb` | MongoDB 8.0.4 (ephemeral) | 27017 |
| | `dev-couchdb` | CouchDB 3.5.3 | 5984 |
| | `dev-redis` | Redis 7.4.11 | 6379 |

**Sites served:**
- `site1.test` → PHP 8.4
- `site2.test` → PHP 7.4
- `dev.test` / `localhost` → PHP 8.4 (default)

Both web servers serve both sites (Nginx on 80/443, Apache on 8080/8443).

---

## Carry-Forward Notes for Future Phases

1. **Workspace containers run as `root`.** This is intentional for dev convenience. All PHP tooling works normally.

2. **Symlink approach for Node is version-agnostic.** If Node 24 gets updated (rebuild), new symlinks are created automatically.

3. **`nvm use` works in interactive shells** but doesn't update the symlinks. Plain `node -v` in a fresh `exec` always shows 24.22.0.

4. **`/backup` mount is now wired** — `~/Projects01/backup_data/` → `/backup` in both workspaces. Will be used in Phase 19.

5. **Full DB connectivity verified from both workspaces.** Every driver works, every container reachable via DNS.

6. **Directory permissions differ** between the two base OSes:
   - workspace84 (`/var/www/html`): `1777` (sticky + rwx)
   - workspace74 (`/var/www/html`): `777` (full rwx)
   
   Both work. If you ever hit permission problems on only one workspace, this is the cause.

7. **Container names:** `dev-workspace84`, `dev-workspace74`.

8. **Image IDs:** `dev-env-workspace84:latest`, `dev-env-workspace74:latest`.

9. **Workspaces have no host ports.** They're reached via `docker compose exec`.

10. **The `docker compose exec` TTY quirk:** if you pipe a heredoc into `docker compose exec`, add `-T` to disable TTY allocation. Example:
    ```bash
    docker compose exec -T workspace84 bash <<'EOF'
    ...
    EOF
    ```
    Same applies to any `exec` command in this environment.

11. **`npm install -g yarn pnpm` is a rebuild trigger.** If you ever want a specific yarn/pnpm version, edit the workspace Dockerfile and rebuild. Not urgent.

---

## Files and System Changes in Phase 15

**Files created:**

```
~/Projects01/docker-env/workspace84/Dockerfile
~/Projects01/docker-env/workspace74/Dockerfile
```

**Files modified:**

```
~/Projects01/docker-env/docker-compose.yml      (workspace84 + workspace74 services added)
```

**Docker objects created:**

- Images: `dev-env-workspace84:latest`, `dev-env-workspace74:latest`
- Containers: `dev-workspace84`, `dev-workspace74`

---

## Rollback Procedure

To undo Phase 15:

```bash
cd ~/Projects01/docker-env
docker compose down workspace84 workspace74
docker rmi dev-env-workspace84:latest dev-env-workspace74:latest
rm -rf ~/Projects01/docker-env/workspace84
rm -rf ~/Projects01/docker-env/workspace74
# Remove workspace84 + workspace74 services from docker-compose.yml
```

No data loss — workspaces have no persistent state (all data lives in mounted folders).

---
## Summary of Phase 15:

- ✅ 14/14 checks passed (one failure required a fix)
- ✅ Both workspaces fully functional
- ✅ PHP, Composer, Git, Node 24/22/20, Yarn, pnpm all working
- ✅ Full DB connectivity from both workspaces
- ✅ Volume mounts verified (sites, /home, /backup)
- ✅ The PATH mismatch bug found and fixed with symlinks

**12 containers running**. The environment is now feature-complete for development — everything you need to build PHP projects.
---

## Next Phase

**Phase 16 — Admin Tools (Flag-Gated)**

Goal: add phpMyAdmin (for MySQL/MariaDB), pgAdmin (for PostgreSQL), mongo-express (for MongoDB), and Adminer (multi-DB) as optional web UIs.

**Key features:**
- Each behind a `.env` flag (`ENABLE_PHPMYADMIN`, `ENABLE_PGADMIN`, `ENABLE_MONGO_EXPRESS`, `ENABLE_ADMINER`)
- Each on its own host port (8081, 8082, 8083, 8084)
- When flag is `false`, the container doesn't start
- CouchDB's Fauxton is already built-in — no extra container needed

Phase 16 begins once this document is saved and confirmed.

---

**End of phase_15.md**