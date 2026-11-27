# ============================================================
# PHP 7.4 base image — shared by php-fpm74, workspace74, php-worker74
# Base: official PHP 7.4 FPM on Debian Bullseye
#
# NOTE: PHP 7.4 is EOL (Nov 2022). This image is frozen at its
# last official release. Suitable for legacy project development.
# ============================================================
FROM php:7.4-fpm-bullseye

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Europe/London

# Pin to a pre-EOL snapshot of Bullseye
RUN printf "deb [check-valid-until=no] http://snapshot.debian.org/archive/debian/20260825T000000Z bullseye main\ndeb [check-valid-until=no] http://snapshot.debian.org/archive/debian/20260825T000000Z bullseye-updates main\ndeb [check-valid-until=no] http://snapshot.debian.org/archive/debian-security/20260825T000000Z bullseye-security main\n" > /etc/apt/sources.list && \
    printf "Acquire::Check-Valid-Until \"false\";\n" > /etc/apt/apt.conf.d/99no-check-valid-until

# --- System dependencies for extensions + tools ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        # Build tooling
        build-essential \
        pkg-config \
        autoconf \
        libtool \
        # Extension dependencies
        libbz2-dev \
        libcurl4-openssl-dev \
        libgmp-dev \
        libicu-dev \
        libldap2-dev \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        libxslt1-dev \
        libssl-dev \
        libsqlite3-dev \
        libpq-dev \
        libsnmp-dev \
        libkrb5-dev \
        default-libmysqlclient-dev \
        libdb-dev \
        libmemcached-dev \
        libc-client2007e-dev \
        libkrb5-dev \
        # Image processing
        imagemagick \
        libmagickwand-dev \
        # LibreOffice (headless)
        libreoffice-writer \
        libreoffice-calc \
        libreoffice-impress \
        libreoffice-core \
        # Common CLI tools
        git \
        curl \
        unzip \
        zip \
        gnupg \
        ca-certificates \
        procps \
        tzdata \
        # Graph/PDF tools
        ghostscript \
        poppler-utils \
    && rm -rf /var/lib/apt/lists/*

# --- Bundled PHP extensions ---
# Note: no `--with-libdir` flags — let PHP auto-detect (ARM64 lesson).
# Note: no `--with-db` on dba — let PHP auto-detect from libdb-dev.
RUN docker-php-ext-configure gd \
        --with-freetype --with-jpeg \
    && docker-php-ext-configure imap \
        --with-imap --with-imap-ssl --with-kerberos \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        bz2 \
        dba \
        exif \
        ftp \
        gd \
        gettext \
        gmp \
        imap \
        intl \
        ldap \
        mysqli \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        pgsql \
        shmop \
        soap \
        sockets \
        sysvmsg \
        sysvsem \
        sysvshm \
        xsl \
        zip \
    && docker-php-ext-enable opcache

# --- PECL extensions (install separately to isolate failures) ---
RUN pecl install apcu igbinary imagick memcached redis \
    && docker-php-ext-enable apcu igbinary imagick memcached redis

# --- MongoDB (pin version compatible with PHP 7.4) ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        libssl-dev \
        libsasl2-dev \
    && pecl install mongodb-1.19.2 \
    && docker-php-ext-enable mongodb \
    && rm -rf /var/lib/apt/lists/*

# --- Xdebug (pin version compatible with PHP 7.4) ---
RUN pecl install xdebug-3.1.6 \
    && docker-php-ext-enable xdebug

# --- AMQP (needs librabbitmq) ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        librabbitmq-dev \
    && printf "\n" | pecl install amqp \
    && docker-php-ext-enable amqp \
    && rm -rf /var/lib/apt/lists/*

# --- ZMQ (manual compile from GitHub master — PECL version is incompatible) ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzmq3-dev \
        unzip \
    && curl -L https://github.com/mkoppanen/php-zmq/archive/refs/heads/master.zip -o /tmp/zmq.zip \
    && unzip /tmp/zmq.zip -d /tmp/ \
    && cd /tmp/php-zmq-master \
    && phpize \
    && ./configure \
    && make -j$(nproc) \
    && make install \
    && docker-php-ext-enable zmq \
    && rm -rf /tmp/zmq.zip /tmp/php-zmq-master \
    && rm -rf /var/lib/apt/lists/*

# --- Composer (pinned at build time) ---
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# --- Xdebug + PHP config ---
COPY php.ini    /usr/local/etc/php/conf.d/zz-php.ini
COPY xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini

# --- Log directory ---
RUN mkdir -p /var/log/php && chmod 777 /var/log/php

WORKDIR /var/www/html

# Default CMD kept from php:7.4-fpm image (FPM daemon)
# Child images (workspace, worker) override CMD as needed.
