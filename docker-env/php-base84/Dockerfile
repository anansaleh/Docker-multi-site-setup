# ============================================================
# PHP 8.4 base image — shared by php-fpm84, workspace84, php-worker84
# Base: official PHP 8.4 FPM on Debian Bookworm
# ============================================================
FROM php:8.4-fpm-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Europe/London

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
RUN docker-php-ext-configure gd \
        --with-freetype --with-jpeg \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        bz2 \
        dba \
        exif \
        ftp \
        gd \
        gettext \
        gmp \
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

# --- PECL extensions (no external deps) ---
RUN pecl install \
        apcu \
        igbinary \
        imagick \
        memcached \
        mongodb \
        redis \
        xdebug \
    && docker-php-ext-enable \
        apcu \
        igbinary \
        imagick \
        memcached \
        mongodb \
        redis \
        xdebug

# --- AMQP (needs librabbitmq) ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        librabbitmq-dev \
    && printf "\n" | pecl install amqp \
    && docker-php-ext-enable amqp \
    && rm -rf /var/lib/apt/lists/*

# --- ZMQ (manual compile from GitHub master) ---
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

# Default CMD kept from php:8.4-fpm image (FPM daemon)
# Child images (workspace, worker) override CMD as needed.
