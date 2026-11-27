# ============================================================
# Redis 7 container
# Version controlled via .env (REDIS_VERSION)
# Base image: official Redis Docker image
# ============================================================
ARG REDIS_VERSION=7
FROM redis:${REDIS_VERSION}

# Copy custom config
COPY redis.conf /usr/local/etc/redis/redis.conf

# Default entrypoint and command are inherited from the base image.
