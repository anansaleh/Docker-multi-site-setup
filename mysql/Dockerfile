# ============================================================
# MySQL 8.0 container
# Version controlled via .env (MYSQL_VERSION)
# Base image: official MySQL Docker image
# ============================================================
ARG MYSQL_VERSION=8.0
FROM mysql:${MYSQL_VERSION}

# Copy custom config
COPY my.cnf /etc/mysql/conf.d/my.cnf

# Default entrypoint and command are inherited from the base image.
