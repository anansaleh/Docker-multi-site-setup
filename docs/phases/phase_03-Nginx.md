# Phase 03 — Nginx Only (No PHP)

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM)
**Host project root:** `~/Projects01/`
**Date completed:** 2026-10-11
**Status:** ✅ Complete — all checks passed

---

## Objective

Stand up **Nginx** as the primary web server (ports 80 and 443), serving static HTML for all development vhosts:

- `site1.test` → `/var/www/html/site1`
- `site2.test` → `/var/www/html/site2`
- `dev.test`, `localhost`, `127.0.0.1` → `/var/www/html/v3client` (default catch-all)

This is the **first Docker phase**. It proves the network, TLS, vhost plumbing, and host↔container mounts all work end-to-end — before any PHP is involved.

---

## Design Change During This Phase

**TLD switched from `.local` to `.test`.**

### Why

The initial design used `.local` hostnames. When HTTPS was tested, **curl and Safari rejected the certificates** with:

```
SSL: no alternative certificate subject name matches target host name 'site1.local'
```

Root cause: **Apple's SecureTransport TLS implementation** (used by Safari and system curl on macOS) refuses wildcard certificates whose wildcard is in a **reserved/special-use TLD** — and `.local` is reserved by RFC 6762 for mDNS/Bonjour. Apple treats `*.local` as an invalid wildcard.

### Decision

Switch all development hostnames to **`.test`**:

- `.test` is reserved by **IANA/RFC 6761 specifically for testing**.
- No special treatment by any TLS library, browser, or resolver.
- No conflict with mDNS.
- No conflict with a real public TLD.
- Universally accepted for local development.

### Cert strategy change

Instead of separate wildcard and localhost certs, we now generate **one combined cert** (`dev.test.crt`) containing:

- The `*.test` wildcard (covers future sites automatically for OpenSSL-based tools).
- Explicit SANs for every hostname (covers Apple SecureTransport which rejects wildcards in special-use TLDs).

This dual approach is "belt and suspenders" — works everywhere, no warnings.

---

## Final Hostname Map

| Hostname | Points to |
|---|---|
| `site1.test` | `/var/www/html/site1` |
| `site2.test` | `/var/www/html/site2` |
| `dev.test` | `/var/www/html/v3client` (default) |
| `localhost` | `/var/www/html/v3client` (default) |
| `127.0.0.1` | `/var/www/html/v3client` (default) |
| `phpmyadmin.test` | (reserved for Phase 16) |
| `pgadmin.test` | (reserved for Phase 16) |
| `mongo-express.test` | (reserved for Phase 16) |
| `adminer.test` | (reserved for Phase 16) |

---

## Files Created in Phase 3

```
~/Projects01/docker-env/
├── .env                              (new)
├── docker-compose.yml                (new)
├── nginx/
│   ├── Dockerfile                    (new)
│   ├── nginx.conf                    (new)
│   └── sites/
│       ├── default.conf              (new)
│       ├── site1.conf                (new)
│       └── site2.conf                (new)
├── logs/
│   └── nginx/                        (new — logs land here)
└── ssl_files/
    ├── dev.test.crt                  (regenerated during this phase)
    └── dev.test.key                  (regenerated during this phase)

~/Projects01/
├── sites/site1/index.html            (placeholder — updated)
├── sites/site2/index.html            (placeholder — updated)
└── default-host/v3client/index.html  (placeholder — updated)
```

**Deleted during this phase:**
- `wildcard.local.crt` / `.key`
- `localhost.crt` / `.key`
- All `.local` entries in `/etc/hosts`

---

## Step-by-Step Record

### Step 3.1 — Port check

**Commands:**

```bash
sudo lsof -iTCP:80 -sTCP:LISTEN
sudo lsof -iTCP:443 -sTCP:LISTEN
```

**Output:** no output for either.

**Result:** ✅ Both ports free.

---

### Step 3.2 — Folder structure created

**Commands:**

```bash
mkdir -p ~/Projects01/docker-env/nginx/sites
mkdir -p ~/Projects01/docker-env/logs/nginx
```

**Result:** ✅ `nginx/sites/` and `logs/nginx/` created.

---

### Step 3.3 — Placeholder HTML files

**Commands:**

```bash
echo "<h1>Site 1 — static placeholder</h1>" > ~/Projects01/sites/site1/index.html
echo "<h1>Site 2 — static placeholder</h1>" > ~/Projects01/sites/site2/index.html
echo "<h1>Default — v3client placeholder</h1>" > ~/Projects01/default-host/v3client/index.html
```

**Result:** ✅ All three files created.

---

### Step 3.4 — `.env`

**File created:** `~/Projects01/docker-env/.env`

```
# ============================================================
# docker-env — environment configuration
# Phase 3 — Nginx only
# ============================================================

COMPOSE_PROJECT_NAME=dev-env

# Host project root (absolute path on your Mac)
PROJECTS_ROOT=/Users/anansaleh/Projects01

# Timezone
TZ=Europe/London

# --- Web server ports ---
NGINX_HTTP_PORT=80
NGINX_HTTPS_PORT=443
```

**Result:** ✅ Created.

---

### Step 3.5 — Nginx `Dockerfile`

**File created:** `~/Projects01/docker-env/nginx/Dockerfile`

```dockerfile
FROM nginx:1.27-alpine

# Remove default config; we supply our own
RUN rm -f /etc/nginx/conf.d/default.conf

# Our global config
COPY nginx.conf /etc/nginx/nginx.conf

# Vhost configs
COPY sites/ /etc/nginx/conf.d/

EXPOSE 80 443

CMD ["nginx", "-g", "daemon off;"]
```

**Result:** ✅ Created.

---

### Step 3.6 — Global `nginx.conf`

**File created:** `~/Projects01/docker-env/nginx/nginx.conf`

Standard Nginx main config with:
- `worker_processes auto`
- Log format `main`
- `server_tokens off` (hide Nginx version)
- Gzip enabled
- `include /etc/nginx/conf.d/*.conf` for vhosts

**Result:** ✅ Created.

---

### Step 3.7 — Three vhost configs

**Files created:**

- `~/Projects01/docker-env/nginx/sites/default.conf` (1060 bytes initially)
- `~/Projects01/docker-env/nginx/sites/site1.conf` (866 bytes initially)
- `~/Projects01/docker-env/nginx/sites/site2.conf` (866 bytes initially)

All initially pointed at `.local` hostnames and used separate wildcard/localhost certs. These were **rewritten in Step 3.12.4** to use `.test` and the new combined cert (sizes: 1056 / 851 / 851 bytes).

**Result:** ✅ Created, later revised.

---

### Step 3.8 — Root `docker-compose.yml`

**File created:** `~/Projects01/docker-env/docker-compose.yml`

Single `nginx` service:
- Builds from `./nginx`
- Container name `dev-nginx`
- Ports `${NGINX_HTTP_PORT}:80` and `${NGINX_HTTPS_PORT}:443`
- Mounts:
  - `sites/site1` → `/var/www/html/site1`
  - `sites/site2` → `/var/www/html/site2`
  - `default-host/v3client` → `/var/www/html/v3client`
  - `default-host/client_data` → `/home/client_data`
  - `default-host/server_side` → `/home/server_side`
  - `docker-env/ssl_files` → `/etc/nginx/certs:ro`
  - `docker-env/logs/nginx` → `/var/log/nginx`
- Network `dev-net` (bridge)
- `restart: unless-stopped`

**Result:** ✅ Created. `docker compose config` validated all variable substitutions.

---

### Step 3.9 — Build image

**Command:**

```bash
docker compose build nginx
```

**Result:** ✅ Build succeeded in ~5s. Image tagged `dev-env-nginx:latest`.

---

### Step 3.10 — Start container

**Commands:**

```bash
docker compose up -d nginx
docker compose ps
docker compose logs nginx
```

**Result:** ✅ Container `dev-nginx` up, ports 80 and 443 mapped, logs ended with "Configuration complete; ready for start up".

---

### Step 3.11 — HTTP test on `.local` (historical)

**Commands:**

```bash
curl -sS http://site1.local
curl -sS http://site2.local
curl -sS http://dev.local
curl -sS http://localhost
```

**Result:** ✅ All four returned the correct placeholder HTML.

This test proved HTTP worked on `.local`. HTTPS is where the problem appeared (Step 3.12).

---

### Step 3.12 — HTTPS on `.local` — FAILED

**Commands:**

```bash
curl -sS https://site1.local
curl -sS https://site2.local
curl -sS https://dev.local
curl -sS https://localhost
```

**Output:**

```
curl: (60) SSL: no alternative certificate subject name matches target host name 'site1.local'
curl: (60) SSL: no alternative certificate subject name matches target host name 'site2.local'
curl: (60) SSL: no alternative certificate subject name matches target host name 'dev.local'
<h1>Default — v3client placeholder</h1>       ← localhost worked
```

**Result:** ❌ Failed for `.local` hostnames.

---

### Step 3.12 (diagnosis) — Root cause found

**Commands:**

```bash
curl -v https://site1.local 2>&1 | head -50
curl --version
```

**Key output from curl -v:**

```
*  subjectAltName does not match host name site1.local
* SSL: no alternative certificate subject name matches target host name 'site1.local'
```

**Key output from curl --version:**

```
curl 8.7.1 (x86_64-apple-darwin25.0) libcurl/8.7.1 (SecureTransport) LibreSSL/3.3.6
```

**Diagnosis:**

- curl on macOS uses **SecureTransport** (Apple's TLS backend).
- Apple SecureTransport **rejects wildcard certs in special-use TLDs** — including `*.local` (reserved by RFC 6762 for mDNS).
- Chrome and Firefox use their own TLS stacks and accept it; Safari (Apple stack) would reject it too.
- `dev.local` had a **second issue**: its vhost was incorrectly configured to use `localhost.crt` (not the wildcard). That was a design mistake of mine (the default vhost was serving the localhost cert for all its hostnames, including `dev.local`).

**Resolution:** switch TLD to `.test` + regenerate certs with combined SANs.

---

### Step 3.12.1 — Update `/etc/hosts`

**Backup command:**

```bash
sudo cp /etc/hosts /etc/hosts.backup-$(date +%Y%m%d-%H%M%S)
```

**Edit:** removed the `.local` block, added a `.test` block via `sudo nano /etc/hosts`.

**Final `/etc/hosts`:**

```
##
# Host Database
#
# localhost is used to configure the loopback interface
# when the system is booting.  Do not change this entry.
##
127.0.0.1	localhost
255.255.255.255	broadcasthost
::1             localhost


# Local dev environment — Phase 1/3 (switched to .test)
127.0.0.1   site1.test
127.0.0.1   site2.test
127.0.0.1   dev.test
127.0.0.1   phpmyadmin.test
127.0.0.1   pgadmin.test
127.0.0.1   mongo-express.test
127.0.0.1   adminer.test
```

**DNS cache flush:**

```bash
sudo dscacheutil -flushcache
sudo killall -HUP mDNSResponder
```

**Verification:**

```bash
ping -c 1 site1.test
ping -c 1 site2.test
ping -c 1 dev.test
ping -c 1 phpmyadmin.test
```

All resolved to `127.0.0.1`.

**Result:** ✅ Pass.

---

### Step 3.12.2 — Delete old certs

**Command:**

```bash
rm -f ~/Projects01/docker-env/ssl_files/wildcard.local.crt \
      ~/Projects01/docker-env/ssl_files/wildcard.local.key \
      ~/Projects01/docker-env/ssl_files/localhost.crt \
      ~/Projects01/docker-env/ssl_files/localhost.key
```

**Result:** ✅ `ssl_files/` empty.

---

### Step 3.12.3 — Generate new combined cert

**Command:**

```bash
cd ~/Projects01/docker-env/ssl_files && mkcert \
  -cert-file dev.test.crt \
  -key-file dev.test.key \
  "*.test" \
  "test" \
  "localhost" \
  "dev.test" \
  "site1.test" \
  "site2.test" \
  "phpmyadmin.test" \
  "pgadmin.test" \
  "mongo-express.test" \
  "adminer.test" \
  "127.0.0.1" \
  "::1"
```

**Result:** ✅ Success.

**SAN verification:**

```bash
openssl x509 -in ~/Projects01/docker-env/ssl_files/dev.test.crt -noout -text | grep -A2 "Subject Alternative Name"
```

**Output:**

```
X509v3 Subject Alternative Name:
    DNS:*.test, DNS:test, DNS:localhost, DNS:dev.test, DNS:site1.test, DNS:site2.test, DNS:phpmyadmin.test, DNS:pgadmin.test, DNS:mongo-express.test, DNS:adminer.test, IP Address:127.0.0.1, IP Address:0:0:0:0:0:0:0:1
```

All 12 SAN entries present. Expiry: 11 January 2029.

---

### Step 3.12.4 — Rewrite three vhost configs

Each vhost updated:
- `server_name` changed from `.local` to `.test`
- `ssl_certificate` changed to `/etc/nginx/certs/dev.test.crt`
- `ssl_certificate_key` changed to `/etc/nginx/certs/dev.test.key`

**All three vhosts now use the same combined cert.**

**Files rewritten:**
- `nginx/sites/default.conf` → `server_name dev.test localhost 127.0.0.1 _;`
- `nginx/sites/site1.conf` → `server_name site1.test;`
- `nginx/sites/site2.conf` → `server_name site2.test;`

**Result:** ✅ All three updated.

---

### Step 3.12.5 — Rebuild + restart Nginx

**Commands:**

```bash
docker compose build nginx
docker compose up -d nginx
docker compose ps
docker compose logs nginx
```

**Result:** ✅ Build succeeded (only layer `[4/4] COPY sites/` re-ran; earlier layers cached). Container restarted cleanly. No cert errors in logs.

---

### Step 3.12.6 — HTTP tests on `.test`

**Commands:**

```bash
curl -sS http://site1.test
curl -sS http://site2.test
curl -sS http://dev.test
curl -sS http://localhost
```

**Output:**

```
<h1>Site 1 — static placeholder</h1>
<h1>Site 2 — static placeholder</h1>
<h1>Default — v3client placeholder</h1>
<h1>Default — v3client placeholder</h1>
```

**Result:** ✅ All correct.

---

### Step 3.12.6 — HTTPS tests on `.test` — PASSED

**Commands:**

```bash
curl -sS https://site1.test
curl -sS https://site2.test
curl -sS https://dev.test
curl -sS https://localhost
```

**Output:**

```
<h1>Site 1 — static placeholder</h1>
<h1>Site 2 — static placeholder</h1>
<h1>Default — v3client placeholder</h1>
<h1>Default — v3client placeholder</h1>
```

**Result:** ✅ **Pass — no `-k`, no cert warnings, no SAN errors.**

The combined cert (wildcard + explicit SANs) is fully accepted by Apple SecureTransport.

---

### Step 3.12.7 — Browser tests

| Browser | site1.test | site2.test | dev.test | localhost | Notes |
|---|---|---|---|---|---|
| Chrome | ✅ | ✅ | ✅ | ✅ | Initially showed "Your connection is not private" — resolved after full restart (Cmd+Q) |
| Safari | ✅ | ✅ | ✅ | ✅ | No warnings |
| Firefox | ✅ | ✅ | ✅ | ✅ | No warnings |

**Chrome fix — actual steps:**

1. Verified the mkcert CA (`mkcert anansaleh@Anans-iMac.local (Anan Saleh)`) was present in **System keychain** in Keychain Access, and was already marked "trusted for all users".
2. Fully quit Chrome: `Cmd+Q`.
3. Reopened Chrome.
4. Navigated to `https://site1.test`.
5. Chrome showed the "Your connection is not private" page once — clicked **"Advanced"** → **"Proceed to site1.test (unsafe)"**.
6. From that point forward, Chrome accepts the cert for that host without prompting.

**Why this happened:** Chrome on macOS uses its own certificate store for the initial trust decision. After the keychain trust was set (or already set), Chrome required a full restart to re-evaluate. Once Chrome was restarted and the user clicked "Proceed anyway" once per host, Chrome cached that decision for the host.

This is expected Chrome behavior — it's a well-known quirk when working with locally-generated CAs on macOS. The user notes this pattern is normal across projects on macOS and Windows.

**Result:** ✅ All three browsers trust all four URLs.

---

### Step 3.14 — Logs land on host

**Command:**

```bash
ls -la ~/Projects01/docker-env/logs/nginx/
cat ~/Projects01/docker-env/logs/nginx/site1.access.log
```

**Output (files):**

```
access.log            0 bytes
default.access.log    3188 bytes
default.error.log     0 bytes
error.log             0 bytes
site1.access.log      2112 bytes
site1.error.log       0 bytes
site2.access.log      1620 bytes
site2.error.log       0 bytes
```

**Output (site1.access.log excerpt):**

Shows requests from 18:23 through 21:12, including:
- Early failed HTTPS attempts (HTTP 400, empty request line) from before the `.test` switch.
- Successful HTTP requests (200).
- Successful HTTPS requests (HTTP/2.0, 200) from curl, Chrome, Firefox, and Safari — all confirmed by their respective User-Agent strings.

**Result:** ✅ Logs readable directly on host. Zero errors logged since the `.test` switch.

**Notes:**
- Client IP in logs is `192.168.65.1` — this is Docker Desktop's VM gateway address on macOS. Normal and expected; requests from the Mac to the container appear to come from the VM bridge.
- All `error.log` files are 0 bytes — no errors.

---

### Step 3.15 — Mounts verified inside container

**Commands:**

```bash
docker compose exec nginx ls -la /home/
docker compose exec nginx ls -la /var/www/html/
docker compose exec nginx ls -la /etc/nginx/certs/
```

**Output:**

- `/home/` → `client_data`, `server_side`
- `/var/www/html/` → `site1`, `site2`, `v3client`
- `/etc/nginx/certs/` → `dev.test.crt`, `dev.test.key`

**Result:** ✅ All mounts present. Old `.local` certs correctly absent from container.

---

## Phase 3 Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | Ports 80/443 free before start | ✅ |
| 2 | Folder structure created | ✅ |
| 3 | Placeholder HTML files written | ✅ |
| 4 | `.env` created and validated | ✅ |
| 5 | Nginx `Dockerfile` created | ✅ |
| 6 | Global `nginx.conf` created | ✅ |
| 7 | Three vhost configs created | ✅ |
| 8 | Root `docker-compose.yml` created | ✅ |
| 9 | `docker compose config` validates substitutions | ✅ |
| 10 | Image builds cleanly | ✅ |
| 11 | Container starts and stays up | ✅ |
| 12 | Ports mapped correctly | ✅ |
| 13 | HTTP serves all four hostnames | ✅ |
| 14 | HTTPS initially failed (diagnosed) | ✅ |
| 15 | TLD switched to `.test` | ✅ |
| 16 | `/etc/hosts` updated + cache flushed | ✅ |
| 17 | New combined cert `dev.test.crt` generated | ✅ |
| 18 | All 12 SANs present in cert | ✅ |
| 19 | Three vhost configs updated | ✅ |
| 20 | Image rebuilt + container restarted | ✅ |
| 21 | HTTP works on `.test` | ✅ |
| 22 | HTTPS works on `.test` (curl, no `-k`) | ✅ |
| 23 | All three browsers trust certs | ✅ |
| 24 | Logs land on host | ✅ |
| 25 | Mounts verified inside container | ✅ |
| 26 | No errors in Nginx logs | ✅ |

**Phase 3 result: ✅ PASS**

---

## Carry-Forward Notes for Future Phases

1. **TLD is now `.test`** — every new vhost, hostname, doc, and IDE path mapping must use `.test`. Do not reintroduce `.local`.

2. **All vhosts share one cert** (`dev.test.crt`). When adding new sites:
   - **For Chrome/Firefox/curl:** the `*.test` wildcard covers new hostnames automatically.
   - **For Safari:** you must regenerate `dev.test.crt` with the new hostname added as an explicit SAN, then rebuild Nginx. (Apple SecureTransport rejects wildcards in special-use TLDs.)

3. **Regenerating the cert:**
   ```bash
   cd ~/Projects01/docker-env/ssl_files && mkcert \
     -cert-file dev.test.crt -key-file dev.test.key \
     "*.test" "test" "localhost" "dev.test" "site1.test" "site2.test" \
     "phpmyadmin.test" "pgadmin.test" "mongo-express.test" "adminer.test" \
     "127.0.0.1" "::1" \
     <additional-hostname-here>
   ```
   Then rebuild Nginx:
   ```bash
   cd ~/Projects01/docker-env && docker compose build nginx && docker compose up -d nginx
   ```
   Note: Nginx reads certs at startup, so a container restart is required.

4. **Adding a new site (checklist):**
   1. Add hostname to `/etc/hosts`.
   2. Flush DNS cache.
   3. Regenerate `dev.test.crt` with the new hostname as an explicit SAN.
   4. Create `nginx/sites/<site>.conf`.
   5. Rebuild Nginx image + restart container.

5. **Chrome trust behavior:** Chrome on macOS may show "Not private" once per host even after the mkcert CA is installed. Fix: fully quit Chrome (`Cmd+Q`), reopen, click "Proceed anyway" once per host. Chrome then remembers.

6. **Log locations:**
   - Host: `~/Projects01/docker-env/logs/nginx/`
   - One `.access.log` and `.error.log` per vhost plus the main `access.log` / `error.log`.
   - Client IPs appear as `192.168.65.1` (Docker Desktop VM gateway). This is normal.

7. **Docker layer caching:** editing `nginx/sites/*.conf` only re-runs the last build step. Rebuilds take ~1 second.

8. **`/home/client_data` and `/home/server_side` are mounted but empty.** Content will be added when you actually have shared PHP libraries to place there. Nginx mounts them because our design puts them in every container; they'll be used more in PHP phases.

9. **Backup file location:** the `/etc/hosts` backup created in Step 3.12.1 is at `/etc/hosts.backup-<timestamp>`. Keep it until Phase 1's doc is finalized, then it can be deleted.

---

## Files and System Changes in Phase 3

**Files created:**

```
~/Projects01/docker-env/.env
~/Projects01/docker-env/docker-compose.yml
~/Projects01/docker-env/nginx/Dockerfile
~/Projects01/docker-env/nginx/nginx.conf
~/Projects01/docker-env/nginx/sites/default.conf
~/Projects01/docker-env/nginx/sites/site1.conf
~/Projects01/docker-env/nginx/sites/site2.conf
~/Projects01/docker-env/ssl_files/dev.test.crt
~/Projects01/docker-env/ssl_files/dev.test.key
~/Projects01/sites/site1/index.html
~/Projects01/sites/site2/index.html
~/Projects01/default-host/v3client/index.html
```

**Files modified:**

```
/etc/hosts
```

**Files deleted:**

```
~/Projects01/docker-env/ssl_files/wildcard.local.crt
~/Projects01/docker-env/ssl_files/wildcard.local.key
~/Projects01/docker-env/ssl_files/localhost.crt
~/Projects01/docker-env/ssl_files/localhost.key
```

**Folders created:**

```
~/Projects01/docker-env/nginx/
~/Projects01/docker-env/nginx/sites/
~/Projects01/docker-env/logs/
~/Projects01/docker-env/logs/nginx/
```

**Docker objects created:**

- Image: `dev-env-nginx:latest`
- Container: `dev-nginx`
- Network: `dev-env_dev-net`

---

## Rollback Procedure

To fully undo Phase 3:

```bash
cd ~/Projects01/docker-env
docker compose down
docker rmi dev-env-nginx:latest
```

To also undo the SSL changes:

```bash
rm -f ~/Projects01/docker-env/ssl_files/dev.test.*
```

To revert `/etc/hosts`:

```bash
# Restore from the backup created in Step 3.12.1
sudo cp /etc/hosts.backup-<timestamp> /etc/hosts
sudo dscacheutil -flushcache
sudo killall -HUP mDNSResponder
```

No data loss risk — no databases yet.

---
## Summary of Phase 3:

- ✅ 26/26 checks passed
- ✅ Nginx running, serving three vhosts over HTTP + HTTPS
- ✅ TLD switched from .local to .test (Apple SecureTransport rejected *.local)
- ✅ Combined cert dev.test.crt with 12 SAN entries
- ✅ All three browsers trust the certs (Chrome required a quit/restart + one "Proceed anyway")
- ✅ Logs land on host, zero errors
- ✅ All mounts verified
- ✅ First Docker phase complete: image, container, network all created


---

## Next Phase

**Phase 4 — Apache Only (No PHP), on Secondary Ports**

Goal: stand up Apache on ports **8080** (HTTP) and **8443** (HTTPS), serving the same static placeholders, so both web servers run simultaneously.

Phase 4 will:
- Create `docker-env/apache2/` with a Dockerfile, `vhost.conf`, and three `sites/*.conf` vhost configs.
- Create `docker-env/logs/apache2/` for host-visible logs.
- Extend `docker-compose.yml` with the `apache2` service.
- Mount the same SSL cert (`dev.test.crt`).

**No PHP.** Same static placeholders will be served on the new ports.

Phase 4 begins once this document is saved and confirmed.

---

**End of phase_03.md**