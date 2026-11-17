# Local Multi-Site PHP Development Environment — Design Analysis

**Host OS:** macOS
**Status:** Design frozen — no code yet
**Reference model:** Laradock (principles only, no code reused)

---

## 1. Purpose & Scope

A self-contained, locally hosted Docker environment for developing **multiple PHP sites** side by side on macOS, with:

- Multiple PHP versions running simultaneously (7.4 and 8.4)
- Multiple web servers running simultaneously (Nginx primary, Apache secondary)
- Multiple database engines (MySQL, MariaDB, PostgreSQL, MongoDB) + Redis
- Shared library code accessible to all containers via `/home`
- Local HTTPS via mkcert with browser-trusted certs
- Persistent, version-isolated data directories
- Flag-gated admin tooling (phpMyAdmin, pgAdmin, mongo-express, Adminer)
- A CLI workspace per PHP version, so nothing is installed on the host

This is a **bespoke environment**, inspired by Laradock's architecture, but owned end-to-end. No Laradock files, images, or CLI are used.

---

## 2. Host Layout (outside `docker-env/`)

```
Projects/
│
├── docker-env/                     # all Docker infrastructure
│
├── sites/                          # project codebases
│   ├── site1/
│   └── site2/
│
├── default-host/                   # default site + shared libraries
│   ├── v3client/                   # default site root (index.php at root)
│   │   └── index.php
│   ├── client_data/                # shared client data
│   └── server_side/                # shared server-side PHP libraries
│
└── backup_data/                    # DB dump/restore files
```

---

## 3. `docker-env/` Structure

```
docker-env/
│
├── data_files/                     # persistent data (version-isolated per DB)
│   ├── mysql80/
│   ├── mariadb11/
│   ├── postgres15/
│   ├── mongodb8/
│   └── redis/                      # unsuffixed
│
├── ssl_files/                      # mkcert output
│   ├── wildcard.local.crt          # *.local
│   ├── wildcard.local.key
│   ├── localhost.crt               # localhost + 127.0.0.1
│   └── localhost.key
│
├── logs/                           # one subfolder per service
│   ├── nginx/
│   ├── apache2/
│   ├── php-fpm84/
│   ├── php-fpm74/
│   ├── mysql/
│   ├── mariadb/
│   ├── postgres/
│   ├── mongodb/
│   └── redis/
│
├── apache2/                        # SECONDARY web server (8080 / 8443)
│   ├── Dockerfile
│   ├── vhost.conf
│   └── sites/
│       ├── default.conf
│       ├── site1.conf
│       └── site2.conf
│
├── nginx/                          # PRIMARY web server (80 / 443)
│   ├── Dockerfile
│   ├── nginx.conf
│   └── sites/
│       ├── default.conf            # catch-all: localhost, 127.0.0.1, dev.local
│       ├── site1.conf              # site1.local → /var/www/html/site1
│       └── site2.conf              # site2.local → /var/www/html/site2
│
├── php-base84/                     # shared base image — PHP 8.4 (Bookworm)
│   ├── Dockerfile
│   ├── php.ini
│   └── xdebug.ini
│
├── php-base74/                     # shared base image — PHP 7.4 (Bullseye)
│   ├── Dockerfile
│   ├── php.ini
│   └── xdebug.ini
│
├── php-fpm84/                      # FROM php-base84
│   └── Dockerfile
│
├── php-fpm74/                      # FROM php-base74
│   └── Dockerfile
│
├── workspace84/                    # FROM php-base84 + dev tooling
│   ├── Dockerfile
│   └── (nvm, composer, git, node)
│
├── workspace74/                    # FROM php-base74 + dev tooling
│   ├── Dockerfile
│   └── (nvm, composer, git, node)
│
├── php-worker84/                   # FROM php-base84 + supervisor
│   ├── Dockerfile
│   └── supervisord.conf
│
├── php-worker74/                   # FROM php-base74 + supervisor
│   ├── Dockerfile
│   └── supervisord.conf
│
├── mysql/
│   ├── Dockerfile
│   └── my.cnf
│
├── mariadb/
│   ├── Dockerfile
│   └── my.cnf
│
├── postgres/
│   ├── Dockerfile
│   └── (config)
│
├── mongodb/
│   ├── Dockerfile
│   └── mongod.conf
│
├── redis/
│   ├── Dockerfile
│   └── redis.conf
│
├── phpmyadmin/                     # flag-gated
│   ├── Dockerfile
│   └── pma.conf
│
├── pgadmin/                        # flag-gated
│   ├── Dockerfile
│   └── (config)
│
├── mongo-express/                  # flag-gated
│   ├── Dockerfile
│   └── mongo_express.conf
│
├── adminer/                        # flag-gated
│   ├── Dockerfile
│   └── (config)
│
├── .env                            # single source of truth
├── .env.example                    # tracked template
├── docker-compose.yml              # orchestrates all services
├── docker-compose.override.yml     # local-only overrides
└── README.md
```

---

## 4. Complete Mount Map

### Site code → `/var/www/html/`

| Host path | Container path | Containers |
|---|---|---|
| `../default-host/v3client` | `/var/www/html/v3client` | nginx, apache2, php-fpm84, php-fpm74, workspace84, workspace74, php-worker84, php-worker74 |
| `../sites/site1` | `/var/www/html/site1` | same as above |
| `../sites/site2` | `/var/www/html/site2` | same as above |

### Shared libraries → `/home/`

| Host path | Container path | Containers |
|---|---|---|
| `../default-host/client_data` | `/home/client_data` | nginx, apache2, php-fpm84, php-fpm74, workspace84, workspace74, php-worker84, php-worker74 |
| `../default-host/server_side` | `/home/server_side` | same as above |

> `/home/` and `/var/www/html/` are **separate mount trees** with no overlap. `include("/home/server_side/file.php")` works from any container, independent of the site's location under `/var/www/html/`.

### Data persistence

| Host path | Container | Mode |
|---|---|---|
| `data_files/mysql80` | mysql data dir | rw |
| `data_files/mariadb11` | mariadb data dir | rw |
| `data_files/postgres15` | postgres data dir | rw |
| `data_files/mongodb8` | mongodb data dir | rw |
| `data_files/redis` | redis data dir | rw |

### SSL & config

| Host path | Container path | Containers | Mode |
|---|---|---|---|
| `ssl_files/` | cert path | nginx, apache2 | ro |
| `nginx/nginx.conf` | nginx global config | nginx | ro |
| `nginx/sites/*.conf` | nginx vhosts | nginx | ro |
| `apache2/vhost.conf` | apache global config | apache2 | ro |
| `apache2/sites/*.conf` | apache vhosts | apache2 | ro |

### Logs

| Host path | Container path | Containers | Mode |
|---|---|---|---|
| `logs/<service>/` | service log dir | each service | rw |

### Backups

| Host path | Container path | Containers | Mode |
|---|---|---|---|
| `../backup_data` | `/backup` | mysql, mariadb, postgres, mongodb, workspace84, workspace74 | rw (777) |

---

## 5. Container Inventory

| Service | Folder | Ports (host) | Role | Flag-gated |
|---|---|---|---|---|
| nginx | `nginx/` | 80, 443 | Primary web server | No |
| apache2 | `apache2/` | 8080, 8443 | Secondary web server | No |
| php-fpm84 | `php-fpm84/` | — | PHP 8.4 runtime | No |
| php-fpm74 | `php-fpm74/` | — | PHP 7.4 runtime | No |
| workspace84 | `workspace84/` | — | PHP 8.4 CLI dev shell | No |
| workspace74 | `workspace74/` | — | PHP 7.4 CLI dev shell | No |
| php-worker84 | `php-worker84/` | — | PHP 8.4 queue/background | No |
| php-worker74 | `php-worker74/` | — | PHP 7.4 queue/background | No |
| mysql | `mysql/` | 3306 | MySQL 8.0 | No |
| mariadb | `mariadb/` | 3307 | MariaDB 11 | No |
| postgres | `postgres/` | 5432 | PostgreSQL 15 | No |
| mongodb | `mongodb/` | 27017 | MongoDB 8 | No |
| redis | `redis/` | 6379 | Redis cache | No |
| phpmyadmin | `phpmyadmin/` | 8081 | MySQL/MariaDB UI | `ENABLE_PHPMYADMIN` |
| pgadmin | `pgadmin/` | 8082 | PostgreSQL UI | `ENABLE_PGADMIN` |
| mongo-express | `mongo-express/` | 8083 | MongoDB UI | `ENABLE_MONGO_EXPRESS` |
| adminer | `adminer/` | 8084 | Multi-DB UI | `ENABLE_ADMINER` |

> Ports are illustrative defaults; final numbers live in `.env`.

---

## 6. Version Strategy

| Component | Version(s) | How selected | Data dir |
|---|---|---|---|
| PHP | 8.4 (Bookworm), 7.4 (Bullseye) | Per-container folder | — |
| MySQL | 8.0 | `.env` (`MYSQL_VERSION=8.0`) | `data_files/mysql80/` |
| MariaDB | 11 | `.env` (`MARIADB_VERSION=11`) | `data_files/mariadb11/` |
| PostgreSQL | 15 | `.env` (`POSTGRES_VERSION=15`) | `data_files/postgres15/` |
| MongoDB | 8 | `.env` (`MONGODB_VERSION=8`) | `data_files/mongodb8/` |
| Redis | latest stable | `.env` | `data_files/redis/` |
| Node | 24 (default), 22, 20 | nvm inside workspace | — |
| Composer | Pinned at build time | In Dockerfile | — |

**DB version convention:** One `.env` variable drives both the image tag and the data dir path. Example: `MYSQL_VERSION=8.0` → image `mysql:8.0` + mount `data_files/mysql80/`. This guarantees image and data dir always match.

---

## 7. `.env` Variable Catalogue (names only, no values)

**General**
- `COMPOSE_PROJECT_NAME`
- `PROJECTS_ROOT` (host path to `Projects/`)
- `UID`, `GID`
- `TZ`

**Web servers**
- `NGINX_HTTP_PORT`, `NGINX_HTTPS_PORT`
- `APACHE_HTTP_PORT`, `APACHE_HTTPS_PORT`
- `WEB_SERVER_PRIMARY` (informational)

**PHP**
- `PHP84_BASE_TAG`, `PHP74_BASE_TAG`

**Databases**
- `MYSQL_VERSION`, `MYSQL_PORT`, `MYSQL_ROOT_PASSWORD`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`
- `MARIADB_VERSION`, `MARIADB_PORT`, `MARIADB_ROOT_PASSWORD`, `MARIADB_DATABASE`, `MARIADB_USER`, `MARIADB_PASSWORD`
- `POSTGRES_VERSION`, `POSTGRES_PORT`, `POSTGRES_PASSWORD`, `POSTGRES_DB`, `POSTGRES_USER`
- `MONGODB_VERSION`, `MONGODB_PORT`, `MONGODB_ROOT_USER`, `MONGODB_ROOT_PASSWORD`
- `REDIS_VERSION`, `REDIS_PORT`, `REDIS_PASSWORD`

**Admin tools (flags)**
- `ENABLE_PHPMYADMIN`, `PHPMYADMIN_PORT`
- `ENABLE_PGADMIN`, `PGADMIN_PORT`
- `ENABLE_MONGO_EXPRESS`, `MONGO_EXPRESS_PORT`
- `ENABLE_ADMINER`, `ADMINER_PORT`

**SSL**
- `SSL_CERT_DIR`
- `SSL_WILDCARD_CERT`, `SSL_WILDCARD_KEY`
- `SSL_LOCALHOST_CERT`, `SSL_LOCALHOST_KEY`

**Xdebug**
- `XDEBUG_MODE`, `XDEBUG_START_WITH_REQUEST`, `XDEBUG_CLIENT_HOST`, `XDEBUG_CLIENT_PORT`

**Node / Composer**
- `NODE_DEFAULT_VERSION`
- `NODE_AVAILABLE_VERSIONS`
- `COMPOSER_VERSION`

---

## 8. Network & Port Map

- All services join a **shared Docker network** (single network is sufficient for this setup).
- **Hostname resolution:** mkcert + `/etc/hosts` entries (or a lightweight host-resolver) for `*.local`.
- **Port map:**
  - Nginx: 80, 443
  - Apache: 8080, 8443
  - MySQL: 3306
  - MariaDB: 3307
  - Postgres: 5432
  - MongoDB: 27017
  - Redis: 6379
  - phpMyAdmin: 8081
  - pgAdmin: 8082
  - mongo-express: 8083
  - Adminer: 8084

---

## 9. SSL Strategy (mkcert)

- **Wildcard cert** for `*.local` — covers `site1.local`, `site2.local`, `dev.local`.
- **Localhost cert** for `localhost` + `127.0.0.1`.
- All sites + default vhost use the same key files (mounted read-only into both web servers).
- Both Nginx (443) and Apache (8443) use the same certs.

---

## 10. Xdebug Configuration (summary)

- **Enabled in:** php-fpm84, php-fpm74, workspace84, workspace74 (not in php-worker).
- **Mode:** `debug`
- **Trigger:** `xdebug.start_with_request=trigger`
- **Client host:** `host.docker.internal` (native on macOS)
- **Client port:** `9003`
- **IDE path mapping:** host `Projects/sites/site1` ↔ container `/var/www/html/site1` (and equivalents)

---

## 11. Logging Strategy

- `logs/` folder with **one subfolder per service**.
- Web servers, PHP containers, and DB containers bind-mount their log directories into the corresponding subfolder.
- Logs are directly readable on the host — no `docker logs` required for inspection.

---

## 12. Backup Strategy

- `backup_data/` is a sibling of `docker-env/`.
- Mounted at `/backup` in:
  - All DB containers (mysql, mariadb, postgres, mongodb)
  - Both workspaces (workspace84, workspace74)
- Permissions: **777** (open, dev-only).
- **No per-engine subfolders** — flat directory.
- Dumps/restores executed via CLI from inside the DB containers or workspace.
- **Version caveat:** restoring into a version-suffixed data dir requires restoring into the running container for that version.

---

## 13. PHP Extensions (per base image)

Included in **both** `php-base84` and `php-base74`, subject to per-version availability:

- **From your list:** bcmath, bz2, curl, dba, dom, exif, fileinfo, ftp, gd, gettext, gmp, iconv, igbinary, imagick, imap, intl, ldap, mbstring, mongodb, mysqli, opcache, pcntl, pdo, pdo_mysql, pdo_pgsql, pgsql, phar, posix, readline, redis, shmop, simplexml, soap, sockets, sodium, sqlite3, sysvmsg, sysvsem, sysvshm, tokenizer, xml, xmlreader, xmlwriter, xsl, zip, zlib
- **Added:** apcu, amqp, memcached, zmq
- **Plus trivially addable extras** at build time
- **Known per-version caveats:**
  - `xmlrpc` — removed from core in PHP 8.0; may be skipped on 8.4.
  - `imap` — deprecated in 8.4; may need PECL version.

Base images also include:
- **LibreOffice** (headless / CLI)
- **ImageMagick** (with `imagick` PHP extension)

---

## 14. Full Decision Log

| # | Decision |
|---|---|
| 1 | Host OS: macOS |
| 2 | Infrastructure is bespoke; Laradock used only as a conceptual reference |
| 3 | DB data dirs are version-suffixed; Redis unsuffixed |
| 4 | Initial DB versions: MySQL 8.0, MariaDB 11, PostgreSQL 15, MongoDB 8 |
| 5 | One `.env` variable drives both image tag and data dir path |
| 6 | PHP versions: 8.4 (Bookworm) and 7.4 (Bullseye) |
| 7 | One shared base image per PHP version (`php-base84`, `php-base74`) |
| 8 | LibreOffice + ImageMagick in both base images |
| 9 | PHP extensions: full list + apcu, amqp, memcached, zmq, plus trivially addable extras |
| 10 | Xdebug: mode=debug, start_with_request=trigger, client_host=host.docker.internal, port=9003 |
| 11 | Nginx primary (80/443); Apache secondary (8080/8443); both run concurrently |
| 12 | SSL via mkcert: `*.local` wildcard + `localhost`/`127.0.0.1` certs |
| 13 | All sites under `/var/www/html/` |
| 14 | Default site at `/var/www/html/v3client` (project root contains `index.php`) |
| 15 | Site hostnames: `site1.local`, `site2.local`, `dev.local` |
| 16 | Shared libs: `client_data` → `/home/client_data`, `server_side` → `/home/server_side` |
| 17 | `/home/` and `/var/www/html/` are separate mount trees |
| 18 | `default-host/` mounts are read-write in web servers and PHP containers |
| 19 | `backup_data/` sibling of `docker-env/`; mounted at `/backup` (777); in DB containers + both workspaces |
| 20 | DB admin tools flag-gated: phpMyAdmin, pgAdmin, mongo-express, Adminer |
| 21 | No Redis UI |
| 22 | Single root `docker-compose.yml` + `docker-compose.override.yml` |
| 23 | `logs/` folder with one subfolder per service |
| 24 | Node LTS versions 24 (default), 22, 20 — via nvm in workspace |
| 25 | Composer pinned at build time |
| 26 | Adminer added alongside phpMyAdmin (not replacing it) |

---

## 15. Open Items / Future Work

- Final port numbers (illustrative defaults above).
- Host-resolver mechanism for `*.local` (hosts file vs. dnsmasq vs. other).
- Exact Node package manager setup inside workspace (npm, yarn, pnpm — per workspace needs).
- Supervisor programs for `php-worker84` / `php-worker74` (queue type per project).
- Base OS package set beyond LibreOffice/ImageMagick (e.g., ffmpeg, poppler-utils) — to be refined per real project needs.
- README content (usage docs) to be written during implementation phase.
- `.env.example` values and comments to be finalized during implementation phase.

---

## Summary of what's locked:
- 17 containers (8 PHP/web runtime, 2 web servers, 5 databases/cache, 4 admin tools)
- 2 PHP versions (7.4 Bullseye, 8.4 Bookworm) with shared base images
- 2 web servers running concurrently on different ports
- 5 persistent data dirs, version-isolated
- Xdebug trigger-based, mkcert SSL, service-per-folder logging
- Node 24/22/20 via nvm, Composer pinned
- Flag-gated admin tooling

Next phase (when you're ready): writing actual files `.env`, `docker-compose.yml`, `Dockerfiles`, `nginx/apache` `vhosts`, `php.ini/xdebug.ini`, `supervisord.conf`, etc. I won't start that until you say so.

---

**End of design analysis.**