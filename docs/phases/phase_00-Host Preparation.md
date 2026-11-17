# Phase 00 — Host Preparation & Prerequisites

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM, 125 GB free storage)
**Date completed:** 2026-10-11
**Status:** ✅ Complete — all checks passed

---

## Objective

Verify that the macOS host has everything required before any Docker or project files are created:

- Docker Desktop installed and running
- Docker Compose v2+ available
- Optimal file-sharing mode (VirtioFS) enabled
- Adequate resource allocation
- Homebrew installed
- mkcert installed
- `Projects01/` folder skeleton created

No Docker images, containers, or config files are created in this phase. It is purely a host-readiness check.

---

## Final Host Environment

| Item | Value |
|---|---|
| Host OS | macOS |
| Chip | Apple M4 |
| Physical RAM | 16 GB |
| Free storage | 125.02 GB (of 245.11 GB total) |
| Docker version | 29.7.2 (build a7dcaa6) |
| Docker Server version | 29.7.2 |
| Docker Compose version | v5.5.1 |
| Docker Desktop VMM | Apple Virtualization framework |
| Docker Desktop file sharing | VirtioFS |
| Docker Desktop CPUs allocated | 8 |
| Docker Desktop memory allocated | 8 GB |
| Docker Desktop disk image | 228.27 GB |
| Homebrew version | 7.0.6 |
| mkcert version | v1.4.4 |
| Host project root | `~/Projects01/` |

---

## Step-by-Step Record

### Step 0.1 — Confirm Docker is installed and running

**Commands run:**

```bash
docker --version
docker info | grep -E "Server Version|Operating System|CPUs|Total Memory"
docker compose version
```

**Output:**

```
Docker version 29.7.2, build a7dcaa6

Server Version: 29.7.2
Operating System: Docker Desktop
CPUs: 8
Total Memory: 7.748GiB

Docker Compose version v5.5.1
```

**Result:** ✅ Pass

**Notes:**
- Docker Desktop is installed and running.
- 8 CPUs allocated, ~8 GB memory allocated.
- Compose v5.5.1 — newer versioning line, syntax fully compatible with what this project uses.

---

### Step 0.2 — Confirm VirtioFS and Apple Virtualization framework

**Checked in:** Docker Desktop → Settings → General

**Values found:**
- VMM: **Apple Virtualization framework**
- File sharing implementation: **VirtioFS**

**Result:** ✅ Pass

**Why this matters:**
- VirtioFS provides significantly faster and safer bind-mount I/O than the legacy gRPC-FUSE on macOS.
- This directly affects database performance when `data_files/` is bind-mounted in later phases.

---

### Step 0.3 — Confirm resource allocation

**Checked in:** Docker Desktop → Settings → Resources

**Values found:**
- CPU slider: **8 CPUs**
- Memory slider: **8 GB**
- Disk image size: **228.27 GB**

**Result:** ✅ Pass (with note)

**Note:**
- Host has 16 GB physical RAM. Docker taking 8 GB leaves 8 GB for macOS — a reasonable balance.
- The plan calls for ~17 containers, two of which (`php-base84`, `php-base74`) include LibreOffice and a full PHP extension set. 8 GB is sufficient for local development; LibreOffice only consumes memory during image build, not at runtime.
- **Decision:** Keep 8 GB. If a PHP base image build ever hits OOM, temporarily raise to 10–12 GB for the build, then lower back.

---

### Step 0.4 — Confirm Homebrew is installed

**Command run:**

```bash
brew --version
```

**Output:**

```
Homebrew 7.0.6
```

**Result:** ✅ Pass

---

### Step 0.5 — Install mkcert

**Commands run:**

```bash
brew install mkcert
mkcert --version
```

**Output:**

```
v1.4.4
```

**Result:** ✅ Pass

**Notes:**
- `mkcert -install` is **not** run yet — this belongs to Phase 2 (SSL certificate generation).
- Firefox's `nss` package is optional and was not installed; can be added later if Firefox is used.

---

### Step 0.6 — Create the `Projects01/` folder skeleton

**Path decision:**
- Standard plan assumed `~/Projects/`.
- Host already has an existing `~/Projects/` folder.
- **Decision:** Use `~/Projects01/` for this project to keep separation from the existing folder.
- This path is now a **fixed reference** and will appear in:
  - `docker-compose.yml` volume mounts
  - `.env` (`PROJECTS_ROOT`)
  - IDE path mappings for Xdebug (PhpStorm / VS Code)
  - Project `.env` files (DB host references, etc.)
- Renaming `Projects01` later would require touching all of the above.

**Commands run:**

```bash
mkdir -p ~/Projects01/docker-env
mkdir -p ~/Projects01/sites/site1
mkdir -p ~/Projects01/sites/site2
mkdir -p ~/Projects01/default-host/v3client
mkdir -p ~/Projects01/default-host/client_data
mkdir -p ~/Projects01/default-host/server_side
mkdir -p ~/Projects01/backup_data

find ~/Projects01 -type d | sort
```

**Output:**

```
/Users/anansaleh/Projects01
/Users/anansaleh/Projects01/backup_data
/Users/anansaleh/Projects01/default-host
/Users/anansaleh/Projects01/default-host/client_data
/Users/anansaleh/Projects01/default-host/server_side
/Users/anansaleh/Projects01/default-host/v3client
/Users/anansaleh/Projects01/docker-env
/Users/anansaleh/Projects01/sites
/Users/anansaleh/Projects01/sites/site1
/Users/anansaleh/Projects01/sites/site2
```

**Result:** ✅ Pass

**Notes:**
- All folders created successfully.
- `docker-env/` remains empty — its subfolders are created phase by phase, not all at once.
- No files were created in this phase — only directories.

---

## Phase 0 Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | Docker installed and running | ✅ |
| 2 | Docker Compose v2+ available | ✅ (v5.5.1) |
| 3 | Docker Server reachable from CLI | ✅ |
| 4 | VirtioFS enabled | ✅ |
| 5 | Apple Virtualization framework enabled | ✅ |
| 6 | CPU allocation ≥ 4 | ✅ (8) |
| 7 | Memory allocation ≥ 8 GB | ✅ (8 GB, with note) |
| 8 | Disk image ≥ 60 GB | ✅ (228.27 GB) |
| 9 | Homebrew installed | ✅ (7.0.6) |
| 10 | mkcert installed | ✅ (v1.4.4) |
| 11 | `Projects01/` skeleton created | ✅ |
| 12 | No errors encountered | ✅ |

**Phase 0 result: ✅ PASS**

---

## Carry-Forward Notes for Future Phases

1. **Host project root is `~/Projects01/`** — not `~/Projects/`. All paths in subsequent phases use `Projects01`.
2. **Docker memory stays at 8 GB.** Raise temporarily only if a build hits OOM, then revert.
3. **`mkcert -install` runs in Phase 2.** It initializes the local CA so generated certs are trusted by macOS.
4. **`docker-env/` is intentionally empty.** Subfolders are added incrementally as each phase requires them.
5. **No Redis UI** will be added (per design decision).
6. **No code has been written yet.** Phase 0 is host-only.

---

## Files and Folders Created in Phase 0

**Folders (empty, on host):**

```
~/Projects01/
├── docker-env/                     (empty)
├── sites/
│   ├── site1/                      (empty)
│   └── site2/                      (empty)
├── default-host/
│   ├── v3client/                   (empty)
│   ├── client_data/                (empty)
│   └── server_side/                (empty)
└── backup_data/                    (empty)
```

**Files created:** none.

**Docker objects created:** none (no images, no containers, no volumes, no networks).

**System changes:** none (no `/etc/hosts` edits, no keychain modifications, no mkcert CA install yet).

---

## Rollback Procedure (if needed)

Phase 0 made no destructive changes, so rollback is trivial:

```bash
# Remove the folder skeleton (only if you want to discard it)
rm -rf ~/Projects01

# Optional: uninstall mkcert (not recommended — you'll need it in Phase 2)
# brew uninstall mkcert
```

No Docker state to clean up — nothing was created.

---
## Summary of Phase 0:

- ✅ 12/12 checks passed
- ✅ Host root fixed as ~/Projects01/
- ✅ Docker, Compose, VirtioFS, Homebrew, mkcert all verified
- ✅ Folder skeleton created (empty folders only)
- ✅ No Docker objects, no files, no system changes

---

## Next Phase

**Phase 1 — DNS & Hostname Resolution for `*.local`**

Goal: make `site1.local`, `site2.local`, and `dev.local` resolve to `127.0.0.1` on the host, via `/etc/hosts`.

Phase 1 can begin once this document is saved and confirmed.

---

**End of phase_00.md**