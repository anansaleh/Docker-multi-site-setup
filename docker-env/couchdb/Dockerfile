# ============================================================
# CouchDB 3 container
# Version controlled via .env (COUCHDB_VERSION)
# Base image: official CouchDB Docker image
# ============================================================
ARG COUCHDB_VERSION=3
FROM couchdb:${COUCHDB_VERSION}

# Copy custom config
COPY local.ini /opt/couchdb/etc/local.d/zz-custom.ini

# Default entrypoint and command are inherited from the base image.
