# Nginx & Docker SSL Architecture Analysis

Based on your Nginx configurations (`default.conf`, `site1.conf`, and `site2.conf`) and your Docker Compose setup, your SSL architecture relies on two distinct certificate pairs stored in `./ssl_files/` and mounted read-only into `/etc/nginx/certs`:

1. **`localhost.crt` & `localhost.key`**: Used by the default catch-all server block (`default.conf`) to secure `localhost`, `127.0.0.1`, and `dev.test`.
2. **`wildcard.test.crt` & `wildcard.test.key`**: Used by individual site blocks (`site1.conf`, `site2.conf`) to secure `site1.test`, `site2.test`, and any future `*.test` domains.

---

## Analytical Breakdown of the SSL Generation Strategy

To satisfy all requirements—including supporting any new `*.test` site automatically—you do not need to create a new SSL certificate every time you add a site. Utilizing `mkcert` allows you to generate a **wildcard certificate** for `*.test`, which instantly covers `site1.test`, `site2.test`, and every future virtual host ending in `.test`.

### 1. The Local Certificate Authority (CA)
Before generating site-specific certificates, `mkcert` installs a local CA into your operating system and browser trust stores. This prevents browser security warnings (`NET::ERR_CERT_AUTHORITY_INVALID`) in local development.

### 2. Certificate Mapping to Nginx Configs

* **Wildcard Domain Scope (`*.test`):**
  When generating a certificate for `*.test` and `.test`, `mkcert` bundles both the wildcard and the apex domain into a single certificate file (`wildcard.test.crt`). Because `site1.conf` and `site2.conf` reference:
  ```nginx
  ssl_certificate     /etc/nginx/certs/wildcard.test.crt;
  ssl_certificate_key /etc/nginx/certs/wildcard.test.key;
  ```
  any `.test` subdomain configured in Nginx will immediately inherit valid SSL without regenerating files.

* **Localhost & Base Dev Scope (`localhost`, `127.0.0.1`, `dev.test`):**
  Your `default.conf` handles fallback traffic and explicitly listens on `dev.test`, `localhost`, and `127.0.0.1`. Generating a dedicated certificate for these specific loopback and development identifiers (`localhost.crt` / `localhost.key`) ensures your catch-all and default landing page (`v3client`) load securely over HTTPS.

### 3. Docker Volume Integration
Because your `docker-compose.yml` maps your host `./ssl_files` directory directly to `/etc/nginx/certs` inside the container:
```yaml
volumes:
  - ./ssl_files:/etc/nginx/certs:ro
```
Any certificate files generated directly inside your host's `ssl_files/` folder will be immediately available to Nginx without needing to rebuild your Docker container. You only need to reload Nginx (`nginx -s reload`) to apply newly recognized files.

---

## Summary of Required Certificate Sets

| Certificate Pair | Covered Domains / IPs | Mapped Nginx Configuration |
| :--- | :--- | :--- |
| **Certificate Set A (`localhost.*`)** | `localhost`, `127.0.0.1`, `dev.test` | `nginx/sites/default.conf` |
| **Certificate Set B (`wildcard.test.*`)** | `*.test`, `.test` (`site1.test`, `site2.test`, etc.) | `nginx/sites/site1.conf`, `nginx/sites/site2.conf`, future `.test` vhosts |