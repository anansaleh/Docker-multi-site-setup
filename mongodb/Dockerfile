# ============================================================
# MongoDB 8 container
# Version controlled via .env (MONGODB_VERSION)
# Base image: official MongoDB Docker image
# ============================================================
ARG MONGODB_VERSION=8
FROM mongo:${MONGODB_VERSION}

# Copy custom config
COPY mongod.conf /etc/mongo/mongod.conf

# Default entrypoint and command are inherited from the base image.
# The container's command is set in docker-compose.yml to point at our config file.
