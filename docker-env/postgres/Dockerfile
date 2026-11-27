ARG POSTGRES_IMAGE_TAG=15-alpine
FROM postgres:${POSTGRES_IMAGE_TAG}

# Ensure internal log directory exists and is owned by the postgres user
RUN mkdir -p /var/log/postgresql \
    && chown -R postgres:postgres /var/log/postgresql \
    && chmod 750 /var/log/postgresql

# Copy custom PostgreSQL configuration into the image
COPY --chown=postgres:postgres postgresql.conf /etc/postgresql/postgresql.conf