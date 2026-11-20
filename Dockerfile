FROM dunglas/frankenphp:1.13.5-php8.5.12-alpine AS base

# Install minimal OS packages for Composer fallbacks
RUN apk add --no-cache \
    zip \
    unzip

# Install php extensions (zip needed for Composer to download dist packages, opcache and pdo are already installed)
RUN install-php-extensions \
    apcu \
    zip \
    pdo_pgsql \
    intl \
    mbstring \
    pcntl \
    pcov

# Install Composer
COPY --from=composer:2.9.8 /usr/bin/composer /usr/bin/composer

# Define the default user for the container
ARG USER=appuser

# Create and set permissions for the default user
RUN \
    adduser -D -g '' $USER && \
    chown -R $USER:$USER /config/caddy /data/caddy /app

# Switch to the default user for subsequent commands
USER $USER

# If you wan't to use HTTPS only on port 80 (not recommended), uncomment the following line:
ENV SERVER_NAME=:80

# Copy Caddy configuration
COPY --chown=appuser:appuser docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile

# Set the working directory
WORKDIR /app

# Copy composer files first for better caching
COPY --chown=appuser:appuser ./composer.json ./composer.lock* ./

FROM base AS development

ARG APP_ENV=dev
ENV APP_ENV=${APP_ENV}

RUN composer install \
    --no-interaction \
    --no-plugins \
    --no-scripts \
    --prefer-dist

# Copy application files
COPY --chown=appuser:appuser . ./

FROM base AS prod

ARG APP_ENV=prod
ENV APP_ENV=${APP_ENV}

RUN composer install \
    --no-interaction \
    --no-plugins \
    --no-scripts \
    --no-dev \
    --prefer-dist

# Copy application files
COPY --chown=appuser:appuser . ./

# Warm prod cache without writing to root-owned /usr/local/bin
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative --apcu \
    && php bin/console cache:clear --env=prod \
    && php bin/console cache:warmup --env=prod \
    && php bin/console assets:install --env=prod --no-debug

# Note: FrankenPHP manages user privileges internally; explicit USER breaks Caddy PKI

# FrankenPHP configuration
ENV FRANKENPHP_CONFIG="worker ./public/index.php"

EXPOSE 80
