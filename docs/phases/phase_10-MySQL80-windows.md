# Phase 10 — MySQL 8.0 (First Database) on Windows 11

> **Environment:** Windows 11 + Docker Desktop with WSL 2 backend + PowerShell (no direct WSL shell required)
> **Goal:** Build, start, troubleshoot, and initialize the MySQL 8.0 container, then create the first database and user for the multi-site Docker environment.

---

## 1. Prerequisites

- Windows 11 with Docker Desktop installed and **WSL 2 backend enabled**.
- PowerShell (run as your normal user; no need to open WSL terminal).
- Project root: `E:\ProjectsDocker01\Projects01\docker-env`
- `.env` values used in this phase:

```ini
PROJECTS_ROOT=../
MYSQL_VERSION=8.0
MYSQL_PORT=3306
MYSQL_ROOT_PASSWORD=root
TZ=Europe/London
```
---

## 2. Relevant docker-compose.yml snippet

```yaml
mysql:
  build:
    context: ./mysql
    args:
      MYSQL_VERSION: ${MYSQL_VERSION}
  image: dev-env-mysql
  container_name: dev-mysql
  restart: unless-stopped
  ports:
    - "${MYSQL_PORT}:3306"
  volumes:
    - ${PROJECTS_ROOT}/docker-env/data_files/mysql80:/var/lib/mysql
    - ${PROJECTS_ROOT}/docker-env/logs/mysql:/var/log/mysql
  environment:
    - MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
    - TZ=${TZ}
  command: --default-authentication-plugin=mysql_native_password
  healthcheck:
    test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-uroot", "-p${MYSQL_ROOT_PASSWORD}"]
    interval: 10s
    timeout: 5s
    retries: 5
    start_period: 30s
  networks:
    - dev-net

```

---

## 3. Step 10.7 — Build and start MySQL

### 3.1 Build the image

```bash
    docker compose build mysql
```

Successful output ends with:
```bash
    => => naming to docker.io/library/dev-env-mysql:latest
    ✔ Image dev-env-mysql Built
```

### 3.2 Start the container

```bash
    docker compose up -d mysql
```
### 3.3 Check status

```bash
    docker ps --filter name=dev-mysql
```

---

## 4. Problem encountered: container restart loop
### 4.1 Symptom
docker ps showed:
```bash
    STATUS
    Restarting (1) 11 seconds ago
```

**Logs** (*docker logs dev-mysql*) repeatedly showed:

```bash
    mysqld: Cannot change permissions of the file 'private_key.pem.temp' (OS errno 1 - Operation not permitted)
    [ERROR] [MY-010295] [Server] Could not set file permission for private_key.pem
    [ERROR] [MY-010119] [Server] Aborting
    mysqld: Table 'mysql.plugin' doesn't exist
    [ERROR] [MY-010735] [Server] Could not open the mysql.plugin table.
```

### 4.2 Root cause
- The MySQL data directory was bind-mounted to a Windows NTFS path (`E:\...\data_files\mysql80`).

- Inside the container, MySQL runs as user mysql (UID 999). NTFS does not support Linux chmod/chown permission metadata.

- MySQL tries to create the SSL private key (private_key.pem) and set its permissions to 600. That operation is rejected (OS errno 1), MySQL aborts startup.

- The `mysql.plugin` error is a secondary error: initialization was interrupted, so system tables were never created.

### 4.3 Health-check false signals
While troubleshooting, docker inspect dev-mysql --format='{{json .State.Health}}' showed:

```bash
    "ExitCode":1,
    "Output":"mysqladmin: connect to server at 'localhost' failed
    error: 'Lost connection to MySQL server at 'reading initial communication packet', system error: 104"
```
This is because mysqladmin ping -h localhost connects through the Unix socket, which exists briefly during the initialization phase and then disappears when MySQL aborts. It was a symptom, not the cause.

---

## 5. Fix applied (what actually worked)

### 5.1 Clean slate
```bash
    docker compose down
```
Then manually deleted:

- The old MySQL image (dev-env-mysql)
- The entire data directory: `E:\ProjectsDocker01\Projects01\docker-env\data_files\mysql80`


### 5.2 Rebuild and start
```bash
    docker compose up -d mysql
```

Docker rebuilt the image and re-initialized the data directory from empty.

### 5.3 Result

```bash
    docker ps --filter name=dev-mysql
```
```bash
    STATUS
    Up 34 seconds (healthy)
```

Logs now show:

```bash
    [System] [MY-013602] [Server] Channel mysql_main configured to support TLS.
    [System] [MY-010931] [Server] /usr/sbin/mysqld: ready for connections.
    Version: '8.0.46'  socket: '/var/run/mysqld/mysqld.sock'  port: 3306
```
No more permission errors, no more `mysql.plugin` errors.

### 5.4 Why this worked
Starting from a fresh empty data directory avoided the NTFS permission conflict during first-time initialization. The previous failed attempts had left partial files (e.g., auto.cnf, temp SSL keys) that kept re-triggering the same crash.

> Note: Bind-mounting to a Windows path under WSL 2 remains fragile. If the crash returns after a restart, switch the MySQL data volume to a Docker named volume (see section 9).

---

### 6. Optional health-check hardening
The original health check used localhost (Unix socket). A more reliable version forces TCP:
```yaml
    healthcheck:
    test: ["CMD-SHELL", "mysql --host=127.0.0.1 --port=3306 --user=root --password=$${MYSQL_ROOT_PASSWORD} -e 'SELECT 1' >/dev/null 2>&1"]
    interval: 10s
    timeout: 5s
    retries: 10
    start_period: 60s

```
**Key points:**

- `--host=127.0.0.1` forces TCP instead of the Unix socket.
- `-e 'SELECT 1'` actually executes a query (stronger than mysqladmin ping).
- `$${MYSQL_ROOT_PASSWORD}` — $$ escapes the variable so the container shell resolves it, not the host.
- `start_period`: 60s gives MySQL enough time for first-time initialization on WSL 2.

---

## 7. Verify MySQL from PowerShell

### 7.1 Root login test
```bash
    docker exec -it dev-mysql mysql --host=127.0.0.1 --port=3306 --user=root --password=root -e "SELECT 1"
```

Expected:
```bash
    +---+
    | 1 |
    +---+
    | 1 |
    +---+
```
---

## 8. Phase 10 — Create the first database and user
At the `mysql>` prompt:
```sql
    CREATE DATABASE your_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER 'your_user'@'%' IDENTIFIED BY 'your_password';
    GRANT ALL PRIVILEGES ON your_db.* TO 'your_user'@'%';
    FLUSH PRIVILEGES;
    SHOW DATABASES;
```

```bash
    +--------------------+
    | Database           |
    +--------------------+
    | information_schema |
    | mysql              |
    | performance_schema |
    | sys                |
    | your_db            |
    +--------------------+
```

```sql
   exit;
```

### 8.1 Verify the new user

```bash
    docker exec -it dev-mysql mysql --host=127.0.0.1 --port=3306 --user=your_user --password=your_password your_db -e "SELECT DATABASE();"
```

Expected:
```bash
    +------------+
    | DATABASE() |
    +------------+
    | your_db    |
    +------------+
```

---

## 9. Optional: switch to a Docker named volume (recommended long-term)
If permission issues reappear, replace the bind mount with a named volume in docker-compose.yml:
```yaml
    services:
    mysql:
        volumes:
        - mysql_data:/var/lib/mysql
        - ${PROJECTS_ROOT}/docker-env/logs/mysql:/var/log/mysql

    volumes:
    mysql_data:
```

Then:

```bash
    docker compose down
    docker compose up -d mysql
```

Named volumes live on WSL 2's ext4 filesystem, so Linux permission operations work natively.

---

## 10. Verify PHP → MySQL connectivity
Bring up the full stack:

```bash
    docker compose up -d
    docker ps
```

Expected: `dev-nginx`, `dev-apache2`, `dev-php-fpm74`, `dev-php-fpm84`, `dev-mysql` all `Up` (MySQL healthy).

Test PDO from the PHP 8.4 container:

Test PDO from the PHP 8.4 container:

```bash
    docker exec -it dev-php-fpm84 php -r "new PDO('mysql:host=mysql;dbname=your_db', 'your_user', 'your_password'); echo 'OK';"
```

Expected output:

```bash
    OK
```

This confirms:
- Docker DNS resolves mysql to the MySQL container on `dev-net`.
- `your_user` has correct privileges.
- The PHP `→` MySQL path is fully functional.

---

## 11. Disable the Docker Debug CLI hint (optional)
After docker exec, Docker Desktop prints:

```bash
    What's next:
        Try Docker Debug for seamless, persistent debugging tools in any container or image → docker debug <name>
```

To suppress it, set the environment variable in PowerShell:
```bash
    $env:DOCKER_CLI_HINTS="false"
```

To persist for the current Windows user:

```bash
    [System.Environment]::SetEnvironmentVariable('DOCKER_CLI_HINTS','false','User')
```
Restart PowerShell afterwards. (Docker Desktop → Settings → General → "Show CLI hints" may also work, but the environment variable is more reliable.)

## 12. Phase 10 completion checklist

- ✅ MySQL image built (`dev-env-mysql`)	
- ✅ Container `dev-mysql` running	
- ✅ Health check (healthy)	
- ✅ TLS configured (`mysql_main`)	
- ✅ Database `your_db` created
- ✅ User `your_user` created with % host
- ✅ Privileges granted and flushed
- ✅ Root login via TCP verified
- ✅ New user login verified
- ✅ PHP 8.4 PDO connection returns `OK`

---

