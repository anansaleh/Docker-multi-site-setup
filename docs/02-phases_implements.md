# Implementation Plan — Phased, Testable

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS
**Status:** Ready for execution — no code written yet

---

## Guiding Principles

1. **Each phase must be verifiable on its own.** A phase is "done" when you can run a specific test and see a specific result.
2. **Never build on an unverified layer.** If Phase N fails, we fix it before Phase N+1.
3. **Every phase ends with a checklist** — you run it, confirm pass/fail, and only then do we move on.
4. **No phase introduces more than one new concept.** If a phase does two new things, it gets split.
5. **Rollback is cheap.** Each phase can be torn down (`docker compose down`) without affecting already-working phases.

---

## Phase 0 — Host Preparation & Prerequisites

**Goal:** Make sure your macOS host is ready before any Docker file exists.

**What we set up:**
- Confirm Docker Desktop is installed and running (with VirtioFS enabled).
- Confirm `docker compose` v2 is available.
- Install `mkcert` (via Homebrew) and its local CA.
- Confirm `/etc/hosts` is writable (or prepare to use `sudo` for hostname entries).
- Create the top-level `Projects/` folder structure (empty folders only).
- Confirm your Docker Desktop resource allocation (CPU/RAM) is adequate for ~17 containers.

**Test / verify:**
- `docker --version` and `docker compose version` return expected output.
- `mkcert -CAROOT` prints a valid path, and `mkcert -install` succeeds.
- `docker run --rm hello-world` succeeds.
- The `Projects/` tree exists with all top-level folders present (but empty).

**Deliverable:** A working Docker + mkcert + folder skeleton.

**Rollback:** None needed — nothing destructive.

---

## Phase 1 — DNS & Hostname Resolution for `*.local`

**Goal:** Make `site1.local`, `site2.local`, `dev.local` resolve to `127.0.0.1` on your Mac.

**Why first:** Without this, nothing else can be tested by hostname. And it's independent of Docker — a pure host concern.

**What we do:**
- Decide the mechanism: `/etc/hosts` entries (simplest) vs `dnsmasq` (scales better).
  - Recommendation for your case: **`/etc/hosts`** — you have a fixed, small set of hostnames.
- Add entries for `site1.local`, `site2.local`, `dev.local`, `localhost.local` (optional).
- Confirm resolution with `ping` / `dig` / `curl`.

**Test / verify:**
- `ping site1.local` resolves to `127.0.0.1`.
- `ping site2.local` resolves to `127.0.0.1`.
- `ping dev.local` resolves to `127.0.0.1`.
- `curl http://site1.local` fails with connection refused (expected — nothing is listening yet).

**Deliverable:** Hostnames resolve. Nothing serves yet.

**Rollback:** Remove entries from `/etc/hosts`.

---

## Phase 2 — SSL Certificates (mkcert)

**Goal:** Generate the wildcard `*.local` cert and the `localhost`/`127.0.0.1` cert, and place them in `ssl_files/`.

**Why before web servers:** The web servers will mount these on startup. Generating them first avoids a "missing cert" failure in Phase 3.

**What we do:**
- Run mkcert to produce:
  - `wildcard.local.crt` + `wildcard.local.key` for `*.local` and `local` itself.
  - `localhost.crt` + `localhost.key` for `localhost`, `127.0.0.1`, `::1`.
- Place them in `docker-env/ssl_files/`.
- Verify browser trust (optional: open a test TLS server, or defer to Phase 3).

**Test / verify:**
- Files exist in `ssl_files/` with correct names.
- `openssl x509 -in wildcard.local.crt -text -noout` shows `*.local` in SAN.
- `openssl x509 -in localhost.crt -text -noout` shows `localhost` + `127.0.0.1`.
- (Optional) `mkcert -install` was run so the CA is trusted by your Mac's keychain.

**Deliverable:** Valid, trusted certs ready to mount.

**Rollback:** Delete cert files; re-run mkcert anytime.

---

## Phase 3 — Nginx Only (No PHP)

**Goal:** Stand up Nginx as the primary web server, serving **static HTML** only, over HTTP and HTTPS, with all three vhosts (`site1.local`, `site2.local`, `dev.local`).

**Why first among Docker phases:** It's the simplest possible container. No PHP, no DB. If this works, the network + SSL + vhost plumbing is proven.

**What we build:**
- `docker-env/nginx/Dockerfile` (base nginx image, copy configs).
- `docker-env/nginx/nginx.conf` (global).
- `docker-env/nginx/sites/default.conf`, `site1.conf`, `site2.conf`.
- `docker-env/logs/nginx/` for logs.
- A minimal `docker-compose.yml` with **only** the nginx service.

**Temporary placeholder files:** Create a simple `index.html` in each site folder so we have something to serve. (Replaced later.)

**Test / verify:**
- `docker compose up -d nginx` starts without error.
- `curl http://site1.local` returns the placeholder HTML.
- `curl http://site2.local` returns the placeholder HTML.
- `curl http://dev.local` returns the default site's HTML.
- `curl http://localhost` returns the default site's HTML.
- `curl -k https://site1.local` works (TLS).
- Browser shows a padlock (trusted) for `https://site1.local`.
- Logs appear in `logs/nginx/`.

**Deliverable:** Working Nginx with 3 vhosts, HTTP + HTTPS, trusted certs.

**Rollback:** `docker compose down` — Phase 1–2 remain intact.

---

## Phase 4 — Apache Only (No PHP), on Secondary Ports

**Goal:** Stand up Apache on 8080/8443, serving the same static placeholders, so both web servers run simultaneously.

**Why now:** Apache is the second web server; it reuses the same SSL and hostnames, so it's a natural follow-on to Phase 3. Still no PHP — keeps the variable count low.

**What we build:**
- `docker-env/apache2/Dockerfile`.
- `docker-env/apache2/vhost.conf` + `sites/*.conf`.
- `docker-env/logs/apache2/`.
- Extend `docker-compose.yml` with the apache2 service.

**Test / verify:**
- `docker compose up -d apache2` starts without error.
- **Nginx still works** (`curl http://site1.local`) — no regression.
- `curl http://site1.local:8080` returns the placeholder HTML via Apache.
- `curl http://site2.local:8080` returns the placeholder HTML via Apache.
- `curl -k https://site1.local:8443` works.
- Logs appear in `logs/apache2/`.

**Deliverable:** Both web servers running concurrently, on different ports, both serving the same sites.

**Rollback:** `docker compose down apache2` — nginx unaffected.

---

## Phase 5 — PHP 8.4 Base Image

**Goal:** Build the shared `php-base84` image and verify it contains everything expected: PHP 8.4, all extensions, LibreOffice, ImageMagick, Xdebug.

**Why before php-fpm:** The base image is the foundation. If it's wrong, everything built on it is wrong.

**Why no container yet:** This phase builds an image but doesn't run a service. It's a pure image-quality check.

**What we build:**
- `docker-env/php-base84/Dockerfile`.
- `docker-env/php-base84/php.ini`.
- `docker-env/php-base84/xdebug.ini`.

**Test / verify:** (run a throwaway container from the image, no compose yet)
- `docker build -t php-base:8.4 ./php-base84` succeeds.
- `docker run --rm php-base:8.4 php -v` shows PHP 8.4.
- `docker run --rm php-base:8.4 php -m` lists all expected extensions (bcmath, imagick, redis, mongodb, apcu, amqp, memcached, zmq, etc.).
- `docker run --rm php-base:8.4 soffice --version` prints a LibreOffice version.
- `docker run --rm php-base:8.4 convert --version` prints ImageMagick version.
- `docker run --rm php-base:8.4 php -i | grep xdebug` shows Xdebug is loaded.

**Deliverable:** A verified `php-base:8.4` image.

**Rollback:** `docker rmi php-base:8.4`.

---

## Phase 6 — PHP 7.4 Base Image

**Goal:** Same as Phase 5, but for PHP 7.4 on Bullseye.

**Why separate phase:** It's a distinct build with distinct challenges (7.4 is EOL; OpenSSL differences). Isolating it means a 7.4 problem doesn't block the 8.4 path.

**What we build:**
- `docker-env/php-base74/Dockerfile`.
- `docker-env/php-base74/php.ini`.
- `docker-env/php-base74/xdebug.ini`.

**Test / verify:** (same shape as Phase 5)
- Build succeeds.
- `php -v` shows 7.4.
- `php -m` shows the expected extensions (minus any genuinely unavailable on 7.4).
- LibreOffice + ImageMagick present.
- Xdebug loaded.

**Deliverable:** A verified `php-base:7.4` image.

**Rollback:** `docker rmi php-base:7.4`.

---

## Phase 7 — php-fpm 8.4 + Nginx Integration

**Goal:** Wire `php-fpm84` to Nginx so `site1.local` serves PHP through FPM.

**Why only 8.4 here:** One PHP version, one web server. Keeps the first PHP integration simple. Once it works, extending to 7.4 and Apache is mechanical.

**What we build:**
- `docker-env/php-fpm84/Dockerfile` (FROM `php-base:8.4`).
- Extend `docker-compose.yml` with `php-fpm84`.
- Update Nginx `sites/*.conf` to pass `.php` requests to `php-fpm84` via FastCGI.
- Replace placeholder HTML with a `phpinfo()` or simple echo script in each site root.

**Test / verify:**
- `docker compose up -d` (nginx + php-fpm84) starts cleanly.
- `curl http://site1.local/info.php` returns PHP output.
- `curl http://site2.local/info.php` returns PHP output.
- `curl http://dev.local/` shows the `v3client` index (PHP).
- `phpinfo()` shows the correct PHP version (8.4) and all extensions.
- Apache (from Phase 4) still works for static, but PHP requests through Apache are **not yet** expected to work.

**Deliverable:** Nginx ↔ php-fpm84 working end-to-end.

**Rollback:** `docker compose down php-fpm84`, revert nginx config.

---

## Phase 8 — php-fpm 7.4 + a Site Assigned to 7.4

**Goal:** Add php-fpm74 and assign one site (say `site2.local`) to use it, proving two PHP versions run simultaneously.

**What we build:**
- `docker-env/php-fpm74/Dockerfile` (FROM `php-base:7.4`).
- Extend `docker-compose.yml` with `php-fpm74`.
- Update **one** nginx vhost (e.g., `site2.conf`) to point FastCGI at `php-fpm74`.

**Test / verify:**
- `curl http://site1.local/info.php` → PHP 8.4.
- `curl http://site2.local/info.php` → PHP 7.4.
- Both run at the same time, no conflicts.
- Nginx reload succeeds after config change.

**Deliverable:** Two PHP versions serving different sites simultaneously.

**Rollback:** Revert `site2.conf`, remove `php-fpm74`.

---

## Phase 9 — php-fpm through Apache (Secondary Web Server)

**Goal:** Make Apache serve PHP too, so both web servers can host PHP sites on different ports.

**Why after both PHP versions:** Apache just needs `mod_proxy_fcgi` pointed at one of the existing FPM containers. No new PHP work.

**What we build:**
- Update Apache `sites/*.conf` to proxy `.php` requests to `php-fpm84` (or `php-fpm74`, per site).
- Update `apache2/Dockerfile` if needed (`mod_proxy_fcgi`, etc.).

**Test / verify:**
- `curl http://site1.local:8080/info.php` → PHP 8.4 via Apache.
- `curl http://site2.local:8080/info.php` → PHP 7.4 via Apache.
- Nginx paths still work unchanged (no regression).
- Both web servers serve both sites, each on its own port.

**Deliverable:** Full dual web server + dual PHP matrix.

**Rollback:** Revert Apache configs.

---

## Phase 10 — MySQL 8.0 (First Database)

**Goal:** Stand up MySQL with persistent data in `data_files/mysql80/`, reachable from PHP.

**Why MySQL first:** It's the most familiar, and it establishes the DB-service pattern (data dir, credentials, env vars) that the other DBs will follow.

**What we build:**
- `docker-env/mysql/Dockerfile` + `my.cnf`.
- Extend `docker-compose.yml` with `mysql`.
- Add `data_files/mysql80/` mount.
- Add MySQL env vars to `.env`.
- Update PHP containers with MySQL client extension (already present in base) and the DB host env var.

**Test / verify:**
- `docker compose up -d mysql` starts cleanly.
- `docker compose exec mysql mysql -uroot -p -e "SELECT VERSION();"` returns 8.0.x.
- Connect from a PHP script: `new PDO('mysql:host=mysql;dbname=...', ...)` succeeds.
- Restart the container: data persists (create a row, restart, row still there).
- `data_files/mysql80/` has real files on the host.

**Deliverable:** Working MySQL reachable from PHP.

**Rollback:** `docker compose down mysql`; delete `data_files/mysql80/`.

---

## Phase 11 — MariaDB 11

**Goal:** Add MariaDB as a second engine, same pattern as MySQL, on a different port (3307).

**Test / verify:**
- `docker compose exec mariadb mariadb -uroot -p -e "SELECT VERSION();"` returns 11.x.
- PHP connects to MariaDB by hostname `mariadb`.
- Data persists.
- MariaDB and MySQL run side by side, no port conflict.

**Deliverable:** MariaDB live alongside MySQL.

**Rollback:** `docker compose down mariadb`; delete its data dir.

---

## Phase 12 — PostgreSQL 15

**Goal:** Add PostgreSQL, same pattern, port 5432.

**Test / verify:**
- `docker compose exec postgres psql -U postgres -c "SELECT version();"` returns 15.x.
- PHP connects via PDO `pgsql`.
- Data persists.

**Deliverable:** Postgres live.

**Rollback:** `docker compose down postgres`; delete its data dir.

---

## Phase 13 — MongoDB 8

**Goal:** Add MongoDB, port 27017.

**Test / verify:**
- `docker compose exec mongodb mongosh --eval "db.version()"` returns 8.x.
- PHP connects via the `mongodb` extension.
- Data persists.

**Deliverable:** MongoDB live.

**Rollback:** `docker compose down mongodb`; delete its data dir.

---

## Phase 14 — Redis

**Goal:** Add Redis, unsuffixed data dir, port 6379.

**Test / verify:**
- `docker compose exec redis redis-cli ping` returns `PONG`.
- PHP connects via the `redis` extension.
- Data persists (set a key, restart, key still there if persistence is on).

**Deliverable:** Redis live.

**Rollback:** `docker compose down redis`; delete `data_files/redis/`.

---

## Phase 15 — Workspaces (CLI Containers)

**Goal:** Add `workspace84` and `workspace74` with Composer, Node (24/22/20 via nvm), Git, and the same PHP setup as FPM.

**Why after DBs:** So the workspace can connect to all DBs and tools are proven available.

**What we build:**
- `docker-env/workspace84/Dockerfile` + `workspace74/Dockerfile`.
- Extend compose.
- Verify `/home/client_data`, `/home/server_side`, `/var/www/html/*`, and `/backup` mounts.

**Test / verify:**
- `docker compose exec workspace84 bash`.
- Inside: `php -v` → 8.4; `php -m` → all extensions; `composer --version` → pinned; `node -v` → 24; `nvm ls` shows 24/22/20; `git --version` works.
- `ls /var/www/html/` shows the three sites.
- `ls /home/` shows `client_data` and `server_side`.
- `ls /backup/` works.
- `mysql -h mysql -u... ` and `psql`, `mongosh`, `redis-cli` connect.
- Repeat for `workspace74`.

**Deliverable:** Both workspaces fully functional.

**Rollback:** `docker compose down workspace84 workspace74`.

---

## Phase 16 — Admin Tools (Flag-Gated)

**Goal:** Add phpMyAdmin, pgAdmin, mongo-express, Adminer behind `.env` flags.

**Why last among services:** They're UIs. They depend on the DBs working.

**What we build:**
- `docker-env/phpmyadmin/`, `pgadmin/`, `mongo-express/`, `adminer/` with Dockerfiles + configs.
- Add `ENABLE_*` flags and ports to `.env`.

**Test / verify:**
- With `ENABLE_PHPMYADMIN=true`: `docker compose up -d phpmyadmin`; browse `http://localhost:8081`, log in, see MySQL + MariaDB.
- Same for pgAdmin (8082), mongo-express (8083), Adminer (8084).
- Set a flag to `false`, `docker compose up -d`, confirm the service is not started.
- All four can run at once without port conflicts.

**Deliverable:** All admin tools work and are flag-controllable.

**Rollback:** Set flags to `false`; `docker compose down`.

---

## Phase 17 — PHP Workers (Queue / Background Jobs)

**Goal:** Add `php-worker84` and `php-worker74` with Supervisor, running your queue workers.

**Why last among services:** Workers depend on PHP, DBs, Redis. All must be up first.

**What we build:**
- `docker-env/php-worker84/Dockerfile` + `supervisord.conf`.
- Same for 7.4.
- Extend compose.

**Test / verify:**
- `docker compose up -d php-worker84 php-worker74` starts.
- `docker compose exec php-worker84 supervisorctl status` shows configured programs running.
- Worker connects to Redis/DB.
- Kill it (`supervisorctl stop ...`) and confirm supervisor restarts it.
- Logs appear in `logs/`.

**Deliverable:** Workers running, supervised, restartable.

**Rollback:** `docker compose down php-worker84 php-worker74`.

---

## Phase 18 — Logs Folder Wiring (Full)

**Goal:** Ensure **every** service writes logs to its `logs/<service>/` folder.

**Why after all services exist:** There's nothing to wire until services are up.

**What we do:**
- Verify or add bind mounts for each service's log output.
- Confirm rotation is handled (or acceptable without rotation in dev).

**Test / verify:**
- Each folder in `logs/` receives files after exercising the corresponding service.
- Files are readable on the host without `docker exec`.

**Deliverable:** Full host-side log visibility.

**Rollback:** Remove mounts (logs go back to container-only).

---

## Phase 19 — `backup_data/` Wiring (Full)

**Goal:** Confirm `/backup` is mounted everywhere it should be, and dump/restore works end-to-end.

**What we do:**
- Verify `/backup` mount in mysql, mariadb, postgres, mongodb, workspace84, workspace74.
- Confirm permissions (777).
- Write a smoke-test procedure (no scripts yet): dump a DB, drop it, restore it.

**Test / verify:**
- From `mysql`: `mysqldump ... > /backup/test.sql` — file appears on host.
- From `workspace84`: restore the file to a second DB.
- Same for postgres (pg_dump), mongodb (mongodump/mongorestore).
- MariaDB same as MySQL.

**Deliverable:** Backups flow to the host; restore works.

**Rollback:** N/A — mount removal only.

---

## Phase 20 — `.env` Consolidation & `.env.example`

**Goal:** Tidy the `.env` so every knob has a home, and produce a clean `.env.example`.

**What we do:**
- Audit every service to ensure its variables live in `.env` (not hardcoded in compose or Dockerfiles).
- Write `.env.example` with safe defaults and comments.
- Add `.env` to `.gitignore`.

**Test / verify:**
- Wipe `.env`, copy `.env.example` to `.env`, `docker compose up -d`, everything still works.
- Change a value (e.g., PHP port, DB password) and confirm the change takes effect.

**Deliverable:** Reproducible environment from a clean clone.

**Rollback:** Keep a copy of `.env`.

---

## Phase 21 — `docker-compose.override.yml` for Local Tweaks

**Goal:** Set up an override file so you can add machine-specific tweaks without editing the main compose file.

**What we do:**
- Create `docker-compose.override.yml` (initially minimal).
- Add it to `.gitignore` so it never gets committed.

**Test / verify:**
- Add a harmless override (e.g., an extra env var), `docker compose config` shows the merged result.
- Removing the override file doesn't break `docker compose up`.

**Deliverable:** A safe local override channel.

**Rollback:** Delete the file.

---

## Phase 22 — Xdebug Full Verification (PhpStorm + VS Code)

**Goal:** Confirm Xdebug actually works with both IDEs for both PHP versions.

**Why late:** It needs PHP-FPM, site code mounted, and a stable path mapping. All in place by now.

**What we do:**
- Configure PhpStorm: DBGp port 9003, path mapping host `Projects/sites/site1` → `/var/www/html/site1`.
- Configure VS Code: same values via `launch.json`.
- Test with a breakpoint in a PHP script.

**Test / verify:**
- PhpStorm: a breakpoint triggers when accessing `http://site1.local/debug.php?XDEBUG_SESSION=start`.
- VS Code: same.
- Both PHP 8.4 and 7.4.
- Works from workspace CLI too (`php debug.php` with `XDEBUG_SESSION` env var).

**Deliverable:** Xdebug working end-to-end with both IDEs.

**Rollback:** Disable Xdebug via `.env` flag if needed.

---

## Phase 23 — Full Stack Smoke Test

**Goal:** With everything built, run a single test that exercises every layer at once.

**What we do:**
- Bring up the entire stack from scratch (`docker compose down -v`, then `up -d`).
- Run a scripted check (a bash one-liner or manual checklist) hitting:
  - `site1.local` (PHP 8.4 via Nginx)
  - `site2.local` (PHP 7.4 via Nginx)
  - `site1.local:8080` (PHP 8.4 via Apache)
  - All four admin UIs
  - Each DB from workspace
  - Redis from workspace
  - A worker restart
  - Xdebug breakpoint

**Test / verify:**
- Every item on the checklist passes.
- No service is in a restart loop.
- `docker compose ps` shows all expected containers as `Up (healthy)` (if healthchecks added).

**Deliverable:** A complete, verified environment.

**Rollback:** Full teardown.

---

## Phase 24 — Documentation & Cleanup

**Goal:** Write the `README.md` in `docker-env/`, document common commands, and remove dead files.

**What we do:**
- Document: how to start/stop, how to add a new site, how to add a new PHP version, how to back up/restore, how to add hosts entries.
- Provide a "cheatsheet" of common commands.
- Clean up any leftover placeholders.
- Decide whether to add healthchecks (nice-to-have).

**Test / verify:**
- A person unfamiliar with the env can read the README and start the stack.
- No placeholder files remain.

**Deliverable:** A documented, self-explanatory environment.

**Rollback:** N/A.

---

# Phase Summary Table

| Phase | Focus | Depends on | Test type |
|---|---|---|---|
| 0 | Host prep | — | Manual |
| 1 | DNS / hostnames | 0 | `ping` |
| 2 | SSL (mkcert) | 0 | `openssl` |
| 3 | Nginx only | 1, 2 | `curl` HTTP/HTTPS |
| 4 | Apache only | 3 | `curl` on 8080/8443 |
| 5 | PHP 8.4 base image | 0 | `docker run` inspection |
| 6 | PHP 7.4 base image | 0 | `docker run` inspection |
| 7 | php-fpm84 + Nginx | 3, 5 | `curl` PHP through Nginx |
| 8 | php-fpm74 + one 7.4 site | 7 | Two PHP versions live |
| 9 | PHP through Apache | 4, 7 | `curl` PHP on 8080 |
| 10 | MySQL | 7 | PDO connect, persistence |
| 11 | MariaDB | 10 | PDO connect, persistence |
| 12 | PostgreSQL | 10 | PDO connect, persistence |
| 13 | MongoDB | 10 | PHP driver connect |
| 14 | Redis | 10 | `redis-cli`, PHP connect |
| 15 | Workspaces (84, 74) | 7, 8, 10–14 | CLI checks inside |
| 16 | Admin tools (flag-gated) | 10–14 | Browser UI |
| 17 | PHP workers | 7, 8, 10, 14 | `supervisorctl status` |
| 18 | Logs folder wiring | all services | Files on host |
| 19 | Backup wiring | 10–14, 15 | Dump + restore |
| 20 | `.env` consolidation | all | Clean-clone up |
| 21 | Compose override | 20 | Merge check |
| 22 | Xdebug verification | 7, 8, 15 | Breakpoints in 2 IDEs |
| 23 | Full smoke test | all | End-to-end |
| 24 | Docs & cleanup | all | Read-through |

---

# Rules for Each Phase

1. **We do not start Phase N+1 until Phase N's test passes.**
2. **If a phase fails, we fix it in isolation** — not by jumping ahead.
3. **Each phase gets its own set of files** and, where applicable, its own compose service addition.
4. **Rollback for every phase is documented** (usually `docker compose down <service>` plus optional data-dir removal).
5. **No phase depends on a future phase's work.**

---

# Suggested Starting Point

**Phase 0** and **Phase 1** are the most sensible next steps — they're host-only, zero Docker risk, and unblock everything else.

When you're ready, tell me:
- Which phase to start with (default: Phase 0)
- Whether to write the plan's phase into actual files and commands at that point

---

**End of implementation plan.**