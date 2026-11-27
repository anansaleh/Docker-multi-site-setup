# ============================================================
# PHP Worker 7.4 — Queue and background job container
# Inherits from php-base:7.4 (PHP, extensions, Composer)
# Runs Supervisor to manage long-running processes.
# ============================================================
FROM php-base:7.4

# Install Supervisor
RUN apt-get update && apt-get install -y --no-install-recommends \
        supervisor \
    && rm -rf /var/lib/apt/lists/*

# Copy Supervisor config
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Supervisor runs in foreground (required for Docker)
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]

WORKDIR /var/www/html
