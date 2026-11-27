# ============================================================
# php-fpm 8.4 container
# Thin layer over the shared base image.
# ============================================================
FROM php-base:8.4

# Copy the php-fpm specific config (if we ever need to override)
# Currently using the default from the base image.

WORKDIR /var/www/html

# Default CMD from php:8.4-fpm image is `php-fpm` — inherit it.
