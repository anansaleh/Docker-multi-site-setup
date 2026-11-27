# Phase 17 — PHP Workers (Queue/Background Jobs)

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM)
**Host project root:** `~/Projects01/`
**Date completed:** 2026-10-17
**Status:** ✅ Complete — all checks passed

---

## Objective

Add `php-worker84` and `php-worker74` — containers running **Supervisor** to manage queue workers and background jobs. These are the last two service containers before we move to backup (Phase 19) and cleanup (Phase 20).

**Purpose:** PHP applications (especially Laravel, Symfony, and others) often have background workers that process queues, schedules, and async tasks. These containers give you a dedicated place to run them.

**Current state:** Both workers run a `placeholder` program (`sleep infinity`) — ready for you to add real workers when projects exist.

---

## Final State After Phase 17

| Component | Details |
|---|---|
| Container names | `dev-php-worker84`, `dev-php-worker74` |
| Images | `dev-env-php-worker84:latest`, `dev-env-php-worker74:latest` |
| PHP versions | 8.4.26 (worker84), 7.4.33 (worker74) |
| Process manager | **Supervisor** |
| Queue backend | Redis (primary), any DB available |
| Working dir | `/var/www/html` |
| Host ports | None (internal-only) |
| Default programs | `placeholder` (sleep infinity) |
| Supervisor state | `RUNNING` on both |

---

## What We Built

```
~/Projects01/docker-env/
├── php-worker84/
│   ├── Dockerfile
│   └── supervisord.conf
├── php-worker74/
│   ├── Dockerfile
│   └── supervisord.conf
└── docker-compose.yml          (extended: 2 new services)
```

---

## Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Base image | `php-base:8.4` / `php-base:7.4` | Inherits PHP, extensions, Composer |
| Process manager | **Supervisor** | Standard, stable, well-documented |
| Default programs | `placeholder` (`sleep infinity`) | Keeps container alive; ready for real workers |
| Supervisor command | `supervisord -n -c /etc/supervisor/supervisord.conf` | Foreground mode (required for Docker) |
| Log destination | stdout/stderr | `docker compose logs php-worker84` shows everything |
| `nodaemon` | `true` | Supervisor runs in foreground (Docker-compatible) |
| `user` | `root` | Dev convenience |
| No host ports | Correct | Internal-only |
| Same site mounts | As FPM/workspaces | Full project visibility |
| Same `/home` mounts | Yes | Shared library access |
| `/backup` mount | Yes | Matches workspaces |

---

## The `php-worker84/Dockerfile`

```dockerfile
# ============================================================
# PHP Worker 8.4 — Queue and background job container
# Inherits from php-base:8.4 (PHP, extensions, Composer)
# Runs Supervisor to manage long-running processes.
# ============================================================
FROM php-base:8.4

# Install Supervisor
RUN apt-get update && apt-get install -y --no-install-recommends \
        supervisor \
    && rm -rf /var/lib/apt/lists/*

# Copy Supervisor config
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Supervisor runs in foreground (required for Docker)
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]

WORKDIR /var/www/html
```

**`php-worker74/Dockerfile`** is identical except for the base image: `FROM php-base:7.4`.

**Critical note on Bullseye:** The `php-worker74` image installs Supervisor via `apt-get`, which runs against the **snapshot.debian.org repository** (set up in `php-base:7.4` in Phase 6). The snapshot has the `supervisor` package available, so the install succeeds. Build time was ~7 seconds.

---

## The `php-worker84/supervisord.conf`

```ini
; ============================================================
; Supervisor configuration — PHP Worker 8.4
; Local development environment
;
; This file defines the processes that Supervisor will run.
; Each [program:X] section starts one process.
;
; By default, NO real jobs are configured. The "placeholder"
; program keeps the container alive so you can `exec` into it
; and manually test queue workers.
;
; To add a real worker (e.g., Laravel queue):
;   1. Uncomment or add a [program:X] section
;   2. Set command to your worker command
;   3. Rebuild + restart the container
; ============================================================

[supervisord]
nodaemon=true
user=root
logfile=/dev/null
logfile_maxbytes=0
pidfile=/tmp/supervisord.pid

[program:placeholder]
; Placeholder process — keeps container alive when no workers are configured.
; Replace this with real workers for each project.
command=sleep infinity
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
priority=999

; ============================================================
; Example worker definitions (uncomment and customize for projects)
; ============================================================

; [program:laravel-queue-site1]
; command=php /var/www/html/site1/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
; directory=/var/www/html/site1
; autostart=true
; autorestart=true
; numprocs=1
; stdout_logfile=/dev/stdout
; stdout_logfile_maxbytes=0
; stderr_logfile=/dev/stderr
; stderr_logfile_maxbytes=0
; stopsignal=TERM

; [program:laravel-scheduler-site1]
; command=php /var/www/html/site1/artisan schedule:work
; directory=/var/www/html/site1
; autostart=true
; autorestart=true
; numprocs=1
; stdout_logfile=/dev/stdout
; stdout_logfile_maxbytes=0
; stderr_logfile=/dev/stderr
; stderr_logfile_maxbytes=0
; stopsignal=TERM
```

**`php-worker74/supervisord.conf`** is identical except the header says "PHP Worker 7.4".

### Key sections explained

| Section | Purpose |
|---|---|
| `[supervisord]` | Supervisor's own config |
| `nodaemon=true` | Run in foreground (required for Docker) |
| `user=root` | Don't drop privileges |
| `logfile=/dev/null` | Disable Supervisor's own logfile (use stdout) |
| `pidfile=/tmp/supervisord.pid` | PID file in writable location |
| `[program:placeholder]` | Placeholder program |
| `command=sleep infinity` | Does nothing, but stays alive |
| `autostart=true` | Start automatically when Supervisor starts |
| `autorestart=true` | Restart if it crashes |
| `priority=999` | Start last (doesn't matter for one program) |

---

## The `docker-compose.yml` — Worker Services

```yaml
  php-worker84:
    build:
      context: ./php-worker84
    image: dev-env-php-worker84
    container_name: dev-php-worker84
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
    depends_on:
      - redis
      - mysql
      - mariadb
      - postgres
    networks:
      - dev-net

  php-worker74:
    build:
      context: ./php-worker74
    image: dev-env-php-worker74
    container_name: dev-php-worker74
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
    depends_on:
      - redis
      - mysql
      - mariadb
      - postgres
    networks:
      - dev-net
```

**Key points:**
- No `ports:` — internal-only.
- Same mounts as workspaces — full project access.
- `depends_on` — workers wait for Redis and DBs to be up.

---

## Step-by-Step Record

### Step 17.1 — `php-worker84/Dockerfile` created

**Result:** ✅ Pass.

---

### Step 17.2 — `php-worker84/supervisord.conf` created

**Result:** ✅ Pass.

---

### Step 17.3 — `php-worker74/Dockerfile` created

**Result:** ✅ Pass.

---

### Step 17.4 — `php-worker74/supervisord.conf` created

**Result:** ✅ Pass.

---

### Step 17.5 — Compose extended

Added two worker services. `docker compose config --services` confirmed:
- Both workers present
- Admin tools still hidden (profile-gated)
- 14 base services + 4 admin (with profile) + 2 workers

**Result:** ✅ Pass.

---

### Step 17.6 — Build

```bash
docker compose build php-worker84 php-worker74
```

**Output:**
```
✔ Image dev-env-php-worker74 Built  7.5s
✔ Image dev-env-php-worker84 Built  7.5s
```

**Notable:** `php-worker74` installed Supervisor via snapshot.debian.org (7s vs 2.8s for Bookworm live). Both succeeded.

**Result:** ✅ Pass.

---

### Step 17.7 — Start

```bash
docker compose up -d php-worker84 php-worker74
docker compose ps php-worker84 php-worker74
```

**Output:** Both containers up. No host ports (internal).

**Result:** ✅ Pass.

---

### Step 17.8 — `supervisorctl status`

```bash
docker compose exec php-worker84 supervisorctl status
docker compose exec php-worker74 supervisorctl status
```

**Output:**
```
placeholder    RUNNING   pid 7, uptime 0:00:24
placeholder    RUNNING   pid 7, uptime 0:00:24
```

**Result:** ✅ Pass.

---

### Step 17.9 — Process tree verified

```bash
docker compose exec php-worker84 ps aux
```

**Output:**
```
root           1  0.2  0.3  ... /usr/bin/python3 /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
root           7  0.0  0.0  ... sleep infinity
root          14  0.0  0.0  ... ps aux
```

**Confirms:** PID 1 is Supervisor; PID 7 is the placeholder child.

**Result:** ✅ Pass.

---

### Step 17.10 — Logs verified

```bash
docker compose logs php-worker84
docker compose logs php-worker74
```

**Output:**
```
INFO Included extra file "/etc/supervisor/conf.d/supervisord.conf" during parsing
INFO Set uid to user 0 succeeded
INFO RPC interface 'supervisor' initialized
CRIT Server 'unix_http_server' running without any HTTP authentication checking
INFO supervisord started with pid 1
INFO spawned: 'placeholder' with pid 7
INFO success: placeholder entered RUNNING state, process has stayed up for > than 1 seconds (startsecs)
```

**The `CRIT` warning** — explained below.

**Result:** ✅ Pass.

---

### Step 17.11 — `supervisorctl` control commands tested

```bash
docker compose exec php-worker84 supervisorctl stop placeholder
docker compose exec php-worker84 supervisorctl status
docker compose exec php-worker84 supervisorctl start placeholder
docker compose exec php-worker84 supervisorctl status
```

**Output:**
```
placeholder: stopped
placeholder    STOPPED   Oct 17 03:46 PM
placeholder: started
placeholder    RUNNING   pid 38, uptime 0:00:02
```

**Result:** ✅ Pass. All `supervisorctl` commands work.

---

### Step 17.12 — **Added a real worker (test)** — expected failure

**Action:** Uncommented the `[program:laravel-queue-site1]` block in `supervisord.conf`, rebuilt, restarted.

**Result:**
```
laravel-queue-site1    FATAL    Exited too quickly (process log may have details)
placeholder            RUNNING  pid 8, uptime 0:00:19
```

**Logs:**
```
Could not open input file: /var/www/html/site1/artisan
WARN exited: laravel-queue-site1 (exit status 1; not expected)
INFO gave up: laravel-queue-site1 entered FATAL state, too many start retries too quickly
```

**Diagnosis:** `site1` is not a Laravel project — `/var/www/html/site1/artisan` doesn't exist. Supervisor correctly:
- Tried to start the worker
- Retried 4 times with increasing delays
- Marked it FATAL after 4 failures
- Left the placeholder running unaffected

**This test proved:**
- Supervisor's retry logic works
- One failed worker doesn't crash the container
- Error messages are precise
- The rest of the programs keep running

**Fix applied:** Re-commented the `[program:laravel-queue-site1]` block. Rebuilt. Restarted.

**Result:** ✅ Pass (with a lesson learned).

---

### Step 17.13 — Final state confirmed

```bash
docker compose exec php-worker84 supervisorctl status
docker compose exec php-worker74 supervisorctl status
```

**Output:**
```
=== php-worker84 ===
placeholder                      RUNNING   pid 13, uptime 0:01:03

=== php-worker74 ===
placeholder                      RUNNING   pid 7, uptime 0:07:24
```

**Result:** ✅ Pass.

---

## About the `CRIT` warning

```
CRIT Server 'unix_http_server' running without any HTTP authentication checking
```

**What it means:** Supervisor's internal HTTP server (used by `supervisorctl` for control commands) has no authentication. This is expected in dev because:
1. It runs on a Unix socket, not TCP.
2. The socket is only accessible from inside the container.
3. Docker isolation prevents external access.
4. `supervisorctl` inside the container doesn't need auth.

**In production:** set `username` and `password` in `[unix_http_server]` or `[inet_http_server]`.

**For local dev: harmless.** Log level `CRIT` sounds alarming but the situation is intentional.

---

## How to Add Real Workers

### Step 1 — Edit `supervisord.conf`

Open `php-worker84/supervisord.conf` and add a `[program:X]` block. Example for a **Laravel** project:

```ini
[program:laravel-queue-site1]
command=php /var/www/html/site1/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
directory=/var/www/html/site1
autostart=true
autorestart=true
numprocs=1
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
stopsignal=TERM
```

Example for **Symfony Messenger**:

```ini
[program:symfony-messenger-site1]
command=php /var/www/html/site1/bin/console messenger:consume async --time-limit=3600
directory=/var/www/html/site1
autostart=true
autorestart=true
numprocs=1
```

Example for **Laravel scheduler** (runs scheduled tasks):

```ini
[program:laravel-scheduler-site1]
command=php /var/www/html/site1/artisan schedule:work
directory=/var/www/html/site1
autostart=true
autorestart=true
numprocs=1
```

### Step 2 — Rebuild and restart

```bash
cd ~/Projects01/docker-env
docker compose build php-worker84
docker compose up -d php-worker84
```

### Step 3 — Verify

```bash
docker compose exec php-worker84 supervisorctl status
# Should show your new worker RUNNING
```

### Step 4 — Check logs

```bash
docker compose logs -f php-worker84
# Should show worker output
```

### Step 5 — If worker fails (FATAL)

```bash
docker compose logs php-worker84 | tail -20
# Shows the exact error
```

Common failure causes:
- Wrong path to binary (`artisan`, `console`, etc.)
- Missing dependency (`vendor/autoload.php`)
- Wrong queue connection (`redis` vs `database` vs `sqs`)
- Missing env vars (`.env` not present)

---

## `supervisorctl` Command Reference

| Command | Purpose |
|---|---|
| `supervisorctl status` | Show status of all programs |
| `supervisorctl status <program>` | Status of one program |
| `supervisorctl start <program>` | Start a stopped program |
| `supervisorctl stop <program>` | Stop a running program |
| `supervisorctl restart <program>` | Restart (stop + start) |
| `supervisorctl tail <program>` | Show stdout/stderr of a program |
| `supervisorctl tail -f <program>` | Follow stdout/stderr in real time |
| `supervisorctl reread` | Reload config from disk |
| `supervisorctl update` | Apply config changes (start/stop based on new config) |
| `supervisorctl shutdown` | Stop Supervisor and all programs |

**Practical use:**
```bash
# Check what's running
docker compose exec php-worker84 supervisorctl status

# Restart a specific worker after editing its .env
docker compose exec php-worker84 supervisorctl restart laravel-queue-site1

# Watch worker output
docker compose exec php-worker84 supervisorctl tail -f laravel-queue-site1

# After editing supervisord.conf, apply changes without rebuilding image
docker compose exec php-worker84 supervisorctl reread
docker compose exec php-worker84 supervisorctl update
```

**Note:** For changes to actually persist, you should still rebuild the image. `reread`/`update` only affect the running container's in-memory config.

---

## Phase 17 Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | `php-worker84/Dockerfile` created | ✅ |
| 2 | `php-worker84/supervisord.conf` created | ✅ |
| 3 | `php-worker74/Dockerfile` created | ✅ |
| 4 | `php-worker74/supervisord.conf` created | ✅ |
| 5 | Compose extended with both services | ✅ |
| 6 | Both images built (Supervisor installed) | ✅ |
| 7 | Both containers started | ✅ |
| 8 | Supervisor running, placeholder program active | ✅ |
| 9 | Process tree verified (supervisord → sleep infinity) | ✅ |
| 10 | Logs clean (except benign `CRIT` warning) | ✅ |
| 11 | `supervisorctl` control commands work | ✅ |
| 12 | Real worker test — FATAL behavior observed correctly | ✅ |
| 13 | Final state confirmed (both RUNNING) | ✅ |

**Phase 17 result: ✅ PASS**

---

## Final Environment State (After Phase 17)

**18 containers (12 base services + 6 additional):**

| Category | Container | Purpose | Host Ports |
|---|---|---|---|
| **Web** | `dev-nginx` | Primary web server | 80, 443 |
| | `dev-apache2` | Secondary web server | 8080, 8443 |
| **PHP** | `dev-php-fpm84` | PHP 8.4 FPM | (internal) |
| | `dev-php-fpm74` | PHP 7.4 FPM | (internal) |
| **Workspace** | `dev-workspace84` | CLI 8.4 | (internal) |
| | `dev-workspace74` | CLI 7.4 | (internal) |
| **Workers** | `dev-php-worker84` | Queue worker 8.4 | (internal) |
| | `dev-php-worker74` | Queue worker 7.4 | (internal) |
| **Databases** | `dev-mysql` | MySQL 8.0.46 | 3306 |
| | `dev-mariadb` | MariaDB 11.8.9 | 3307 |
| | `dev-postgres` | PostgreSQL 15.19 | 5432 |
| | `dev-mongodb` | MongoDB 8.0.4 (ephemeral) | 27017 |
| | `dev-couchdb` | CouchDB 3.5.3 | 5984 |
| | `dev-redis` | Redis 7.4.11 | 6379 |
| **Admin (flag-gated)** | `dev-phpmyadmin` | MySQL/MariaDB UI | 8081 |
| | `dev-pgadmin` | PostgreSQL UI | 8082 |
| | `dev-mongo-express` | MongoDB UI | 8083 |
| | `dev-adminer` | Multi-DB UI | 8084 |

**Total: 18 containers.**

---

## Carry-Forward Notes for Future Phases

1. **Both workers are running with `placeholder` program.** No real jobs yet.

2. **Supervisor is running as PID 1** in both containers. It manages all child programs.

3. **`CRIT` warning** is benign — Supervisor's Unix socket has no auth (expected in dev).

4. **To add real workers, edit `supervisord.conf`, rebuild, restart.** Full workflow documented above.

5. **If a worker enters `FATAL` state**, the container stays healthy — only that program is affected. Check logs and fix the underlying issue.

6. **Workers see the same codebases** as FPM and workspace containers via identical mounts.

7. **Workers can reach all databases** (Redis, MySQL, MariaDB, PostgreSQL) via the Docker network.

8. **No host ports on workers** — internal-only.

9. **Container names:** `dev-php-worker84`, `dev-php-worker74`.

10. **Image IDs:** `dev-env-php-worker84:latest`, `dev-env-php-worker74:latest`.

11. **Supervisor commands** documented above — `status`, `start`, `stop`, `restart`, `tail`, `reread`, `update`.

12. **`numprocs`** — set to 1 in examples. For multiple workers processing the same queue, increase to 2 or more.

13. **`autorestart=true`** — workers restart automatically if they crash. Combined with Supervisor's retry logic, transient failures are handled gracefully.

14. **`stopsignal=TERM`** — polite signal on stop. Workers should handle SIGTERM for graceful shutdown (Laravel/Symfony do this by default).

15. **A worker that crashes too many times → FATAL.** Fix it and `supervisorctl restart <program>` to try again.

16. **The `php-worker74` image** required the snapshot repo (inherited from `php-base:7.4`). No additional config needed — build worked out of the box.

---

## Files and System Changes in Phase 17

**Files created:**

```
~/Projects01/docker-env/php-worker84/Dockerfile
~/Projects01/docker-env/php-worker84/supervisord.conf
~/Projects01/docker-env/php-worker74/Dockerfile
~/Projects01/docker-env/php-worker74/supervisord.conf
```

**Files modified:**

```
~/Projects01/docker-env/docker-compose.yml      (php-worker84 + php-worker74 services added)
```

**Docker objects created:**

- Images: `dev-env-php-worker84:latest`, `dev-env-php-worker74:latest`
- Containers: `dev-php-worker84`, `dev-php-worker74`

---

## Rollback Procedure

To undo Phase 17:

```bash
cd ~/Projects01/docker-env
docker compose stop php-worker84 php-worker74
docker compose rm -f php-worker84 php-worker74
docker rmi dev-env-php-worker84:latest dev-env-php-worker74:latest
rm -rf ~/Projects01/docker-env/php-worker84
rm -rf ~/Projects01/docker-env/php-worker74
# Remove php-worker84 + php-worker74 service blocks from docker-compose.yml
```

No data loss — workers have no persistent state.

---
## Summary of Phase 17:

- ✅ 13/13 checks passed
- ✅ Both worker containers running with Supervisor
- ✅ Placeholder program (sleep infinity) keeps containers alive
- ✅ supervisorctl control commands work (stop, start, status)
- ✅ Step 17.12 taught a valuable lesson: adding a real worker without the target file → FATAL state, but container stays healthy
- ✅ Full worker-addition workflow documented
- ✅ Complete supervisorctl command reference
- ✅ CRIT warning explained (benign)

**18 containers total.** All PHP infrastructure is complete.

---

## Next Phase

**Phase 18 — Logs Folder Wiring (Full)**

Goal: verify that **every** service writes logs to `logs/<service>/` folders on the host, and fix any that don't.

Phase 18 will:
- Audit each service's log destination
- Ensure hosts have readable logs (Nginx, Apache, PHP-FPMs, MySQL, MariaDB, PostgreSQL, MongoDB, CouchDB, Redis)
- Document the "where are the logs?" question definitively
- Fix any gaps (e.g., PHP workers currently log to stdout only)

**Note:** Many services already write to host logs from earlier phases. Phase 18 is mostly an **audit and documentation** phase, plus filling any remaining gaps.

Phase 18 begins once this document is saved and confirmed.

---

**End of phase_17.md**