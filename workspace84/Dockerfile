# ============================================================
# Workspace 8.4 — CLI development container
# Inherits from php-base:8.4 (PHP, Composer, extensions)
# Adds: nvm, Node 24/22/20, Yarn, pnpm
# ============================================================
FROM php-base:8.4

# Install Node version manager (nvm) and Node versions
RUN curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash

# Set up nvm environment variables
ENV NVM_DIR=/root/.nvm
ENV NODE_DEFAULT_VERSION=24

# Install Node versions (24, 22, 20) and set default
RUN bash -lc 'source $NVM_DIR/nvm.sh && \
    nvm install 24 && \
    nvm install 22 && \
    nvm install 20 && \
    nvm alias default 24 && \
    nvm use default'

# Install Yarn and pnpm globally (for Node 24)
RUN bash -lc 'source $NVM_DIR/nvm.sh && nvm use default && \
    npm install -g yarn pnpm'

# Create stable symlinks for all Node binaries (node, npm, npx, yarn, pnpm)
# so they work in any `docker compose exec` without sourcing nvm.
# This is version-agnostic — no hardcoded version number.
RUN bash -lc 'source $NVM_DIR/nvm.sh && nvm use default && \
    for bin in node npm npx yarn pnpm; do \
        ln -sf $(which $bin) /usr/local/bin/$bin; \
    done'

# Set nvm to auto-source in login shells (so users can switch versions with `nvm use 22` etc.)
RUN echo 'source $NVM_DIR/nvm.sh' >> /root/.bashrc

WORKDIR /var/www/html

# Keep the container alive
CMD ["tail", "-f", "/dev/null"]
