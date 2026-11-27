# ============================================================
# MariaDB 11 container
# Version controlled via .env (MARIADB_VERSION)
# Base image: official MariaDB Docker image
# ============================================================
ARG MARIADB_VERSION=11
FROM mariadb:${MARIADB_VERSION}

# Copy custom config
COPY my.cnf /etc/mysql/conf.d/my.cnf

# Default entrypoint and command are inherited from the base image.
