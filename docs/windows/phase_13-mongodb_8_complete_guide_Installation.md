# MongoDB 8 Service — Architecture, Persistence & Implementation Guide

## 1. Objective

Expand the existing Windows 11 `docker-env` local development stack to run **MongoDB 8** alongside **PostgreSQL 15**, adhering to the same modular, bind-mounted, environment-driven, and shared-network architecture (`local_dev_network`).

---

## 2. Target Directory & File Architecture

```text
docker-env/
│
├── data_files/                     # Persistent data (version-isolated per DB)
│   ├── postgres15/
│   └── mongodb8/
│
├── logs/                           # One subfolder per service
│   ├── postgres15/                 # Keep consistent naming with data_files
│   └── mongodb8/
│
├── postgres/
│   ├── Dockerfile
│   └── postgresql.conf
│
├── mongodb/
│   ├── Dockerfile
│   └── mongod.conf
│
├── .env                            # Single source of truth
└── docker-compose.yml              # Orchestrates all services
```

### Architectural Role of Each Component

* **`data_files/mongodb8/`**: Mirrors `data_files/postgres15/` so MongoDB 8 database files are version-isolated on the Windows host and survive `docker compose down` and container recreation.
* **`logs/mongodb8/`**: Mirrors the host-mounted logging pattern so MongoDB's log output writes directly to Windows and remains readable after container restarts. *(Aligning `logs/postgres15/` with `logs/mongodb8/` keeps folder naming uniform across services.)*
* **`mongodb/Dockerfile`**: Mirrors `postgres/Dockerfile` by building a custom MongoDB 8 image from the official base image, pre-creating internal log/config directories with proper ownership for the `mongodb` system user, and baking `mongod.conf` into the image.
* **`mongodb/mongod.conf`**: Mirrors `postgresql.conf` by centralizing MongoDB 8 daemon settings—storage path (`/data/db`), network binding (`0.0.0.0` on port `27017`), and file-based logging (`systemLog.destination: file` and `systemLog.path`)—so startup behavior is explicit and reproducible.
* **`.env`**: Remains the single source of truth for MongoDB 8 configuration (image tag, container name, root username/password, host port, and internal data/log paths) alongside PostgreSQL 15.
* **`docker-compose.yml`**: Orchestrates both `postgres15` and `mongodb8` on `local_dev_network`, wiring `.env` variables, build arguments, host bind mounts, port exposure (`27017`), and container health checks.

---

## 3. PostgreSQL 15 vs. MongoDB 8 Architectural Comparison

| Aspect | PostgreSQL 15 (`postgres15`) | MongoDB 8 (`mongodb8`) |
| :--- | :--- | :--- |
| **Base Image** | `postgres:15-alpine` | `mongo:8` (official image) |
| **Service User** | `postgres` | `mongodb` |
| **Config File & Flag** | `/etc/postgresql/postgresql.conf` via `-c config_file=...` | `/etc/mongod.conf` via `--config /etc/mongod.conf` |
| **Bootstrap Env Vars** | `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD` | `MONGO_INITDB_ROOT_USERNAME`, `MONGO_INITDB_ROOT_PASSWORD`, `MONGO_INITDB_DATABASE` |
| **Host Data Mount** | `./data_files/postgres15` -> `/var/lib/postgresql/data` | `./data_files/mongodb8` -> `/data/db` |
| **Host Log Mount** | `./logs/postgres15` -> `/var/log/postgresql` | `./logs/mongodb8` -> `/var/log/mongodb` |
| **Default Port** | `5432` | `27017` |
| **Healthcheck Tool** | `pg_isready` | `mongosh --eval "db.runCommand({ ping: 1 })"` |
| **Shared Network** | `local_dev_network` | `local_dev_network` |

---

## 4. Persistence Across `docker compose down` and `up`

### 4.1 Will Data in `data_files/mongodb8/` Survive Shutdown and Restart?

**Yes.** Because `data_files/mongodb8/` is bind-mounted to MongoDB's data directory (`/data/db`), all WiredTiger data and journal files live on the Windows host filesystem outside the container's writable layer. Running `docker compose down` removes only the container and virtual network—your host bind-mounted files remain untouched, and running `docker compose up -d` reattaches the existing dataset cleanly.

### 4.2 Critical Safeguards & Edge Cases to Prevent Data Loss or Startup Failures

1. **Allow Clean `SIGTERM` Shutdowns**:
   * Standard `docker compose down` sends `SIGTERM` to `mongod`, allowing WiredTiger to flush checkpoints and remove `mongod.lock` cleanly.
   * Avoid force-killing Docker Desktop or terminating the container abruptly (`SIGKILL`), which forces WiredTiger journal recovery on the next start.
2. **Enforce `mongodb` User Ownership in `Dockerfile`**:
   * The official MongoDB image runs `mongod` as the non-root `mongodb` user. Pre-creating `/var/log/mongodb` (and ensuring `/data/db` permissions) with `chown -R mongodb:mongodb` in `mongodb/Dockerfile` prevents startup permission errors on mounted directories.
3. **Understand First-Boot Credential Initialization**:
   * `MONGO_INITDB_ROOT_USERNAME` and `MONGO_INITDB_ROOT_PASSWORD` create the root administrator **only when `data_files/mongodb8/` is empty**. Once initialized, modifying `.env` credentials will not overwrite existing database users; user/password updates must be performed via `mongosh`.
4. **Prevent Major-Version Storage Mismatches**:
   * Keeping `data_files/mongodb8/` strictly dedicated to MongoDB 8 prevents WiredTiger feature-compatibility-version (FCV) conflicts or downgrade errors.
5. **Preserve `LF` Line Endings & UTF-8 Without BOM**:
   * Editing `mongod.conf`, `Dockerfile`, `.env`, or `docker-compose.yml` on Windows 11 with `CRLF` (`\r\n`) or UTF-8 BOM headers can cause YAML/config parse errors inside the Linux container.
6. **Keep Logical Backups (`mongodump`)**:
   * Even with persistent bind mounts, take periodic `mongodump` snapshots before destructive schema or migration tests.

---

## 5. Six-Step Implementation Roadmap

### Step 1: Prepare Host Directories and Standardize Log Folders
* Create `C:\projects\docker-env\data_files\mongodb8` and `C:\projects\docker-env\logs\mongodb8`.
* Confirm `logs/` naming matches `data_files/` (`postgres15` and `mongodb8`) for consistency.
* **Verification**: `Get-ChildItem C:\projects\docker-env\data_files, C:\projects\docker-env\logs` lists `mongodb8` in both parent folders.

### Step 2: Add MongoDB 8 Variables to `.env`
* Add MongoDB 8 settings to `C:\projects\docker-env\.env` (image tag, container name, root username/password, initial database, host port `27017`, and internal data/log paths) using `LF` line endings.
* **Verification**: `docker compose config` parses `.env` with zero unresolved variable warnings.

### Step 3: Write `mongodb/mongod.conf` for MongoDB 8
* Configure `storage.dbPath` (`/data/db`), `net.port` (`27017`), `net.bindIp` (`0.0.0.0`), and `systemLog` (`destination: file`, `logAppend: true`, `path: /var/log/mongodb/mongod.log`) with `LF` line endings and UTF-8 without BOM.
* **Verification**: PowerShell line-ending check confirms pure `LF` (`\n`) with no `\r` characters.

### Step 4: Create the Custom `mongodb/Dockerfile`
* Build from the official `mongo` image tag (`ARG MONGO_IMAGE_TAG=8`), create `/var/log/mongodb` with `mongodb:mongodb` ownership, and copy `mongod.conf` to `/etc/mongod.conf`.
* **Verification**: `docker compose build mongodb8` completes cleanly with exit code `0`.

### Step 5: Update `docker-compose.yml` with `mongodb8`
* Add the `mongodb8` service to `C:\projects\docker-env\docker-compose.yml` with `--config /etc/mongod.conf`, bind mounts for `./data_files/mongodb8` and `./logs/mongodb8`, port `27017:27017`, attachment to `local_dev_network`, and a `mongosh` ping healthcheck.
* **Verification**: `docker compose config` renders both `postgres15` and `mongodb8` on `local_dev_network`.

### Step 6: Start, Verify Health, and Test Persistence Across `down` / `up`
* Start the stack with `docker compose up -d --build` and wait until `mongodb8` reports `healthy`.
* Connect via `mongosh`, insert a test document into a collection, execute `docker compose down` followed by `docker compose up -d`, and verify:
  1. The test document remains intact after container recreation.
  2. `mongod.log` is actively writing inside `C:\projects\docker-env\logs\mongodb8`.
  3. Host port `localhost:27017` and inter-container DNS (`db_mongodb8:27017` on `local_dev_network`) accept connections.

---

# Step 1: Prepare Host Directories and Standardize Log Folders

## 1. Objective

Create the host-bound persistent data and log directories for **MongoDB 8** under `C:\projects\docker-env` (`data_files\mongodb8` and `logs\mongodb8`) and confirm uniform, version-isolated directory naming alongside **PostgreSQL 15** (`postgres15`).

## 2. Method & Architectural Notes

* **Data Isolation (`data_files\mongodb8`)**: Isolates MongoDB 8 WiredTiger storage files on the Windows 11 host so container lifecycle events (`docker compose down` / `up`) do not wipe database files and major versions do not collide.
* **Host-Mounted Logging (`logs\mongodb8`)**: Mirrors `logs\postgres15` so `mongod` writes log files directly to the Windows filesystem for easy inspection.
* **Naming Standardization**: During setup review, we noted an earlier sketch listed `logs/postgres/` while the active PostgreSQL 15 stack uses `logs\postgres15`. Keeping `logs\postgres15` and `logs\mongodb8` aligns `logs\` directly with `data_files\`.

## 3. PowerShell Commands & Actual Output

### Commands Executed

```powershell
cd C:\projects\docker-env

# 1. Create MongoDB 8 host data and log directories
New-Item -ItemType Directory -Path "C:\projects\docker-env\data_files\mongodb8" -Force
New-Item -ItemType Directory -Path "C:\projects\docker-env\logs\mongodb8" -Force

# 2. Inspect data_files and logs folders to verify structure and naming consistency
Get-ChildItem -Path "C:\projects\docker-env\data_files"
Get-ChildItem -Path "C:\projects\docker-env\logs"
```

### Actual PowerShell Output

```text
Windows PowerShell 5.1
Copyright (C) Microsoft Corporation. All rights reserved.

PS C:\Users\Anan> cd C:\projects\docker-env
PS C:\projects\docker-env> New-Item -ItemType Directory -Path "C:\projects\docker-env\data_files\mongodb8" -Force


    Directory: C:\projects\docker-env\data_files


Mode                 LastWriteTime         Length Name
----                 -------------         ------ ----
d-----        23/11/2026     12:29                mongodb8


PS C:\projects\docker-env> New-Item -ItemType Directory -Path "C:\projects\docker-env\logs\mongodb8" -Force


    Directory: C:\projects\docker-env\logs


Mode                 LastWriteTime         Length Name
----                 -------------         ------ ----
d-----        23/11/2026     12:29                mongodb8


PS C:\projects\docker-env> Get-ChildItem -Path "C:\projects\docker-env\data_files"


    Directory: C:\projects\docker-env\data_files


Mode                 LastWriteTime         Length Name
----                 -------------         ------ ----
d-----        23/11/2026     12:29                mongodb8
d-----        21/11/2026     12:48                postgres15


PS C:\projects\docker-env> Get-ChildItem -Path "C:\projects\docker-env\logs"


    Directory: C:\projects\docker-env\logs


Mode                 LastWriteTime         Length Name
----                 -------------         ------ ----
d-----        23/11/2026     12:29                mongodb8
d-----        21/11/2026     13:00                postgres15


PS C:\projects\docker-env>
```

## 4. Verification Summary

* `C:\projects\docker-env\data_files\mongodb8` created cleanly.
* `C:\projects\docker-env\logs\mongodb8` created cleanly.
* Both `data_files\` and `logs\` now share identical version-isolated subfolder names (`postgres15` and `mongodb8`).

---

# Step 2: Add MongoDB 8 Variables to `.env`

## 1. Objective

Extend `C:\projects\docker-env\.env` as the single source of truth for the `docker-env` stack by adding all **MongoDB 8** configuration variables (`MONGODB8_*`), while preserving **UTF-8 without BOM** encoding and **LF (`\n`)** line endings for Linux container compatibility on Windows 11.

## 2. Method & Architectural Notes

* **Single Source of Truth (`.env`)**: Centralizes image tags, container names, credentials, host port mappings, and internal container paths for both `postgres15` and `mongodb8` in one file.
* **Version-Isolated Prefixing (`MONGODB8_*`)**: Mirrors `POSTGRES15_*` so each database engine and major version maintains distinct, collision-free variables.
* **Cross-Platform Line Ending Hygiene**: PowerShell's `[System.IO.File]::WriteAllText` with `System.Text.UTF8Encoding($false)` ensures `.env` contains no UTF-8 BOM header and no Windows `CRLF` (`\r\n`) characters that could inject trailing `\r` values into container environment variables.

## 3. PowerShell Commands & Actual Output

### Commands Executed

```powershell
cd C:\projects\docker-env

# 1. Append MongoDB 8 variables to .env and normalize to UTF-8 (no BOM) + LF line endings
$envPath = "C:\projects\docker-env\.env"
$existing = (Get-Content -Path $envPath -Raw) -replace "`r`n", "`n"
$existing = $existing.TrimEnd("`n")

$mongoBlock = @"

# ==========================================
# MongoDB 8 Configuration
# ==========================================
MONGODB8_IMAGE_TAG=8.0
MONGODB8_CONTAINER_NAME=db_mongodb8
MONGODB8_ROOT_USER=dev_root
MONGODB8_ROOT_PASSWORD=dev_secret_pass
MONGODB8_DB=app_local
MONGODB8_HOST_PORT=27017
MONGODB8_DATA_DIR=/data/db
MONGODB8_LOG_DIR=/var/log/mongodb
"@ -replace "`r`n", "`n"

$finalContent = $existing + "`n" + $mongoBlock + "`n"
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($envPath, $finalContent, $utf8NoBom)

# 2. Verify LF line endings and inspect .env
$raw = Get-Content -Path $envPath -Raw
if ($raw -match "`r") {
    Write-Warning "CRLF detected in .env!"
} else {
    Write-Host "OK: .env saved with LF line endings and UTF-8 without BOM."
}
Get-Content -Path $envPath
```

### Actual PowerShell Output

```text
OK: .env saved with LF line endings and UTF-8 without BOM.
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

# ==========================================
# MongoDB 8 Configuration
# ==========================================
MONGODB8_IMAGE_TAG=8.0
MONGODB8_CONTAINER_NAME=db_mongodb8
MONGODB8_ROOT_USER=dev_root
MONGODB8_ROOT_PASSWORD=dev_secret_pass
MONGODB8_DB=app_local
MONGODB8_HOST_PORT=27017
MONGODB8_DATA_DIR=/data/db
MONGODB8_LOG_DIR=/var/log/mongodb
```

## 4. Verification Summary

* `C:\projects\docker-env\.env` updated with all eight `MONGODB8_*` parameters (`MONGODB8_IMAGE_TAG`, `MONGODB8_CONTAINER_NAME`, `MONGODB8_ROOT_USER`, `MONGODB8_ROOT_PASSWORD`, `MONGODB8_DB`, `MONGODB8_HOST_PORT`, `MONGODB8_DATA_DIR`, `MONGODB8_LOG_DIR`).
* Verified pure `LF` line endings (`\r` check passed) and UTF-8 without BOM encoding.
* Ready to proceed to **Step 3: Write `mongodb/mongod.conf` for MongoDB 8**.

---

# Step 3: Write `mongodb/mongod.conf` for MongoDB 8

## 1. Objective

Create the `C:\projects\docker-env\mongodb` service directory and write `mongod.conf` using **UTF-8 without BOM** encoding and **LF (`\n`)** line endings so MongoDB 8 runs with explicit, version-controlled storage, logging, networking, and authorization settings.

## 2. Method & Architectural Notes

* **Service Module Folder (`mongodb\`)**: Mirrors `postgres\` so each database service encapsulates its own `Dockerfile` and configuration file.
* **Storage Path (`storage.dbPath: /data/db`)**: Aligns directly with `MONGODB8_DATA_DIR` in `.env` and the host bind mount `./data_files/mongodb8`.
* **Persistent File Logging (`systemLog`)**: Directs MongoDB daemon logs to `/var/log/mongodb/mongod.log` with `logAppend: true`, ensuring logs persist inside `./logs/mongodb8` on the Windows host without truncating prior entries on container restarts.
* **Network Binding (`net.bindIp: 0.0.0.0`, `port: 27017`)**: Allows connections from the Windows host port mapping (`localhost:27017`) and peer containers over `local_dev_network`.
* **Security (`security.authorization: enabled`)**: Enforces authenticated access once the root user is initialized on first boot.
* **Line Ending Hygiene**: Written via `[System.IO.File]::WriteAllText` with `UTF8Encoding($false)` and normalized `\n` line endings so YAML parsing inside the Linux container never encounters `CRLF` or BOM errors.

## 3. PowerShell Commands & Actual Output

### Commands Executed

```powershell
cd C:\projects\docker-env

# 1. Create the mongodb service directory
New-Item -ItemType Directory -Path "C:\projects\docker-env\mongodb" -Force

# 2. Write mongod.conf with UTF-8 (no BOM) and LF line endings
$confPath = "C:\projects\docker-env\mongodb\mongod.conf"
$mongodConf = @"
# MongoDB 8 Configuration File
# Local Development Environment

storage:
  dbPath: /data/db

systemLog:
  destination: file
  logAppend: true
  path: /var/log/mongodb/mongod.log

net:
  port: 27017
  bindIp: 0.0.0.0

processManagement:
  timeZoneInfo: /usr/share/zoneinfo

security:
  authorization: enabled
"@ -replace "`r`n", "`n"

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($confPath, $mongodConf + "`n", $utf8NoBom)

# 3. Verify LF line endings and inspect mongod.conf
$raw = Get-Content -Path $confPath -Raw
if ([string]::IsNullOrWhiteSpace($raw)) {
    Write-Warning "mongod.conf is empty!"
} elseif ($raw -match "`r") {
    Write-Warning "CRLF detected in mongod.conf!"
} else {
    Write-Host "OK: mongod.conf saved with LF line endings and UTF-8 without BOM."
}
Get-Content -Path $confPath
```

### Actual PowerShell Output

```text
PS C:\projects\docker-env> cd C:\projects\docker-env
PS C:\projects\docker-env>
PS C:\projects\docker-env> # 1. Create the mongodb service directory
PS C:\projects\docker-env> New-Item -ItemType Directory -Path "C:\projects\docker-env\mongodb" -Force


    Directory: C:\projects\docker-env


Mode                 LastWriteTime         Length Name
----                 -------------         ------ ----
d-----        23/11/2026     12:36                mongodb


PS C:\projects\docker-env>
PS C:\projects\docker-env> # 2. Write mongod.conf with UTF-8 (no BOM) and LF line endings
PS C:\projects\docker-env> $confPath = "C:\projects\docker-env\mongodb\mongod.conf"
PS C:\projects\docker-env> $mongodConf = @"
>> # MongoDB 8 Configuration File
>> # Local Development Environment
>>
>> storage:
>>   dbPath: /data/db
>>
>> systemLog:
>>   destination: file
>>   logAppend: true
>>   path: /var/log/mongodb/mongod.log
>>
>> net:
>>   port: 27017
>>   bindIp: 0.0.0.0
>>
>> processManagement:
>>   timeZoneInfo: /usr/share/zoneinfo
>>
>> security:
>>   authorization: enabled
>> "@ -replace "`r`n", "`n"
PS C:\projects\docker-env>
PS C:\projects\docker-env> $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
PS C:\projects\docker-env> [System.IO.File]::WriteAllText($confPath, $mongodConf + "`n", $utf8NoBom)
PS C:\projects\docker-env>
PS C:\projects\docker-env> # 3. Verify LF line endings and inspect mongod.conf
PS C:\projects\docker-env> $raw = Get-Content -Path $confPath -Raw
PS C:\projects\docker-env> if ([string]::IsNullOrWhiteSpace($raw)) {
>>     Write-Warning "mongod.conf is empty!"
>> } elseif ($raw -match "`r") {
>>     Write-Warning "CRLF detected in mongod.conf!"
>> } else {
>>     Write-Host "OK: mongod.conf saved with LF line endings and UTF-8 without BOM."
>> }
OK: mongod.conf saved with LF line endings and UTF-8 without BOM.
PS C:\projects\docker-env> Get-Content -Path $confPath
# MongoDB 8 Configuration File
# Local Development Environment

storage:
  dbPath: /data/db

systemLog:
  destination: file
  logAppend: true
  path: /var/log/mongodb/mongod.log

net:
  port: 27017
  bindIp: 0.0.0.0

processManagement:
  timeZoneInfo: /usr/share/zoneinfo

security:
  authorization: enabled
PS C:\projects\docker-env>
```

## 4. Verification Summary

* Created `C:\projects\docker-env\mongodb`.
* Wrote `C:\projects\docker-env\mongodb\mongod.conf` with UTF-8 without BOM and pure `LF` line endings.
* Ready to proceed to **Step 4: Create the Custom `mongodb/Dockerfile`**.

---

# Step 4: Create the Custom `mongodb/Dockerfile`

## 1. Objective

Create `C:\projects\docker-env\mongodb\Dockerfile` to build a custom **MongoDB 8** image driven by `.env` (`MONGODB8_IMAGE_TAG`), configure directory ownership for the non-root `mongodb` user, bake `mongod.conf` into `/etc/mongod.conf`, and ensure **UTF-8 without BOM** encoding with **LF (`\n`)** line endings.

## 2. Method, Troubleshooting & Architectural Notes

* **Environment-Driven Base Image (`ARG MONGODB8_IMAGE_TAG=8.0`)**: Allows `docker-compose.yml` to pass the version tag from `.env` at build time while providing a safe fallback default (`8.0`).
* **Directory Permissions (`chown -R mongodb:mongodb`)**: Pre-creates `/var/log/mongodb` and `/data/db` owned by `mongodb:mongodb` so the non-root `mongod` daemon can write log and database files without permission errors.
* **Custom Configuration Injection (`COPY --chown=mongodb:mongodb mongod.conf /etc/mongod.conf`)**: Mirrors `postgres/Dockerfile` by baking the version-controlled configuration file directly into the image with `0644` permissions.
* **PowerShell Here-String Interpolation Catch & Fix**:
  * *Issue*: Using a double-quoted PowerShell here-string (`@"..."@`) expanded `${MONGODB8_IMAGE_TAG}` as an empty PowerShell variable, resulting in `FROM mongo:`.
  * *Resolution*: Switched to a single-quoted literal here-string (`@'...'@`) so PowerShell preserves `${MONGODB8_IMAGE_TAG}` verbatim for Docker BuildKit.

## 3. PowerShell Commands & Actual Output

### 3.1 Initial Attempt (Double-Quoted Here-String Catch)

```powershell
# Initial write using @"..."@ expanded ${MONGODB8_IMAGE_TAG} in PowerShell -> "FROM mongo:"
Get-Content -Path "C:\projects\docker-env\mongodb\Dockerfile"
```

**Observed Output:**

```text
ARG MONGODB8_IMAGE_TAG=8.0
FROM mongo:
```

### 3.2 Corrected Execution (Single-Quoted Literal Here-String)

**Commands Executed:**

```powershell
cd C:\projects\docker-env

$dockerfilePath = "C:\projects\docker-env\mongodb\Dockerfile"
$dockerfileContent = @'
ARG MONGODB8_IMAGE_TAG=8.0
FROM mongo:${MONGODB8_IMAGE_TAG}

# Ensure log and data directories exist and are owned by the mongodb user
RUN mkdir -p /var/log/mongodb /data/db \
    && chown -R mongodb:mongodb /var/log/mongodb /data/db

# Copy custom MongoDB configuration into the image
COPY --chown=mongodb:mongodb mongod.conf /etc/mongod.conf
RUN chmod 0644 /etc/mongod.conf

EXPOSE 27017
'@ -replace "`r`n", "`n"

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($dockerfilePath, $dockerfileContent + "`n", $utf8NoBom)

$raw = Get-Content -Path $dockerfilePath -Raw
if ([string]::IsNullOrWhiteSpace($raw)) {
    Write-Warning "Dockerfile is empty!"
} elseif ($raw -match "`r") {
    Write-Warning "CRLF detected in mongodb/Dockerfile!"
} else {
    Write-Host "OK: mongodb/Dockerfile saved with LF line endings and UTF-8 without BOM."
}
Get-Content -Path $dockerfilePath
```

**Actual PowerShell Output:**

```text
OK: mongodb/Dockerfile saved with LF line endings and UTF-8 without BOM.
ARG MONGODB8_IMAGE_TAG=8.0
FROM mongo:${MONGODB8_IMAGE_TAG}

# Ensure log and data directories exist and are owned by the mongodb user
RUN mkdir -p /var/log/mongodb /data/db \
    && chown -R mongodb:mongodb /var/log/mongodb /data/db

# Copy custom MongoDB configuration into the image
COPY --chown=mongodb:mongodb mongod.conf /etc/mongod.conf
RUN chmod 0644 /etc/mongod.conf

EXPOSE 27017
```

## 4. Verification Summary

* `C:\projects\docker-env\mongodb\Dockerfile` verified with `FROM mongo:${MONGODB8_IMAGE_TAG}` intact.
* Confirmed `LF` line endings and UTF-8 without BOM encoding.
* Ready to proceed to **Step 5: Update `docker-compose.yml` with `mongodb8`**.

---

# Step 5: Update `docker-compose.yml` with `mongodb8`

## 1. Objective

Update `C:\projects\docker-env\docker-compose.yml` to add the **MongoDB 8** (`mongodb8`) service alongside **PostgreSQL 15** (`postgres15`), wiring `.env` variables, build arguments, bind mounts, port `27017`, `local_dev_network`, and a healthcheck while preserving **UTF-8 without BOM** encoding and **LF (`\n`)** line endings.

## 2. Method & Architectural Notes

* **Service Symmetry (`postgres15` & `mongodb8`)**: Mirrors the existing `postgres15` service block—using `restart: unless-stopped`, environment variables sourced from `.env`, a custom `Dockerfile` build context, bind mounts under `./data_files/` and `./logs/`, and attachment to `shared_net` (`local_dev_network`).
* **Custom Configuration Startup (`mongod --config /etc/mongod.conf`)**: Explicitly launches `mongod` with `/etc/mongod.conf` baked into the `mongodb8` image.
* **Healthcheck (`mongosh` Ping)**: Uses `mongosh --quiet --eval "db.adminCommand('ping').ok" | grep 1` with a `20s` `start_period` so Docker Compose accurately tracks readiness.
* **Line Ending & Config Validation**: Saved with `UTF8Encoding($false)` and `LF` endings, then verified via `docker compose config` to confirm all environment interpolations and bind mounts resolve cleanly.

## 3. PowerShell Commands & Actual Output

### Commands Executed

```powershell
cd C:\projects\docker-env

# 1. Write updated docker-compose.yml with postgres15 and mongodb8 (UTF-8 no BOM, LF)
$composePath = "C:\projects\docker-env\docker-compose.yml"
$composeContent = @'
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

  mongodb8:
    build:
      context: ./mongodb
      dockerfile: Dockerfile
      args:
        MONGODB8_IMAGE_TAG: ${MONGODB8_IMAGE_TAG:-8.0}
    container_name: ${MONGODB8_CONTAINER_NAME:-db_mongodb8}
    restart: unless-stopped
    environment:
      MONGO_INITDB_ROOT_USERNAME: ${MONGODB8_ROOT_USER}
      MONGO_INITDB_ROOT_PASSWORD: ${MONGODB8_ROOT_PASSWORD}
      MONGO_INITDB_DATABASE: ${MONGODB8_DB:-app_local}
    command:
      - mongod
      - --config
      - /etc/mongod.conf
    ports:
      - "${MONGODB8_HOST_PORT:-27017}:27017"
    volumes:
      - ./data_files/mongodb8:${MONGODB8_DATA_DIR:-/data/db}
      - ./logs/mongodb8:${MONGODB8_LOG_DIR:-/var/log/mongodb}
    networks:
      - shared_net
    healthcheck:
      test: ["CMD-SHELL", "mongosh --quiet --eval \"db.adminCommand('ping').ok\" | grep 1"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 20s

networks:
  shared_net:
    name: ${SHARED_NETWORK_NAME:-local_dev_network}
    driver: bridge
'@ -replace "`r`n", "`n"

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($composePath, $composeContent + "`n", $utf8NoBom)

# 2. Verify LF line endings and validate with docker compose config
$raw = Get-Content -Path $composePath -Raw
if ($raw -match "`r") {
    Write-Warning "CRLF detected in docker-compose.yml!"
} else {
    Write-Host "OK: docker-compose.yml saved with LF line endings and UTF-8 without BOM."
}

docker compose config
```

---

# Step 6: Build, Healthcheck, Persistence, Logging, and Network Verification

## 1. Objective

Build and start the multi-service environment (`postgres15` and `mongodb8`) via Docker Compose, verify `healthy` container readiness, confirm MongoDB 8 data persistence across `docker compose down` and `docker compose up -d`, verify host bind-mounted WiredTiger storage and `mongod.log` output, and confirm shared bridge network DNS/IP connectivity on `local_dev_network`.

## 2. Method & Verification Checks

1. **Image Build & Startup (`docker compose up -d --build`)**: Built both custom images (`docker-env-postgres15` and `docker-env-mongodb8`) using the `.env`-driven image tags (`15-alpine` and `8.0`) and started both containers in detached mode.
2. **Healthcheck Readiness (`db_mongodb8` & `db_postgres15`)**: Monitored `db_mongodb8` through its `20s` startup window until `mongosh` ping checks transitioned the status from `starting` to `healthy`.
3. **Write & Lifecycle Persistence Test (`down` / `up`)**: Inserted a timestamped document into `app_local.persistence_test`, ran `docker compose down` to remove both containers and the `local_dev_network` bridge, restarted the stack with `docker compose up -d`, and queried `app_local.persistence_test` to verify the exact `ObjectId` and document survived intact.
4. **Host Bind-Mount & Network Inspection**: Confirmed WiredTiger data files in `C:\projects\docker-env\data_files\mongodb8`, verified `mongod.log` in `C:\projects\docker-env\logs\mongodb8`, and inspected `local_dev_network` container IP assignments.

## 3. PowerShell Commands & Actual Output

### Commands Executed

```powershell
cd C:\projects\docker-env

# 1. Build and start services
docker compose up -d --build

# 2. Wait for mongodb8 health status
do {
    Start-Sleep -Seconds 3
    $health = docker inspect --format "{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}" db_mongodb8
    Write-Host "db_mongodb8 status: $health"
} while ($health -eq "starting")

docker compose ps

# 3. Insert a persistence test document into app_local
docker exec -i db_mongodb8 mongosh -u dev_root -p dev_secret_pass --authenticationDatabase admin --quiet --eval "
  const dbApp = db.getSiblingDB('app_local');
  dbApp.persistence_test.insertOne({ key: 'step6_check', note: 'survives_down_up', ts: new Date() });
  printjson(dbApp.persistence_test.find({ key: 'step6_check' }).toArray());
"

# 4. Tear down and bring stack back up to test persistence
docker compose down
docker compose up -d

do {
    Start-Sleep -Seconds 3
    $health = docker inspect --format "{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}" db_mongodb8
    Write-Host "db_mongodb8 status after up: $health"
} while ($health -eq "starting")

# 5. Verify persisted document, host data/log files, and local_dev_network
docker exec -i db_mongodb8 mongosh -u dev_root -p dev_secret_pass --authenticationDatabase admin --quiet --eval "
  const dbApp = db.getSiblingDB('app_local');
  printjson(dbApp.persistence_test.find({ key: 'step6_check' }).toArray());
"

Get-ChildItem -Path "C:\projects\docker-env\data_files\mongodb8"
Get-ChildItem -Path "C:\projects\docker-env\logs\mongodb8"
docker network inspect local_dev_network --format "{{range .Containers}}{{.Name}} ({{.IPv4Address}}) {{end}}"
```

### Actual PowerShell Output

```text
[+] Building 48.1s (22/22) FINISHED
 => [postgres15 1/3] FROM docker.io/library/postgres:15-alpine
 => [mongodb8 1/4] FROM docker.io/library/mongo:8.0
 => [mongodb8 2/4] RUN mkdir -p /var/log/mongodb /data/db && chown -R mongodb:mongodb /var/log/mongodb /data/db
 => [mongodb8 3/4] COPY --chown=mongodb:mongodb mongod.conf /etc/mongod.conf
 => [mongodb8 4/4] RUN chmod 0644 /etc/mongod.conf
[+] up 4/4
 ✔ Image docker-env-postgres15 Built                                      48.3s
 ✔ Image docker-env-mongodb8   Built                                      48.3s
 ✔ Container db_postgres15     Started                                    13.6s
 ✔ Container db_mongodb8       Started                                    14.0s

db_mongodb8 status: starting
db_mongodb8 status: starting
db_mongodb8 status: starting
db_mongodb8 status: starting
db_mongodb8 status: starting
db_mongodb8 status: starting
db_mongodb8 status: starting
db_mongodb8 status: healthy

NAME            IMAGE                   COMMAND                  SERVICE      CREATED              STATUS                    PORTS
db_mongodb8     docker-env-mongodb8     "docker-entrypoint.s…"   mongodb8     About a minute ago   Up 56 seconds (healthy)   0.0.0.0:27017->27017/tcp, [::]:27017->27017/tcp
db_postgres15   docker-env-postgres15   "docker-entrypoint.s…"   postgres15   About a minute ago   Up 56 seconds (healthy)   0.0.0.0:5432->5432/tcp, [::]:5432->5432/tcp

[
  {
    _id: ObjectId('6b047082b248ca087ed954c4'),
    key: 'step6_check',
    note: 'survives_down_up',
    ts: ISODate('2026-11-23T16:57:38.480Z')
  }
]

[+] down 3/3
 ✔ Container db_mongodb8     Removed                                       0.6s
 ✔ Container db_postgres15   Removed                                       0.7s
 ✔ Network local_dev_network Removed                                       0.3s
[+] up 3/3
 ✔ Network local_dev_network Created                                       0.1s
 ✔ Container db_postgres15   Started                                       0.6s
 ✔ Container db_mongodb8     Started                                       0.9s

db_mongodb8 status after up: healthy

[
  {
    _id: ObjectId('6b047082b248ca087ed954c4'),
    key: 'step6_check',
    note: 'survives_down_up',
    ts: ISODate('2026-11-23T16:57:38.480Z')
  }
]

    Directory: C:\projects\docker-env\data_files\mongodb8

Mode                 LastWriteTime         Length Name
----                 -------------         ------ ----
d-----        23/11/2026     16:56                .mongodb
d-----        23/11/2026     17:00                diagnostic.data
d-----        23/11/2026     16:58                journal
-a----        23/11/2026     16:58          20480 collection-0-11593571366398111578.wt
-a----        23/11/2026     16:58          20480 collection-0-18212480457045063650.wt
-a----        23/11/2026     16:59          36864 collection-2-11593571366398111578.wt
-a----        23/11/2026     16:56           4096 collection-4-11593571366398111578.wt
-a----        23/11/2026     16:58          20480 collection-7-11593571366398111578.wt
-a----        23/11/2026     16:58          20480 index-1-11593571366398111578.wt
-a----        23/11/2026     16:57          20480 index-1-18212480457045063650.wt
-a----        23/11/2026     16:59          36864 index-3-11593571366398111578.wt
-a----        23/11/2026     16:56           4096 index-5-11593571366398111578.wt
-a----        23/11/2026     16:59           4096 index-6-11593571366398111578.wt
-a----        23/11/2026     16:56          20480 index-8-11593571366398111578.wt
-a----        23/11/2026     16:58          20480 index-9-11593571366398111578.wt
-a----        23/11/2026     16:58              2 mongod.lock
-a----        23/11/2026     16:59          36864 sizeStorer.wt
-a----        23/11/2026     16:56            114 storage.bson
-a----        23/11/2026     16:56             50 WiredTiger
-a----        23/11/2026     16:56             21 WiredTiger.lock
-a----        23/11/2026     16:59           1466 WiredTiger.turtle
-a----        23/11/2026     16:59          86016 WiredTiger.wt
-a----        23/11/2026     16:58           4096 WiredTigerHS.wt
-a----        23/11/2026     16:58          36864 _mdb_catalog.wt

    Directory: C:\projects\docker-env\logs\mongodb8

Mode                 LastWriteTime         Length Name
----                 -------------         ------ ----
-a----        23/11/2026     16:57          90250 mongod.log

db_postgres15 (172.18.0.2/16) db_mongodb8 (172.18.0.3/16)
```

## 4. Verification Summary

* **Build & Health**: Both `db_mongodb8` and `db_postgres15` built cleanly and reached `healthy` status.
* **Persistence Verified**: Document `ObjectId('6b047082b248ca087ed954c4')` in `app_local.persistence_test` persisted across `docker compose down` and `docker compose up -d` via the `./data_files/mongodb8` host bind mount.
* **Logging Verified**: MongoDB wrote `mongod.log` (`90,250` bytes) directly to `C:\projects\docker-env\logs\mongodb8` as configured in `/etc/mongod.conf`.
* **Network Orchestration Verified**: Both `db_postgres15` (`172.18.0.2/16`) and `db_mongodb8` (`172.18.0.3/16`) operate on the shared `local_dev_network` bridge.

---

# MongoDB Log Investigation & Docker Configuration Transcript

---

## User

I have upload the mongod.log

invistigate it and tell me if it is all OK

and explain me why it is increased quickly.

---

## Assistant

### 1. Is Everything OK?

**Yes—from a database health and stability standpoint, there are no errors or warnings in this log.** Every single one of the **695** log entries has informational severity (`"s":"I"`), and WiredTiger checkpoints complete cleanly without errors.

However, there are **two things worth paying attention to** depending on your environment:
* **Repeated short-lived connections (~every 10 seconds):** A client (`mongosh 2.14.3` / Node.js driver `7.5.0`) opens brief bursts of connections to `127.0.0.1:27017` roughly every ~9.4–10.7 seconds and closes them right away. This is common with health checks, polling scripts, or tools/tests that reconnect repeatedly instead of reusing a persistent connection pool.
* **No authentication (`Connection not authenticating`):** Every incoming session logs `id: 10483900` (`"Connection not authenticating"`). That is normal for an unauthenticated local/dev container, but if this instance is reachable outside `127.0.0.1` or holds sensitive data, you should enable MongoDB authorization and restrict network binding.

| Metric / Observation | Finding in `mongod.log` |
| :--- | :--- |
| **Time window covered** | `2026-11-23T17:07:22.182+00:00` to `2026-11-23T17:13:53.361+00:00` (~6.5 minutes) |
| **Total log lines** | 695 (all severity `"I"`; 0 warnings or errors) |
| **Client & runtime** | `mongosh 2.14.3` (`nodejs|mongosh` driver `7.5.0|2.14.3`, Node.js `v24.22.2`, Linux x64 in `docker`) |
| **Connections (`conn219`–`conn379`)** | 161 accepted and 161 ended (~24.7 connections/min on average) |
| **Checkpoints (`WTCHKPT`)** | 7 checkpoint progress entries (snapshots 17–22, no active write churn in log) |

---

### 2. Why Is the Log Increasing Quickly?

The log grows quickly because **every short-lived connection generates multiple verbose JSON lines**, and those bursts repeat regularly:

* **4–5 log lines per connection just to open and close:** Each client connection typically produces:
  1. `22943` (`NETWORK`): `Connection accepted`
  2. `51800` (`NETWORK`): `client metadata` (includes a full nested JSON object with `mongosh`, driver, Node.js, OS, and `docker` runtime metadata)
  3. `10483900` (`ACCESS`): `Connection not authenticating` (repeats the client metadata block)
  4. `6788700` (`NETWORK`): `Received first command on ingress connection...` (logged on some connections, e.g., 44 times across the capture)
  5. `22944` (`NETWORK`): `Connection ended`
* **Frequent burst cycles (~every 10 seconds):** Most cycles open 4 or 5 connections at once, run briefly, and close them within ~15–150 ms, generating ~17–22 lines per burst. Over ~6.5 minutes, that adds up to 695 lines (~1.8 lines/sec), which scales to roughly **6,400+ lines/hour** or **~155,000+ lines/day** if left running continuously.
* **Structured JSON verbosity:** Modern `mongod` logs each event as a single-line JSON object containing full attribute payloads (`uuid`, ports, `connectionCount`, driver/OS/container metadata), which takes more bytes per event than legacy plain-text logs.

---

### 3. Practical Ways to Reduce Log Growth & Connection Churn

* **Reuse connections instead of reconnecting every ~10s:** If an application, script, or health/monitoring check is opening new `mongosh`/driver connections on a timer, keep a single pooled connection open or run checks at a wider interval.
* **Adjust network/access log verbosity if needed:** You can inspect current log component levels with `db.getLogComponents()` in `mongosh` and tune `systemLog.component.network.verbosity` or `systemLog.component.accessControl.verbosity` if connection/handshake logs are too noisy (while keeping warnings and errors enabled).
* **Configure log rotation:** Use `logRotate: "reopen"` (or OS/container log rotation such as `logrotate` or Docker `json-file` / `local` log driver `max-size` and `max-file` limits) so `mongod.log` doesn't grow unbounded on disk.

What is connecting to `127.0.0.1:27017` every ~10 seconds (for example, an app, cron/polling script, or container health check), and is this running in local development or production?

---

## User

this is running in local development

---

## Assistant

For **local development**, there is no security concern with `Connection not authenticating` on `127.0.0.1:27017`—keeping auth off locally is completely normal and avoids friction while iterating.

Here is how to keep your local setup clean and low-noise without changing your app's behavior:

* **Find what's opening a connection every ~10s** (useful if you want to stop the churn):
  * Check `docker ps` / container health checks or `ps aux` for a `mongosh` command, test runner, or dev script polling port `27017`.
  * In a Node.js/driver app, make sure you create one `MongoClient` at startup and reuse it rather than calling `connect()` and `close()` inside a loop or request/poll handler.
* **Silence noisy connection/auth messages locally** (only if the JSON lines bother you while reading logs):
  * In `mongosh`, check current levels with `db.getLogComponents()`, then bump `network` and `accessControl` verbosity to `-1` (errors/warnings only) for the running instance:
    ```js
    db.setLogLevel(-1, "network")
    db.setLogLevel(-1, "accessControl")
    ```
  * To make it persist across restarts in a `mongod.conf` file, set `systemLog.component.network.verbosity: -1` and `systemLog.component.accessControl.verbosity: -1`.
* **Cap log size on disk** (especially handy in Docker):
  * If running in Docker, set `--log-opt max-size=10m --log-opt max-file=3` (or `max-size` / `max-file` in `docker-compose.yml` under `logging.options`) so local logs auto-rotate instead of growing indefinitely.

Would you like to track down which process or container is triggering those ~10-second bursts, or set up config/Docker options to quiet and cap the logs?

---

## User

set up config/Docker options to quiet and cap the logs

---

## Assistant

Here are ready-to-use configurations to quiet noisy connection/auth logs in `mongod` and cap log size in Docker for your local development setup.

### 1. Quiet Connection & Auth Logs (`mongod.conf`)

Create or update your `mongod.conf` file to set `network` and `accessControl` verbosity to `-1` (errors and warnings only) while keeping `systemLog.destination: file` so Docker or `mongod` manages the log output cleanly:

```yaml
systemLog:
  destination: file
  path: /var/log/mongodb/mongod.log
  logAppend: true
  component:
    network:
      verbosity: -1
    accessControl:
      verbosity: -1

net:
  port: 27017
  bindIp: 127.0.0.1
```

* If you want to test immediately without restarting `mongod`, run this in `mongosh`:
  ```js
  db.setLogLevel(-1, "network")
  db.setLogLevel(-1, "accessControl")
  ```

---

### 2. Cap Log Size in Docker

#### Option A: `docker-compose.yml` (recommended for local dev)

Mount the `mongod.conf` file and set `logging.options` (`max-size` and `max-file`) on the MongoDB service so Docker automatically rotates and caps logs:

```yaml
services:
  mongo:
    image: mongo:8.0
    ports:
      - "127.0.0.1:27017:27017"
    volumes:
      - ./mongod.conf:/etc/mongod.conf:ro
      - mongo_data:/data/db
    command: ["mongod", "--config", "/etc/mongod.conf"]
    logging:
      driver: "json-file"
      options:
        max-size: "10m"
        max-file: "3"

volumes:
  mongo_data:
```

#### Option B: `docker run` CLI

```bash
docker run -d \
  --name mongo-dev \
  -p 127.0.0.1:27017:27017 \
  -v "$(pwd)/mongod.conf:/etc/mongod.conf:ro" \
  -v mongo_data:/data/db \
  --log-driver json-file \
  --log-opt max-size=10m \
  --log-opt max-file=3 \
  mongo:8.0 \
  mongod --config /etc/mongod.conf
```

* With `max-size: "10m"` and `max-file: "3"`, Docker keeps at most ~30 MB of container logs for that service and drops the oldest rotated file once full.

Are you running MongoDB via `docker-compose.yml`, `docker run`, or directly on your host machine?

---

## User

docker-compose.yml

---

## Assistant

Here is a complete local-development `docker-compose.yml` setup with `mongod.conf` to quiet noisy connection/auth logs and cap container log size on disk.

### 1. `mongod.conf` (place in the same folder as `docker-compose.yml`)

```yaml
systemLog:
  destination: file
  path: /var/log/mongodb/mongod.log
  logAppend: true
  component:
    network:
      verbosity: -1
    accessControl:
      verbosity: -1

net:
  port: 27017
  bindIp: 127.0.0.1
```

### 2. `docker-compose.yml`

```yaml
services:
  mongo:
    image: mongo:8.0
    container_name: mongo-local-dev
    ports:
      - "127.0.0.1:27017:27017"
    volumes:
      - ./mongod.conf:/etc/mongod.conf:ro
      - mongo_data:/data/db
    command: ["mongod", "--config", "/etc/mongod.conf"]
    logging:
      driver: "json-file"
      options:
        max-size: "10m"
        max-file: "3"

volumes:
  mongo_data:
```

### How to apply it
* Run `docker compose up -d` from that directory.
* Docker caps stdout/stderr JSON logs at 3 files of 10 MB each (~30 MB total), and `mongod` logs only warnings/errors for `network` and `accessControl`.

Would you like to include a separate app service in this `docker-compose.yml` or adjust the log size limits?

---

## User

generate the followings 
1- full docker-compose.yml after changes
2- mongodb/Dockerfile
3- mongodb/mongod.conf

after all changeing

---

## Assistant

### 1. `docker-compose.yml`

```yaml
services:
  mongo:
    build:
      context: ./mongodb
      dockerfile: Dockerfile
    container_name: mongo-local-dev
    ports:
      - "127.0.0.1:27017:27017"
    volumes:
      - mongo_data:/data/db
      - mongo_logs:/var/log/mongodb
    logging:
      driver: "json-file"
      options:
        max-size: "10m"
        max-file: "3"

volumes:
  mongo_data:
  mongo_logs:
```

---

### 2. `mongodb/Dockerfile`

```dockerfile
FROM mongo:8.0

RUN mkdir -p /var/log/mongodb && chown -R mongodb:mongodb /var/log/mongodb

COPY mongod.conf /etc/mongod.conf

CMD ["mongod", "--config", "/etc/mongod.conf"]
```

---

### 3. `mongodb/mongod.conf`

```yaml
systemLog:
  destination: file
  path: /var/log/mongodb/mongod.log
  logAppend: true
  logRotate: reopen
  component:
    network:
      verbosity: -1
    accessControl:
      verbosity: -1

storage:
  dbPath: /data/db

net:
  port: 27017
  bindIp: 0.0.0.0
```

---

### Key Notes for Your Setup
* **Inside-container `bindIp` (`0.0.0.0`):** Because Docker forwards host traffic from `127.0.0.1:27017` into the container's network interface, `mongod` inside the container needs `bindIp: 0.0.0.0` while `docker-compose.yml` keeps it restricted to `127.0.0.1:27017:27017` on your host.
* **Rebuild & start:** Run `docker compose up -d --build` so Docker builds the custom image with `mongodb/mongod.conf` baked in.
* **Log rotation inside the volume:** If you also want `mongod.log` inside `mongo_logs` rotated periodically, run `db.adminCommand({ logRotate: 1 })` or send `SIGUSR1` to `mongod` so it rotates cleanly with `logRotate: reopen`.