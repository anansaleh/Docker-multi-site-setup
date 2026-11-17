# SSL Certificate Generation & Troubleshooting Guide

This document details the complete execution, troubleshooting, and verification steps performed to generate local trusted SSL certificates using `mkcert` and configure Nginx in Docker.

---

## Step 1: Installing `mkcert` on Windows

Because `mkcert`, `choco` (Chocolatey), and `scoop` were not pre-installed on the host machine, `winget` (the native Windows Package Manager) was used to install `mkcert`.

```powershell
winget install FiloSottile.mkcert
```

> **Note:** After installation, close and reopen PowerShell so the updated system `PATH` environment variables are loaded.

---

## Step 2: Initializing the Local Certificate Authority (CA)

Navigate to the project root directory and install the local CA into the Windows and browser trust stores:

```powershell
cd E:\ProjectsDocker01\Projects01\docker-env
mkcert -install
```

*Confirm the Windows security prompt by clicking **Yes** to trust the local root certificate.*

---

## Step 3: Generating the SSL Certificate Files

Ensure the `ssl_files/` directory exists in the project root (which is mapped read-only to `/etc/nginx/certs` inside the Nginx container):

```powershell
mkdir -p ssl_files
```

### 1. Localhost & Default Catch-All Certificate
Generate the certificate pair for `localhost`, `127.0.0.1`, and `dev.test` (mapped in `default.conf`):

```powershell
mkcert -cert-file ssl_files/localhost.crt -key-file ssl_files/localhost.key localhost 127.0.0.1 dev.test
```

### 2. Wildcard Certificate for `.test` Domains
Generate the wildcard certificate pair for all `*.test` virtual hosts (mapped in `site1.conf`, `site2.conf`, and any future `.test` sites):

```powershell
mkcert -cert-file ssl_files/wildcard.test.crt -key-file ssl_files/wildcard.test.key "*.test"
```

> **Important Note on Wildcard Syntax:** Do not pass `".test"` as a separate argument alongside `"*.test"`. `mkcert` rejects bare dot-prefixed suffixes with `ERROR: ".test" is not a valid hostname, IP, URL or email`. Passing `"*.test"` alone covers all subdomains (`site1.test`, `site2.test`, etc.).

---

## Step 4: Configuring the Windows `hosts` File

To ensure local `.test` domains resolve to your Docker host, open `C:\Windows\System32\drivers\etc\hosts` as **Administrator** and add:

```text
127.0.0.1 dev.test site1.test site2.test
```

---

## Step 5: Applying Changes to Nginx

Restart the Nginx container so it loads the newly generated certificate pairs from `./ssl_files`:

```powershell
docker compose restart nginx
```

---

## Step 6: Troubleshooting & Verification

### Resolving `NET::ERR_CERT_COMMON_NAME_INVALID` in Chrome
If Chrome displays a privacy error stating that the certificate is invalid or mismatched after generating the certificates:

1. **Verify Nginx Site Configuration:** Confirm `site1.conf` and `site2.conf` explicitly reference `/etc/nginx/certs/wildcard.test.crt` and `/etc/nginx/certs/wildcard.test.key`.
2. **Clear Browser SSL Cache:**
   * Open **Internet Options** from the Windows Start menu.
   * Navigate to the **Content** tab and click **Clear SSL state**.
   * Restart Chrome or test inside a fresh **Incognito Window**.

### Inspecting Nginx Logs for SSL Errors
To verify that Nginx loaded the certificates cleanly without startup or parsing errors (`[emerg]` or `[ssl]`):

```powershell
# Check the Nginx container error log file
docker compose exec nginx tail -n 50 /var/log/nginx/error.log

# Or follow live container logs during restart
docker compose logs -f nginx
```

Once verified, all sites (`https://localhost`, `https://dev.test`, `https://site1.test`, and `https://site2.test`) load securely over HTTPS with a valid green lock icon.