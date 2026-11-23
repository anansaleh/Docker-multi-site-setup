# Phase 12 — PostgreSQL 15 on Windows 11 using PowerShell
## 12.0 The Problem
mounting a Windows directory to PostgreSQL's data directory causes a permission check failure.

### Most Likely Cause
PostgreSQL has strict requirements for the data directory: permissions must be 0700 or 0750. When you mount a Windows path (such as E:\...) to /var/lib/postgresql/data, the NTFS file system cannot provide the Linux permission bits that PostgreSQL expects. The postgres user inside the container (UID 999) attempts to execute chmod or chown, but is rejected by the file system, and the container crashes directly.

---
### Config mounting strategy.
Postgres config is fiddly because postgresql.conf needs the right data_directory, hba_file, ident_file, and logging_collector/log_directory values, and permissions matter (postgres user, 0700 on data dir on Linux containers, but Windows bind mounts don't carry Unix perms — this is the classic Docker Desktop on Windows trap). Options:

- Mount a whole postgresql.conf per version, tuned via env at entrypoint.
- Mount only conf.d/*.conf overrides (cleaner, keeps official defaults).
- Init scripts in /docker-entrypoint-initdb.d/ for user/db/extension bootstrap — these only run on first init of an empty data dir, which is important to remember.

### The Windows 11 / Docker Desktop reality
This is the biggest operational constraint and it shapes several things:

- ** Bind mounts** work, but path translation and file permissions are Linux-VM-mediated. Postgres data on a bind mount from `C:\...` can hit permission problems (`chmod 700` on the data dir). The reliable answers: 
    - (a) put docker-env/ inside WSL2 filesystem (e.g., \\wsl$\Ubuntu\home\...) and run compose from WSL, or 
    - (b) use named volumes for data instead of bind mounts — but that breaks your `data_files/postgres15/` tree. 

    So we should plan to run from WSL2 and document that. Alternatively, keep bind mounts and accept a slower/permission-quirky path, or use Docker Desktop's improved WSL2 backend and still bind-mount. This needs a decision.

- **Logs** written by the postgres user inside the container to logs/postgres15/ on a Windows bind mount → same permission caveat; `logging_collector=on` writes as the postgres uid, and the host folder must be writable by that uid from the container's view. Again easier under WSL2.

- **Port** collisions with host services (a local Windows Postgres on 5432) → we map host ports from .env (e.g., 5432, 5433, 5434) and keep container ports at 5432. Documented in .env.

- **Line endings** — .env, init .sql, config files must be LF, not CRLF, or Postgres and shell entrypoints misbehave. We'll note `.gitattributes` / editorconfig.

**Naming and log-folder granularity**. Your tree shows `logs/postgres/` (per engine), but with multiple versions the logs will mix. I recommend `logs/postgres15/`, `logs/postgres16/` — i.e., per service/version, matching data_files/postgres15/. Same for data_files. This is a small but important deviation from the literal tree; flagging it for your yes/no.

**Networking.** One user-defined bridge network, e.g. dbenv_net. Service names become DNS names. Containers resolve postgres15:5432, postgres16:5432. Cross-container migration (pg_dump from 15 → restore into 16) becomes trivial. We should also decide whether the network is internal (no host access to internet) — probably not, because you'll want to pull images and maybe install extensions.

Secrets. Passwords in .env → fine for local dev, but we should call out: `.env` goes in .gitignore; provide `.env.example` as the committed template. No secrets in the Dockerfile or compose.

What stays out of scope (for now). No app containers, no reverse proxy, no backup orchestration, no monitoring stack — this is DB hosting only. We can bolt those on later.


---
# 12.1 Analysis & Planning Progress
## 12.1.1 Initial Goal & Directory Structure


```text
docker-env/
│
├── data_files/                     # persistent data (version-isolated per DB)
│   └── postgres15/  
│
├── logs/                           # one subfolder per service
│   └── postgres/
│
├── postgres/
│   ├── Dockerfile
│   └── (config)
│
├── .env                            # single source of truth
└── docker-compose.yml              # orchestrates all services
```


**Architectural Mapping for Local PHP + Multi-DB Workflows:**
* **`data_files/postgres15/`**: Isolates persistent storage by database engine and major version so different versions or engines can run side-by-side without data collisions or wiping volumes when recreating containers.
* **`logs/postgres/`**: Centralizes service logs on the host for inspecting slow queries, connection errors, or PHP PDO/Doctrine connection issues directly on Windows.
* **`postgres/Dockerfile` & `(config)`**: Provides a dedicated build context per database service for custom extensions (e.g., `uuid-ossp`, `pgcrypto`) and tuned local configuration (`postgresql.conf`, `pg_hba.conf`, or init scripts).
* **`.env`**: Single source of truth for ports, credentials, database names, and version tags, keeping sensitive values out of `docker-compose.yml` and aligning with PHP app `.env` files.
* **`docker-compose.yml`**: Orchestrates services, networks, volumes, and environment variables in one stack.

**Initial Windows 11 + PowerShell Considerations:**
1. **Line endings (`CRLF` vs. `LF`)**: Linux-based containers require `LF` (`\n`) line endings in shell scripts and config files rather than Windows `CRLF` (`\r\n`).
2. **Logs & bind mounts**: Official database images default to `stdout`/`stderr` unless explicitly configured to write logs into a mounted directory.

---

## 12.1.2. Requirements & Technical Analysis
**User Clarifications:**
* `logs/postgres` will centralize service logs by mounting a custom log directory.
* Future support for running multiple versions of PostgreSQL if needed.
* A shared network is required so containers can communicate with each other.
* Acknowledged the importance of `CRLF` vs. `LF` on Windows 11 + PowerShell.

**Detailed Technical Breakdown:**
1. **Centralized Service Logs (Mounted Log Directory):**
   * Official PostgreSQL images log to `stderr` by default.
   * To write physical log files to a host-mounted folder, PostgreSQL logging parameters (`logging_collector`, `log_directory`, `log_filename`) must match the mounted path inside the container.
   * Separating log subfolders by version (e.g., `logs/postgres15/`) prevents log collisions and simplifies diagnostics.
2. **Future Multi-Version PostgreSQL Support:**
   * PostgreSQL `PGDATA` directories are tied to a specific major version; isolating data folders per version prevents startup failures or data corruption.
   * Running multiple instances simultaneously requires distinct host port mappings and service/container names managed via `.env`.
3. **Shared Docker Network:**
   * Containers attached to the same user-defined Docker bridge network can communicate using their **service name** as the hostname and their **internal container port** (e.g., `5432`).
   * Keeps inter-container traffic (such as PHP app containers, admin tools, and databases) isolated and predictable while still allowing host access from Windows via `localhost`.
4. **CRLF vs. LF on Windows 11 + PowerShell:**
   * Windows uses `CRLF` (`\r\n`), while Linux containers expect `LF` (`\n`).
   * `CRLF` characters in scripts or Linux-parsed config files cause syntax errors or `not found` failures inside containers.
   * Editors (such as VS Code) should save files with `LF` and UTF-8 without BOM.

---

## 12.1.3. Initial Scope & Step-by-Step Implementation Plan
**Scope Adjustment:**
* Defer multiple PostgreSQL versions for now and begin exclusively with **PostgreSQL 15**.
* Rename the log folder from `logs/postgres` to `logs/postgres15` to stay version-consistent from day one.
* Use the default PostgreSQL port (`5432`).

**Execution Roadmap:**
1. **Verify Prerequisites on Windows 11** *(~2 min)*
   * Confirm Docker Desktop is running with the WSL 2 backend enabled, PowerShell is ready, and the editor is configured for `LF` line endings.
2. **Scaffold the Project Directory Tree** *(~2 min)*
   * Create `docker-env/`, `data_files/postgres15/`, `logs/postgres15/`, and `postgres/`, along with empty files for `.env`, `docker-compose.yml`, `postgres/Dockerfile`, and the custom PostgreSQL configuration file.
3. **Configure Environment Variables** *(~3 min)*
   * Populate `.env` with the Postgres 15 image tag, database name, superuser credentials, host port (`5432`), internal log directory path, and shared Docker network name.
4. **Define PostgreSQL Logging & Custom Configuration** *(~5 min)*
   * Create a custom configuration file in `postgres/` enabling `logging_collector`, pointing `log_directory` to the mounted path, and defining log filenames/rotation using `LF` line endings and UTF-8 without BOM.
5. **Set Up the Custom Dockerfile** *(~3 min)*
   * Base `postgres/Dockerfile` on the official PostgreSQL 15 image, copy the custom configuration file into the image, and configure ownership/write permissions for the `postgres` user on the container log directory.
6. **Orchestrate Service, Volumes, and Shared Network** *(~5 min)*
   * Configure `postgres15` in `docker-compose.yml` using `.env` variables: map port `5432:5432`, bind-mount `data_files/postgres15` to `PGDATA`, bind-mount `logs/postgres15` to the container log directory, apply the custom config flag, and connect the service to a shared bridge network.
7. **Build, Launch, and Validate the Stack** *(~5 min)*
   * Build and start the container in detached mode via PowerShell, then verify container health, confirm log files appear inside `logs/postgres15/` on Windows, and test a database connection on `localhost:5432`.

---

# 12.2 Step 1: Verify Prerequisites on Windows 11

## 12.2.1. Objective & Verification Commands

Before creating folders or configuration files, we verify that the Windows 11 environment, PowerShell, Docker Desktop, and text editor are properly configured for Linux-based containers.


### Check Docker Desktop & WSL 2 in PowerShell

```powershell
# 1. Verify Docker CLI and Engine are running
docker version

# 2. Verify Docker Compose v2 is available
docker compose version

# 3. Confirm Docker is using the Linux / WSL 2 engine (look for "OSType: linux")
docker info --format '{{.OSType}} | {{.OperatingSystem}}'

# 4. Check WSL status on Windows 11
wsl --status
```

**Expected Results:**
* `docker version` returns both **Client** and **Server** sections without connection errors.
* `docker compose version` returns `Docker Compose version v2.x.x` (or higher).
* `docker info` outputs `linux | Docker Desktop`.
* `wsl --status` confirms **Default Version: 2**.

### Verify Port `5432` Is Free on Windows

Since we are using PostgreSQL's default port `5432` on the host, ensure no local Windows installation of PostgreSQL (or another service) is already listening on it:

```powershell
Get-NetTCPConnection -LocalPort 5432 -ErrorAction SilentlyContinue
```

**Expected Results:**
* **No output** means port `5432` is completely free.
* If a row returns with `State: Listen`, a local service is occupying `5432` and would conflict with our container mapping.

### Configure Line Endings (`LF`) in Your Editor
* **VS Code:** Open Settings (`Ctrl + ,`), search for `files.eol`, and set **Files: Eol** to `\n` (`LF`). Verify the bottom-right status bar displays **`LF`** when editing project files.

---

## 12.2.2. Q&A: `.gitattributes` Syntax and WSL 2 Mounting

### User Questions
1. `.gitattributes` file: what is its syntax and what should it include?
2. Concern about WSL 2 causing issues when mounting `/mnt/c/projects/docker-env` (`C:\projects\docker-env`).

---

### Answer 1: `.gitattributes` Syntax and What to Include

In Git, a `.gitattributes` file (placed at the root of your repository) maps file patterns to attributes that Git applies when checking files in or out.

* **Basic syntax:** `<pattern> <attribute1> <attribute2>`
* **`* text=auto`**: Lets Git detect text vs. binary files automatically and normalize line endings to `LF` inside the Git repository.
* **`eol=lf`**: Forces specific files to use `LF` (`\n`) when checked out into your working directory on Windows, overriding default `CRLF` behavior.
* **`eol=crlf`**: Forces specific Windows-only files (such as `.bat` or `.cmd`) to use `CRLF` (`\r\n`).
* **`binary`**: Marks binary files (such as images, archives, or compiled files) so Git never alters their bytes or line endings.

**Recommended `.gitattributes` for `docker-env` on Windows 11:**

```gitattributes
# Normalize text files in Git and default to LF
* text=auto eol=lf

# Explicitly enforce LF for shell scripts, Dockerfiles, configs, and compose files
*.sh text eol=lf
Dockerfile* text eol=lf
*.conf text eol=lf
*.yml text eol=lf
*.yaml text eol=lf
*.env* text eol=lf

# Optional: keep Windows batch/PowerShell scripts as CRLF if needed
*.bat text eol=crlf
*.cmd text eol=crlf

# Treat binary assets as untouched binary data
*.png binary
*.jpg binary
*.jpeg binary
*.gif binary
*.zip binary
*.tar.gz binary
```
---

### Answer 2: WSL 2 and Mounting `C:\projects\docker-env` (`/mnt/c/projects/docker-env`)


On **Docker Desktop for Windows**, the **WSL 2 backend is the standard and recommended engine** (the older Hyper-V VM backend was deprecated/removed in newer Docker Desktop versions).

* **How `C:\` mounts work with WSL 2:** When you bind-mount a Windows path like `C:\projects\docker-env` in `docker-compose.yml` from PowerShell, Docker Desktop automatically translates and shares `C:\` into the WSL 2 / Linux container runtime—you do **not** have to manually type `/mnt/c/...` in your Compose file.
* **Common pitfalls with `/mnt/c/...` mounts:**
  1. **Path typos:** Ensure exact path matching (e.g., `C:\projects\docker-env` vs. `c:\projects\docker-en`).
  2. **Performance & permissions on `C:\`:** Accessing Windows `C:\` files via the 9P filesystem bridge from Linux is slower for heavy I/O or database workloads than Linux-native paths, and POSIX/Linux file permissions (`chmod`/`chown`) behave differently on Windows bind mounts.
  3. **Path formatting:** Ensure the folder is accessible to Docker Desktop and avoid unusual characters or spaces in the path.

Running `docker compose` from PowerShell using relative paths (like `./data_files/postgres15` and `./logs/postgres15`) or `C:\projects\docker-env` works directly with WSL 2 enabled.

---

## 12.2.3. Q&A: NTFS vs. Unix Permissions on `data_files/postgres15`

### User Question
What about permissions in the folder `data_files/postgres15` across NTFS and Unix?

### Analysis & Practical Guidance

When mounting `data_files/postgres15` from Windows 11 (NTFS) into a Linux PostgreSQL container (Unix permissions), NTFS and Unix permissions operate on two separate layers, and PostgreSQL enforces strict rules on `PGDATA`:

1. **On Windows 11 (NTFS):**
   * The folder `data_files/postgres15` lives on an NTFS volume and uses Windows ACLs (Access Control Lists). Your current Windows user account needs normal read/write/modify access to the folder.
2. **Inside the Linux Container (Unix):**
   * PostgreSQL's `initdb` and server process require the `PGDATA` directory to be owned by the **`postgres`** user (typically UID/GID `999` in official Postgres images) with restrictive directory permissions **`0700`** (`drwx------`) or **`0750`**.
   * If permissions are too open (such as `0777`) or owned by the wrong UID when initialized, PostgreSQL refuses to start with a data directory permissions error.
3. **Why bind-mounting NTFS for `PGDATA` requires care:**
   * NTFS does not natively store standard Linux `chown`/`chmod` ownership bits in the same way a native Linux filesystem does. Docker Desktop's WSL 2 / 9P translation exposes Windows files to Linux, which can cause permission mismatches or slower database I/O compared to a Docker named volume—though bind-mounting works when the folder starts completely empty so the container entrypoint can initialize it cleanly.

### Actionable Steps for `data_files/postgres15` Permissions

1. **Create a clean, empty `data_files/postgres15` directory on NTFS:**
   * Ensure your Windows user owns it and has full control on NTFS (`C:\projects\docker-env\data_files\postgres15`), and keep it empty before starting the container for the first time.
   * *Verification:* In PowerShell, `Get-ChildItem .\data_files\postgres15` returns no files or subfolders and shows no access-denied errors.
2. **Let the container initialize `PGDATA` on first run:**
   * Start the PostgreSQL 15 container pointing to the mounted data directory so its entrypoint sets ownership and directory modes as it initializes the database cluster.
   * *Verification:* Container logs show `PostgreSQL init process complete; ready for start up` rather than `data directory "..." has wrong ownership` or `permissions ... are too open`.
3. **Avoid manual `chmod`/`chown` workarounds on Windows bind mounts unless needed:**
   * If permission errors ever appear on a Windows bind mount, diagnose whether the directory already had conflicting files or whether a Docker named volume is a more reliable choice for `PGDATA` on Windows 11.
   * *Verification:* The container remains running (`Up`) without restarting in a crash loop.

---

# 12.3: Scaffold the Project Directory Tree

## 12.3.1. Objective

Create the root `docker-env/` directory tree along with isolated subdirectories for PostgreSQL 15 data and logs, plus empty placeholder files for `.env`, `docker-compose.yml`, `postgres/Dockerfile`, and `postgres/postgresql.conf` using PowerShell on Windows 11.

## 12.3.2. PowerShell Commands Executed

```powershell
# Move to your working directory (adjust if needed)
cd C:\projects

# Create the directory tree
New-Item -ItemType Directory -Force -Path @(
    "docker-env\data_files\postgres15",
    "docker-env\logs\postgres15",
    "docker-env\postgres"
) | Out-Null

# Create empty placeholder files
New-Item -ItemType File -Force -Path @(
    "docker-env\.env",
    "docker-env\docker-compose.yml",
    "docker-env\postgres\Dockerfile",
    "docker-env\postgres\postgresql.conf"
) | Out-Null
```

*(Design Note: In the initial layout diagram, `postgres/` contained `Dockerfile` and `(config)`. Using a concrete filename `postgres\postgresql.conf` keeps configuration paths explicit and predictable.)*

## 12.3.3. Verification Command & Output

**Command:**

```powershell
Get-ChildItem -Path "C:\projects\docker-env" -Recurse -Force |
    Select-Object Mode, Length, FullName
```

**Actual PowerShell Output:**

```text
Mode   Length FullName
----   ------ --------
d-----        C:\projects\docker-env\data_files
d-----        C:\projects\docker-env\logs
d-----        C:\projects\docker-env\postgres
-a---- 0      C:\projects\docker-env\.env
-a---- 0      C:\projects\docker-env\docker-compose.yml
d-----        C:\projects\docker-env\data_files\postgres15
d-----        C:\projects\docker-env\logs\postgres15
-a---- 0      C:\projects\docker-env\postgres\Dockerfile
-a---- 0      C:\projects\docker-env\postgres\postgresql.conf
```

## 12.3.4. Verification Summary & Next-Step Reminders

* **Directory Isolation:** `data_files\postgres15` and `logs\postgres15` exist and are completely empty, ready for clean container initialization on first startup.
* **Placeholder Files:** `.env`, `docker-compose.yml`, `postgres\Dockerfile`, and `postgres\postgresql.conf` exist with `Length` of `0` bytes.
* **Line Ending Reminder:** Before populating these four files in subsequent steps, ensure your editor saves them with **LF** (`\n`) line endings and **UTF-8 without BOM** encoding to prevent Windows-to-Linux parsing issues.

---

# 12.4 Configure Environment Variables (`.env`)

## 12.4.1. Objective

Populate `C:\projects\docker-env\.env` as the single source of truth for image tags, container names, credentials, ports, internal data/log paths, and the shared Docker network name, ensuring `LF` line endings and UTF-8 without BOM encoding.

## 12.4.2. `.env` File Contents

File path: `C:\projects\docker-env\.env`

```dotenv
# ==========================================
# Shared Network Configuration
# ==========================================
SHARED_NETWORK_NAME=local_dev_network

# ==========================================
# PostgreSQL 15 Configuration
# ==========================================
POSTGRES15_IMAGE_TAG=15-alpine
POSTGRES15_CONTAINER_NAME=db_postgres15
POSTGRES15_HOST_PORT=5432

# Database & Credentials (Local Development)
POSTGRES15_DB=app_local
POSTGRES15_USER=dev_user
POSTGRES15_PASSWORD=dev_secret_pass

# Internal Container Paths
POSTGRES15_DATA_DIR=/var/lib/postgresql/data/pgdata
POSTGRES15_LOG_DIR=/var/log/postgresql
```

## 12.4.3. Architectural Rationale & Variable Breakdown

* **`SHARED_NETWORK_NAME=local_dev_network`**: Defines a clear, reusable name for the shared Docker bridge network so future PHP application containers or database admin tools (such as pgAdmin or Adminer) can connect seamlessly.
* **`POSTGRES15_*` Prefix**: Scopes environment variables by service and version so `.env` remains clean and collision-free if additional engines or PostgreSQL versions (e.g., `POSTGRES17_*` or `MYSQL80_*`) are added later.
* **`POSTGRES15_IMAGE_TAG=15-alpine`**: Pins PostgreSQL major version `15` on a lightweight Alpine Linux image. Switching to the Debian-based `15` image later requires changing only this single value.
* **`POSTGRES15_DATA_DIR=/var/lib/postgresql/data/pgdata`**: Points `PGDATA` to a subdirectory inside `/var/lib/postgresql/data` to prevent `initdb` failures if the mounted host directory contains hidden OS metadata files.
* **`POSTGRES15_LOG_DIR=/var/log/postgresql`**: Defines the exact internal container path where PostgreSQL writes physical log files, which maps to `./logs/postgres15` on the Windows host.

## 12.4.4. PowerShell Verification Command & Output

**Command:**

```powershell
# Verify contents and check that no CRLF (\r) characters exist
$raw = Get-Content -Path "C:\projects\docker-env\.env" -Raw
if ($raw -match "`r") { Write-Warning "CRLF detected!" } else { Write-Host "OK: LF line endings verified." }
```

**Actual PowerShell Output:**

```text
OK: LF line endings verified.
```

## 12.4.5. Verification Summary

* `C:\projects\docker-env\.env` is saved with all required configuration variables.
* Line ending verification confirmed pure `LF` (`\n`) formatting with zero `CRLF` (`\r\n`) characters.


---

# 12.5. Define PostgreSQL Logging & Custom Configuration

## 12.5.1. Objective

Configure `C:\projects\docker-env\postgres\postgresql.conf` so PostgreSQL 15 writes physical log files into the container directory mapped to `./logs/postgres15` (`POSTGRES15_LOG_DIR=/var/log/postgresql`) with clean rotation and helpful local PHP development diagnostics, using `LF` line endings and UTF-8 without BOM encoding.

## 12.5.2. `postgresql.conf` File Contents

File path: `C:\projects\docker-env\postgres\postgresql.conf`

```ini
# ==========================================
# Custom PostgreSQL 15 Configuration (Local Dev)
# ==========================================

# Listen on all interfaces inside the container
listen_addresses = '*'

# File-based logging into mounted log directory (/var/log/postgresql)
logging_collector = on
log_directory = '/var/log/postgresql'
log_filename = 'postgresql-%Y-%m-%d_%H%M%S.log'
log_file_mode = 0640
log_rotation_age = 1d
log_rotation_size = 50MB
log_truncate_on_rotation = off

# Log format & useful PHP / query diagnostics
log_line_prefix = '%m [%p] %q%u@%d/%a '
log_timezone = 'UTC'
log_min_messages = warning
log_error_verbosity = default
log_connections = on
log_disconnections = on
log_lock_waits = on
log_temp_files = 0
```

## 12.5.3. Configuration Breakdown & Rationale

* **`listen_addresses = '*'`**: Ensures PostgreSQL accepts connections over the Docker bridge network and mapped host port rather than only internal container loopback.
* **`logging_collector = on` & `log_directory = '/var/log/postgresql'`**: Official PostgreSQL images output logs to `stderr` by default. Enabling `logging_collector` and setting `log_directory` to `/var/log/postgresql` writes physical `.log` files directly into the directory bind-mounted to `logs\postgres15` on Windows.
* **`log_filename` & rotation (`log_rotation_age = 1d`, `log_rotation_size = 50MB`)**: Generates timestamped log files and rotates them daily or at `50MB` so local development logs remain organized and manageable.
* **`log_line_prefix = '%m [%p] %q%u@%d/%a '`**: Includes timestamp (`%m`), process ID (`%p`), user (`%u`), database (`%d`), and application name (`%a`—useful when PHP PDO specifies `application_name` or when inspecting multiple clients).
* **`log_connections = on` & `log_disconnections = on`**: Aids local PHP development when troubleshooting PDO connections, connection pooling, or unexpected disconnects.
* **`log_lock_waits = on` & `log_temp_files = 0`**: Surfaces lock contention and temporary disk sorts/hashes early during query testing.

## 12.5.4. PowerShell Verification Command & Output

**Command:**

```powershell
# Verify non-empty content and LF line endings
$confPath = "C:\projects\docker-env\postgres\postgresql.conf"
$raw = Get-Content -Path $confPath -Raw
if ([string]::IsNullOrWhiteSpace($raw)) {
    Write-Warning "File is empty!"
} elseif ($raw -match "`r") {
    Write-Warning "CRLF detected!"
} else {
    Write-Host "OK: postgresql.conf saved with LF line endings."
}
```

**Actual PowerShell Output:**

```text
OK: postgresql.conf saved with LF line endings.
```

## 12.5.5. Verification Summary

* `C:\projects\docker-env\postgres\postgresql.conf` is saved with file-based logging and local PHP diagnostic settings.
* Line ending verification confirmed pure `LF` (`\n`) formatting with zero `CRLF` (`\r\n`) characters.

---

# 12.6. Set Up the Custom Dockerfile

## 12.6.1. Objective

Configure `C:\projects\docker-env\postgres\Dockerfile` to build a custom PostgreSQL 15 image that integrates our custom `postgresql.conf` outside of `PGDATA` and ensures the internal log directory (`/var/log/postgresql`) exists with proper ownership and permissions for the `postgres` system user, saved with `LF` line endings and UTF-8 without BOM encoding.

## 12.6.2. `Dockerfile` Contents

File path: `C:\projects\docker-env\postgres\Dockerfile`

```dockerfile
ARG POSTGRES_IMAGE_TAG=15-alpine
FROM postgres:${POSTGRES_IMAGE_TAG}

# Ensure internal log directory exists and is owned by the postgres user
RUN mkdir -p /var/log/postgresql \
    && chown -R postgres:postgres /var/log/postgresql \
    && chmod 750 /var/log/postgresql

# Copy custom PostgreSQL configuration into the image
COPY --chown=postgres:postgres postgresql.conf /etc/postgresql/postgresql.conf
```

## 12.6.3. Instruction Breakdown & Rationale

* **`ARG POSTGRES_IMAGE_TAG=15-alpine` & `FROM postgres:${POSTGRES_IMAGE_TAG}`**: Allows `docker-compose.yml` to inject `POSTGRES15_IMAGE_TAG` from `.env` as a build argument while defaulting cleanly to `15-alpine` if the image is built standalone.
* **`RUN mkdir -p /var/log/postgresql && chown -R postgres:postgres /var/log/postgresql && chmod 750 /var/log/postgresql`**: Pre-creates `/var/log/postgresql` inside the image and assigns ownership to the `postgres` system user (`UID 70` on Alpine, `UID 999` on Debian) before any bind mount or database startup occurs.
* **`COPY --chown=postgres:postgres postgresql.conf /etc/postgresql/postgresql.conf`**: Places the custom configuration file in a dedicated path outside `PGDATA` so `initdb` never overwrites it during initial cluster creation. In Step 6, PostgreSQL will be instructed to load this file via `-c config_file=/etc/postgresql/postgresql.conf`.

## 12.6.4. PowerShell Verification Command & Output

**Command:**

```powershell
# Verify non-empty content and LF line endings
$dockerfilePath = "C:\projects\docker-env\postgres\Dockerfile"
$raw = Get-Content -Path $dockerfilePath -Raw
if ([string]::IsNullOrWhiteSpace($raw)) {
    Write-Warning "Dockerfile is empty!"
} elseif ($raw -match "`r") {
    Write-Warning "CRLF detected!"
} else {
    Write-Host "OK: Dockerfile saved with LF line endings."
}
```

**Actual PowerShell Output:**

```text
OK: Dockerfile saved with LF line endings.
```

## 12.6.5. Verification Summary

* `C:\projects\docker-env\postgres\Dockerfile` is configured to bundle `postgresql.conf` and prepare `/var/log/postgresql` with `postgres` ownership.
* Line ending verification confirmed pure `LF` (`\n`) formatting with zero `CRLF` (`\r\n`) characters.

---

# 12.7. Orchestrate Service, Volumes, and Shared Network (`docker-compose.yml`)

## 12.7.1. Service Configuration (`C:\projects\docker-env\docker-compose.yml`)

Saved with **LF** line endings and **UTF-8 without BOM**:

```yaml
services:
  postgres15:
    build:
      context: ./postgres
      dockerfile: Dockerfile
      args:
        POSTGRES_IMAGE_TAG: ${POSTGRES15_IMAGE_TAG:-15-alpine}
    container_name: ${POSTGRES15_CONTAINER_NAME:-db_postgres15}
    restart: unless-stopped
    environment:
      POSTGRES_DB: ${POSTGRES15_DB}
      POSTGRES_USER: ${POSTGRES15_USER}
      POSTGRES_PASSWORD: ${POSTGRES15_PASSWORD}
      PGDATA: ${POSTGRES15_DATA_DIR:-/var/lib/postgresql/data/pgdata}
    command:
      - postgres
      - -c
      - config_file=/etc/postgresql/postgresql.conf
    ports:
      - "${POSTGRES15_HOST_PORT:-5432}:5432"
    volumes:
      - ./data_files/postgres15:/var/lib/postgresql/data
      - ./logs/postgres15:${POSTGRES15_LOG_DIR:-/var/log/postgresql}
    networks:
      - shared_net
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U $${POSTGRES_USER:-dev_user} -d $${POSTGRES_DB:-app_local}"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 15s

networks:
  shared_net:
    name: ${SHARED_NETWORK_NAME:-local_dev_network}
    driver: bridge
```

---

## 12.7.2. Key Design Choices

* **Bind-mounting `./data_files/postgres15` to `/var/lib/postgresql/data` with `PGDATA=/var/lib/postgresql/data/pgdata`**: Keeps the Windows directory cleanly mapped while PostgreSQL initializes its cluster inside the `pgdata` subdirectory, avoiding hidden OS metadata files at the root of the mount.
* **Bind-mounting `./logs/postgres15` to `${POSTGRES15_LOG_DIR}`**: Connects `postgresql.conf`'s `log_directory` (`/var/log/postgresql`) directly to `C:\projects\docker-env\logs\postgres15` on Windows so log files persist and remain easy to inspect.
* **Explicit `-c config_file=/etc/postgresql/postgresql.conf`**: Loads the custom configuration file baked into the image during Step 5.
* **Named bridge network `${SHARED_NETWORK_NAME}` (`local_dev_network`)**: Provides a predictable network name so future containers (such as PHP/web apps or database clients) can join and reach PostgreSQL over Docker's internal DNS.
* **Healthcheck with `pg_isready`**: Reports when PostgreSQL 15 is genuinely ready to accept connections rather than merely having a running container process.

---

## 12.7.3. Validation & Startup Verification Outputs

### Line-Ending & Compose Config Validation
```powershell
# 1. Verify LF line endings
$composePath = "C:\projects\docker-env\docker-compose.yml"
$raw = Get-Content -Path $composePath -Raw
if ([string]::IsNullOrWhiteSpace($raw)) {
    Write-Warning "docker-compose.yml is empty!"
} elseif ($raw -match "`r") {
    Write-Warning "CRLF detected!"
} else {
    Write-Host "OK: docker-compose.yml saved with LF line endings."
}

# 2. Validate parsed Compose config (from C:\projects\docker-env)
cd C:\projects\docker-env
docker compose config
```

Output:
```text
OK: docker-compose.yml saved with LF line endings.
name: docker-env
services:
  postgres15:
    restart: unless-stopped
    container_name: db_postgres15
    command:
- postgres
- -c
- config_file=/etc/postgresql/postgresql.conf
    environment:
      PGDATA: /var/lib/postgresql/data/pgdata
      POSTGRES_DB: app_local
      POSTGRES_PASSWORD: dev_secret_pass
      POSTGRES_USER: dev_user
    networks:
      shared_net: null
    volumes:
- type: bind
        source: C:\projects\docker-env\data_files\postgres15
        target: /var/lib/postgresql/data
        bind: {}
- type: bind
        source: C:\projects\docker-env\logs\postgres15
        target: /var/log/postgresql
        bind: {}
    build:
      context: C:\projects\docker-env\postgres
      dockerfile: Dockerfile
      args:
        POSTGRES_IMAGE_TAG: 15-alpine
    healthcheck:
      test:
- CMD-SHELL
- pg_isready -U $${POSTGRES_USER:-dev_user} -d $${POSTGRES_DB:-app_local}
      timeout: 5s
      interval: 10s
      retries: 5
      start_period: 15s
    ports:
- mode: ingress
        target: 5432
        published: "5432"
        protocol: tcp
networks:
  shared_net:
    name: local_dev_network
    driver: bridge
```

### Container Health, Readiness, and Network Inspection
```powershell
docker compose ps
docker compose exec postgres15 pg_isready -U dev_user -d app_local
docker network inspect local_dev_network
```

Output:
```text
NAME            IMAGE                   COMMAND                  SERVICE      CREATED          STATUS                    PORTS
db_postgres15   docker-env-postgres15   "docker-entrypoint.s…"   postgres15   32 seconds ago   Up 27 seconds (healthy)   0.0.0.0:5432->5432/tcp, [::]:5432->5432/tcp
```
```text
/run/postgresql:5432 - accepting connections
```
```text
[
    {
        "Name": "local_dev_network",
        "Id": "90b638f89fd2083d7182e04c06a86e9aafd606521732923d31b63b0a03b3a50a",
        "Created": "2026-11-21T12:48:49.310270262Z",
        "Scope": "local",
        "Driver": "bridge",
        "EnableIPv4": true,
        "EnableIPv6": false,
        "IPAM": {
            "Driver": "default",
            "Options": null,
            "Config": [
                {
                    "Subnet": "172.18.0.0/16",
                    "Gateway": "172.18.0.1"
                }
            ]
        },
        "Internal": false,
        "Attachable": false,
        "Ingress": false,
        "ConfigFrom": {
            "Network": ""
        },
        "ConfigOnly": false,
        "Options": {
            "com.docker.network.enable_ipv4": "true",
            "com.docker.network.enable_ipv6": "false"
        },
        "Labels": {
            "com.docker.compose.config-hash": "00efd745efd62790d5058254fcb33b13cd38b5c7848533f43d034fa4c620d81c",
            "com.docker.compose.network": "shared_net",
            "com.docker.compose.project": "docker-env",
            "com.docker.compose.version": "5.7.0"
        },
        "Containers": {
            "1e913aa3f1519dcb30959fc080c083d820a04e384d0be2b58b70c89a2b7ff8bd": {
                "Name": "db_postgres15",
                "EndpointID": "431dbf3159ae39428dbdcbc63fa75447bdba0cee1efc82486c35a22b06316cec",
                "MacAddress": "96:9e:b7:e6:4b:8f",
                "IPv4Address": "172.18.0.2/16",
                "IPv6Address": ""
            }
        },
        "Status": {
            "IPAM": {
                "Subnets": {
                    "172.18.0.0/16": {
                        "IPsInUse": 4,
                        "DynamicIPsAvailable": 65532
                    }
                }
            }
        }
    }
]
```

### Host Bind-Mount Verification (`logs\postgres15` & `data_files\postgres15`)
```powershell
Get-ChildItem -Path "C:\projects\docker-env\logs\postgres15"
Get-ChildItem -Path "C:\projects\docker-env\data_files\postgres15" -Recurse -Depth 2
```

Output:
```text
    Directory: C:\projects\docker-env\logs\postgres15


Mode                 LastWriteTime         Length Name
----                 -------------         ------ ----
-a----        21/11/2026     12:49           2288 postgresql-2026-11-21_124905.log
-a----        21/11/2026     12:49              0 postgresql-2026-11-21_124909.log


    Directory: C:\projects\docker-env\data_files\postgres15


Mode                 LastWriteTime         Length Name
----                 -------------         ------ ----
da----        21/11/2026     12:49                pgdata


    Directory: C:\projects\docker-env\data_files\postgres15\pgdata


Mode                 LastWriteTime         Length Name
----                 -------------         ------ ----
d-----        21/11/2026     12:49                base
d-----        21/11/2026     12:49                global
d-----        21/11/2026     12:48                pg_commit_ts
d-----        21/11/2026     12:48                pg_dynshmem
d-----        21/11/2026     12:49                pg_logical
d-----        21/11/2026     12:48                pg_multixact
d-----        21/11/2026     12:48                pg_notify
d-----        21/11/2026     12:48                pg_replslot
d-----        21/11/2026     12:48                pg_serial
d-----        21/11/2026     12:48                pg_snapshots
d-----        21/11/2026     12:49                pg_stat
d-----        21/11/2026     12:48                pg_stat_tmp
d-----        21/11/2026     12:48                pg_subtrans
d-----        21/11/2026     12:48                pg_tblspc
d-----        21/11/2026     12:48                pg_twophase
d-----        21/11/2026     12:48                pg_wal
d-----        21/11/2026     12:48                pg_xact
-a----        21/11/2026     12:49             60 current_logfiles
-a----        21/11/2026     12:49           4821 pg_hba.conf
-a----        21/11/2026     12:48           1636 pg_ident.conf
-a----        21/11/2026     12:48              3 PG_VERSION
-a----        21/11/2026     12:48             88 postgresql.auto.conf
-a----        21/11/2026     12:48          29496 postgresql.conf
-a----        21/11/2026     12:49             75 postmaster.opts
-a----        21/11/2026     12:49             97 postmaster.pid
```

---

# 12.8. Runtime & Persistence Verification

## 12.8.1. Objective

Validate end-to-end runtime behavior of `db_postgres15` (`app_local` as `dev_user`) on Windows 11 / Docker Desktop, including `psql` connectivity, data persistence across `docker compose down` and `docker compose up -d`, active `postgresql.conf` directives, host-mounted log files, Windows-to-container TCP reachability on port `5432`, and container-to-container DNS/connectivity over `local_dev_network`.

## 12.8.2. `psql` Check & Persistence Test Across `docker compose down`

### 12.8.2.1 Initial `psql` Verification & Test Row Creation

**Commands:**

```powershell
docker compose exec -e PGPASSWORD=dev_secret_pass postgres15 psql -U dev_user -d app_local -c "SELECT version(), current_database(), current_user;"
docker compose exec -e PGPASSWORD=dev_secret_pass postgres15 psql -U dev_user -d app_local -c "CREATE TABLE IF NOT EXISTS persistence_check (id SERIAL PRIMARY KEY, note TEXT NOT NULL, created_at TIMESTAMPTZ DEFAULT now()); INSERT INTO persistence_check (note) VALUES ('before_down_test') RETURNING *;"
```

**Actual PowerShell Output:**

```text
                                         version                                          | current_database | current_user
------------------------------------------------------------------------------------------+------------------+--------------
 PostgreSQL 15.20 on x86_64-pc-linux-musl, compiled by gcc (Alpine 15.2.0) 15.2.0, 64-bit | app_local        | dev_user
(1 row)

CREATE TABLE
 id |       note       |          created_at
----+------------------+-------------------------------
  1 | before_down_test | 2026-11-21 12:59:53.469732+00
(1 row)

INSERT 0 1
```

### 12.8.2.2 Teardown (`docker compose down`), Host Mount Check, Recreation (`docker compose up -d`), and Data Verification

**Commands:**

```powershell
docker compose down
docker compose ps -a
Get-ChildItem -Path "C:\projects\docker-env\data_files\postgres15\pgdata"
docker compose up -d
docker compose ps
docker compose exec -e PGPASSWORD=dev_secret_pass postgres15 psql -U dev_user -d app_local -c "SELECT * FROM persistence_check ORDER BY id;"
```

**Actual PowerShell Output:**

```text
[+] down 2/2
 ✔ Container db_postgres15   Removed                                                                                                                                                                         0.7s
 ✔ Network local_dev_network Removed                                                                                                                                                                         0.3s
NAME      IMAGE     COMMAND   SERVICE   CREATED   STATUS    PORTS


    Directory: C:\projects\docker-env\data_files\postgres15\pgdata


Mode                 LastWriteTime         Length Name
----                 -------------         ------ ----
d-----        21/11/2026     12:49                base
d-----        21/11/2026     12:50                global
d-----        21/11/2026     12:48                pg_commit_ts
d-----        21/11/2026     12:48                pg_dynshmem
d-----        21/11/2026     12:49                pg_logical
d-----        21/11/2026     12:48                pg_multixact
d-----        21/11/2026     12:48                pg_notify
d-----        21/11/2026     12:48                pg_replslot
d-----        21/11/2026     12:48                pg_serial
d-----        21/11/2026     12:48                pg_snapshots
d-----        21/11/2026     13:00                pg_stat
d-----        21/11/2026     12:48                pg_stat_tmp
d-----        21/11/2026     12:48                pg_subtrans
d-----        21/11/2026     12:48                pg_tblspc
d-----        21/11/2026     12:48                pg_twophase
d-----        21/11/2026     12:48                pg_wal
d-----        21/11/2026     12:48                pg_xact
-a----        21/11/2026     12:49             60 current_logfiles
-a----        21/11/2026     12:49           4821 pg_hba.conf
-a----        21/11/2026     12:48           1636 pg_ident.conf
-a----        21/11/2026     12:48              3 PG_VERSION
-a----        21/11/2026     12:48             88 postgresql.auto.conf
-a----        21/11/2026     12:48          29496 postgresql.conf
-a----        21/11/2026     12:49             75 postmaster.opts
[+] up 2/2
 ✔ Network local_dev_network Created                                                                                                                                                                         0.1s
 ✔ Container db_postgres15   Started                                                                                                                                                                         0.5s
NAME            IMAGE                   COMMAND                  SERVICE      CREATED         STATUS                           PORTS
db_postgres15   docker-env-postgres15   "docker-entrypoint.s…"   postgres15   2 seconds ago   Up 1 second (health: starting)   0.0.0.0:5432->5432/tcp, [::]:5432->5432/tcp
 id |       note       |          created_at
----+------------------+-------------------------------
  1 | before_down_test | 2026-11-21 12:59:53.469732+00
(1 row)
```

## 12.8.3. Additional Runtime, Configuration, Logging & Network Checks

### 12.8.3.1 Active Custom `postgresql.conf` Directives

**Command:**

```powershell
docker compose exec -e PGPASSWORD=dev_secret_pass postgres15 psql -U dev_user -d app_local -c "SHOW config_file; SHOW logging_collector; SHOW log_directory; SHOW log_connections; SHOW log_lock_waits;"
```

**Actual PowerShell Output:**

```text
           config_file
---------------------------------
 /etc/postgresql/postgresql.conf
(1 row)

 logging_collector
-------------------
 on
(1 row)

    log_directory
---------------------
 /var/log/postgresql
(1 row)

 log_connections
-----------------
 on
(1 row)

 log_lock_waits
----------------
 on
(1 row)
```

### 12.8.3.2 Host Log File Output (`C:\projects\docker-env\logs\postgres15`)

**Commands:**

```powershell
$latestLog = Get-ChildItem -Path "C:\projects\docker-env\logs\postgres15\*.log" | Sort-Object LastWriteTime -Descending | Select-Object -First 1
Get-Content -Path $latestLog.FullName -Tail 20
```

**Actual PowerShell Output:**

```text
2026-11-21 13:02:00.428 UTC [106] dev_user@app_local/[unknown] LOG:  connection authorized: user=dev_user database=app_local application_name=pg_isready
2026-11-21 13:02:00.460 UTC [106] dev_user@app_local/pg_isready LOG:  disconnection: session time: 0:00:00.042 user=dev_user database=app_local host=[local]
2026-11-21 13:02:10.553 UTC [114] [unknown]@[unknown]/[unknown] LOG:  connection received: host=[local]
2026-11-21 13:02:10.562 UTC [114] dev_user@app_local/[unknown] LOG:  connection authorized: user=dev_user database=app_local application_name=pg_isready
2026-11-21 13:02:10.596 UTC [114] dev_user@app_local/pg_isready LOG:  disconnection: session time: 0:00:00.043 user=dev_user database=app_local host=[local]
2026-11-21 13:02:20.654 UTC [121] [unknown]@[unknown]/[unknown] LOG:  connection received: host=[local]
2026-11-21 13:02:20.664 UTC [121] dev_user@app_local/[unknown] LOG:  connection authorized: user=dev_user database=app_local application_name=pg_isready
2026-11-21 13:02:20.696 UTC [121] dev_user@app_local/pg_isready LOG:  disconnection: session time: 0:00:00.042 user=dev_user database=app_local host=[local]
2026-11-21 13:02:28.807 UTC [129] [unknown]@[unknown]/[unknown] LOG:  connection received: host=[local]
2026-11-21 13:02:28.816 UTC [129] dev_user@app_local/[unknown] LOG:  connection authorized: user=dev_user database=app_local application_name=pg_isready
2026-11-21 13:02:28.849 UTC [129] dev_user@app_local/pg_isready LOG:  disconnection: session time: 0:00:00.042 user=dev_user database=app_local host=[local]
2026-11-21 13:02:38.934 UTC [136] [unknown]@[unknown]/[unknown] LOG:  connection received: host=[local]
2026-11-21 13:02:38.944 UTC [136] dev_user@app_local/[unknown] LOG:  connection authorized: user=dev_user database=app_local application_name=pg_isready
2026-11-21 13:02:38.978 UTC [136] dev_user@app_local/pg_isready LOG:  disconnection: session time: 0:00:00.044 user=dev_user database=app_local host=[local]
2026-11-21 13:02:41.038 UTC [143] [unknown]@[unknown]/[unknown] LOG:  connection received: host=[local]
2026-11-21 13:02:41.047 UTC [143] dev_user@app_local/[unknown] LOG:  connection authorized: user=dev_user database=app_local application_name=psql
2026-11-21 13:02:41.082 UTC [143] dev_user@app_local/psql LOG:  disconnection: session time: 0:00:00.044 user=dev_user database=app_local host=[local]
2026-11-21 13:02:49.061 UTC [151] [unknown]@[unknown]/[unknown] LOG:  connection received: host=[local]
2026-11-21 13:02:49.070 UTC [151] dev_user@app_local/[unknown] LOG:  connection authorized: user=dev_user database=app_local application_name=pg_isready
2026-11-21 13:02:49.106 UTC [151] dev_user@app_local/pg_isready LOG:  disconnection: session time: 0:00:00.045 user=dev_user database=app_local host=[local]
```

### 12.8.3.3 Host TCP Reachability & Shared-Network DNS Connectivity

**Commands:**

```powershell
Test-NetConnection -ComputerName localhost -Port 5432
docker run --rm --network local_dev_network alpine:3.20 sh -c "apk add --no-cache postgresql15-client >/dev/null && pg_isready -h db_postgres15 -p 5432 -U dev_user -d app_local"
```

**Actual PowerShell Output:**

```text
ComputerName     : localhost
RemoteAddress    : ::1
RemotePort       : 5432
InterfaceAlias   : Loopback Pseudo-Interface 1
SourceAddress    : ::1
TcpTestSucceeded : True


Unable to find image 'alpine:3.20' locally
3.20: Pulling from library/alpine
25f1d6b1951a: Pull complete
d4050d56ebf2: Download complete
3a030ca3f633: Download complete
Digest: sha256:d9e853e87e55526f6b2917df91a2115c36dd7c696a35be12163d44e6e2a4b6bc
Status: Downloaded newer image for alpine:3.20
- Setting postgresql15 as the default version
WARNING: opening from cache https://dl-cdn.alpinelinux.org/alpine/v3.20/main: No such file or directory
WARNING: opening from cache https://dl-cdn.alpinelinux.org/alpine/v3.20/community: No such file or directory
db_postgres15:5432 - accepting connections
```

## 12.8.4. Verification Summary

* Connected via `psql` to `app_local` as `dev_user` on PostgreSQL `15.20` (Alpine) and verified that test rows persist across `docker compose down` and `docker compose up -d` via `C:\projects\docker-env\data_files\postgres15\pgdata`.

* Confirmed active custom settings (`config_file = /etc/postgresql/postgresql.conf`, `logging_collector = on`, `log_directory = /var/log/postgresql`, `log_connections = on`, `log_lock_waits = on`).

* Verified physical log file generation in `C:\projects\docker-env\logs\postgres15` with custom `log_line_prefix` output.

* Verified host reachability on `localhost:5432` (`TcpTestSucceeded : True`) and container-to-container connectivity to `db_postgres15:5432` over `local_dev_network`.

---
