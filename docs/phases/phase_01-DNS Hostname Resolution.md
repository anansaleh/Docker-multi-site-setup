# Phase 01 — DNS & Hostname Resolution for `*.test`

**Project:** Local Multi-Site PHP Development Environment
**Host OS:** macOS (Apple M4, 16 GB RAM)
**Host project root:** `~/Projects01/`
**Date completed:** 2026-10-11
**Status:** ✅ Complete — all checks passed

---

## Objective

Make the local development hostnames resolve to `127.0.0.1` on the macOS host, so that:

- `site1.test` → `127.0.0.1`
- `site2.test` → `127.0.0.1`
- `dev.test`   → `127.0.0.1`

This phase is **host-only**. No Docker is involved.

**Why this comes before web servers:**
- Web servers (Nginx in Phase 3) will be addressed by hostname. Without resolution, nothing can be tested end-to-end.
- Doing this first means that when Nginx starts in Phase 3, hostname access works immediately with no extra setup.

---

## Approach Chosen

**`/etc/hosts`** — the simplest approach for a fixed, small set of hostnames.

**Alternative considered:** `dnsmasq` for wildcard `*.test` resolution. Not used here because:
- The hostname set is fixed and small (3 entries now, ~1 extra per site added).
- No extra software, no launchd config, no daemon to manage.
- If dozens of sites are added in the future, we can migrate to `dnsmasq` later without disruption.

---

## Step-by-Step Record

### Step 1.1 — Inspect `/etc/hosts`

**Command run:**

```bash
cat /etc/hosts
```

**Output (before changes):**

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
```

**Result:** ✅ Pass

**Notes:**
- Clean, default macOS hosts file.
- None of `site1.test`, `site2.test`, `dev.test` present — nothing to clean up.
- Safe to append without modifying any existing entries.

---

### Step 1.2 — Add the hostnames

**Command run:**

```bash
sudo tee -a /etc/hosts > /dev/null <<'EOF'

# Local dev environment — Phase 1
127.0.0.1   site1.test
127.0.0.1   site2.test
127.0.0.1   dev.test
EOF
```

**Why this approach:**
- `tee -a` appends safely without rewriting the file — existing content is untouched.
- The heredoc (`<<'EOF'`) prevents shell expansion, so the lines are written literally.
- Password prompt is expected (sudo).

**Result:** ✅ Pass

---

### Step 1.3 — Verify `/etc/hosts`

**Command run:**

```bash
cat /etc/hosts
```

**Output (after changes):**

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




# Local dev environment — Phase 1
127.0.0.1   site1.test
127.0.0.1   site2.test
127.0.0.1   dev.test
```

**Result:** ✅ Pass

**Notes:**
- Original macOS entries remain untouched.
- New block appended at the bottom, clearly labelled.
- IPv4 (`127.0.0.1`) used deliberately instead of IPv6 (`::1`) — Nginx will bind IPv4 by default in Phase 3, and this avoids a class of "connection refused over IPv6" issues on macOS.

---

### Step 1.4 — Verify DNS resolution

**Commands run:**

```bash
ping -c 1 site1.test
ping -c 1 site2.test
ping -c 1 dev.test
curl -sS -o /dev/null -w "%{http_code}\n" http://site1.test
```

**Output:**

```
PING site1.test (127.0.0.1): 56 data bytes
64 bytes from 127.0.0.1: icmp_seq=0 ttl=64 time=1.020 ms

--- site1.test ping statistics ---
1 packets transmitted, 1 packets received, 0.0% packet loss
round-trip min/avg/max/stddev = 1.020/1.020/1.020/nan ms

PING site2.test (127.0.0.1): 56 data bytes
64 bytes from 127.0.0.1: icmp_seq=0 ttl=64 time=0.965 ms

--- site2.test ping statistics ---
1 packets transmitted, 1 packets received, 0.0% packet loss
round-trip min/avg/max/stddev = 0.965/0.965/0.965/0.000 ms

PING dev.test (127.0.0.1): 56 data bytes
64 bytes from 127.0.0.1: icmp_seq=0 ttl=64 time=0.939 ms

--- dev.test ping statistics ---
1 packets transmitted, 1 packets received, 0.0% packet loss
round-trip min/avg/max/stddev = 0.939/0.939/0.939/nan ms

curl: (7) Failed to connect to site1.test port 80 after 5007 ms: Couldn't connect to server
000
```

**Result:** ✅ Pass

**Interpretation:**
- All three hostnames resolve to `127.0.0.1` — resolution is working.
- Ping succeeds with 0% packet loss (expected — the loopback interface always responds).
- `curl: (7) Failed to connect ... port 80` is the **correct, expected** result:
  - It proves the hostname resolved to `127.0.0.1`.
  - It proves **nothing is listening on port 80 yet** (Nginx arrives in Phase 3).
  - The `000` HTTP code means "no HTTP response received", which is expected when the connection is refused.

---

### Step 1.5 — Flush DNS cache (optional, precautionary)

**Commands (available but not run unless needed):**

```bash
sudo dscacheutil -flushcache
sudo killall -HUP mDNSResponder
```

**Result:** Not executed — tests passed without it.

**Note:** Keep these commands handy in case a future hostname change doesn't take effect. Running them forces macOS to re-read `/etc/hosts`.

---

## Phase 1 Verification Checklist

| # | Check | Status |
|---|---|---|
| 1 | `/etc/hosts` inspected before changes | ✅ |
| 2 | Original entries preserved | ✅ |
| 3 | `site1.test` added | ✅ |
| 4 | `site2.test` added | ✅ |
| 5 | `dev.test` added | ✅ |
| 6 | `ping site1.test` → 127.0.0.1 | ✅ |
| 7 | `ping site2.test` → 127.0.0.1 | ✅ |
| 8 | `ping dev.test` → 127.0.0.1 | ✅ |
| 9 | HTTP connection refused (nothing listening yet) | ✅ |
| 10 | No errors encountered | ✅ |

**Phase 1 result: ✅ PASS**

---

## Carry-Forward Notes for Future Phases

1. **Three hostnames now resolve:**
   - `site1.test` → `127.0.0.1`
   - `site2.test` → `127.0.0.1`
   - `dev.test`   → `127.0.0.1` (this is the **default site** hostname — `v3client`)

2. **Default site also responds to `localhost` and `127.0.0.1`.** In Phase 3, the default vhost will be configured to catch `localhost`, `127.0.0.1`, and `dev.test`.

3. **Adding a new site later requires two edits:**
   - Add a line to `/etc/hosts` (e.g., `127.0.0.1 site3.test`)
   - Add a vhost config in Nginx and/or Apache
   This workflow will be documented in the Phase 24 README.

4. **`.local` is a reserved TLD** — safe to use for local development, no risk of colliding with a real public domain.

5. **DNS cache flush commands** are available if hostname changes don't take effect immediately:
   ```bash
   sudo dscacheutil -flushcache
   sudo killall -HUP mDNSResponder
   ```

6. **IPv4 chosen over IPv6.** The hosts entries use `127.0.0.1`, not `::1`. This matches Nginx's default bind behaviour in Phase 3 and avoids IPv6-mismatch connection failures.

---

## Files and System Changes in Phase 1

**Files modified:**

```
/etc/hosts    ← three hostname entries appended at the end
```

**Files created:** none.

**Folders created:** none.

**Docker objects created:** none.

**Software installed:** none.

---

## Rollback Procedure

If you need to undo Phase 1:

**Option A — Remove just the added block:**
Edit `/etc/hosts` and delete everything from `# Local dev environment — Phase 1` to the end of the file:

```bash
sudo nano /etc/hosts
```

**Option B — Restore from scratch:**
Replace `/etc/hosts` with the original macOS default:

```bash
sudo tee /etc/hosts > /dev/null <<'EOF'
##
# Host Database
#
# localhost is used to configure the loopback interface
# when the system is booting.  Do not change this entry.
##
127.0.0.1	localhost
255.255.255.255	broadcasthost
::1             localhost
EOF
```

Then flush the DNS cache:

```bash
sudo dscacheutil -flushcache
sudo killall -HUP mDNSResponder
```

No Docker state to clean up — nothing was created in this phase.

---
## Summary of Phase 1:

- ✅ 10/10 checks passed
- ✅ /etc/hosts updated cleanly (original entries preserved)
- ✅ site1.local, site2.local, dev.local all resolve to 127.0.0.1
- ✅ HTTP on port 80 correctly refuses (nothing listening yet — expected)
- ✅ No Docker objects, no files created, no software installed


---

## Next Phase

**Phase 2 — SSL Certificates (mkcert)**

Goal: generate locally-trusted HTTPS certificates for the development hostnames.

Certificates to produce:
- `wildcard.local.crt` + `wildcard.local.key` — for `*.test`
- `localhost.crt` + `localhost.key` — for `localhost`, `127.0.0.1`, `::1`

These will be placed in `~/Projects01/docker-env/ssl_files/` and mounted into both Nginx (Phase 3) and Apache (Phase 4).

Phase 2 begins once this document is saved and confirmed.

---

**End of phase_01.md**