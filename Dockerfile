# syntax=docker/dockerfile:1

# Shared PHP runtime: extensions are compiled once for build and production.
FROM php:8.5-apache-trixie AS base
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ffmpeg gosu libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql pcntl \
    && apt-mark manual libfreetype6 libjpeg62-turbo libpng16-16t64 libwebp7 \
    && apt-get purge -y --auto-remove \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && rm -rf /var/lib/apt/lists/* \
    && php -r 'foreach (["curl", "openssl", "sodium", "json", "pdo", "pdo_mysql", "mbstring", "fileinfo", "simplexml", "gd", "pcntl"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing extension: $extension\n"); exit(1); } }'
WORKDIR /var/www/html

# Build production dependencies; these tools never enter the final image.
FROM base AS build
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY app/ app/
COPY database/ database/
RUN composer dump-autoload --no-dev --no-scripts --optimize --no-interaction \
    && composer check-platform-reqs --no-dev

# Assemble the distributable image with production defaults and no instance data.
FROM base AS production
ENV APP_NAME=Openbook APP_ENV=production APP_DEBUG=false APP_TIMEZONE=UTC \
    LOG_CHANNEL=stderr LOG_LEVEL=info \
    DB_CONNECTION=mysql DB_PORT=3306 \
    SESSION_DRIVER=file SESSION_SECURE_COOKIE=true \
    CACHE_STORE=file QUEUE_CONNECTION=database \
    MAIL_MAILER=log OPENBOOK_WEB_CRON_ENABLED=false OPENBOOK_REGISTRATION_OPEN=false \
    XDG_CONFIG_HOME=/tmp/openbook-config XDG_DATA_HOME=/tmp/openbook-data

COPY . .
COPY --from=build /var/www/html/vendor/ vendor/
COPY docker/php.ini /usr/local/etc/php/conf.d/openbook.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/prepare.php /usr/local/lib/openbook/prepare.php
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/openbook-entrypoint
COPY --chmod=755 docker/cron.sh /usr/local/bin/openbook-cron
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && a2enmod rewrite \
    && mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs storage/app/public storage/app/private \
    && ln -s /var/www/html/storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache

ARG VCS_REF=unknown
LABEL org.opencontainers.image.source="https://github.com/skeyby/openbook" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later" \
      org.opencontainers.image.revision="${VCS_REF}"

EXPOSE 80
# CLI services need SIGTERM; Compose selects Apache graceful shutdown for web.
STOPSIGNAL SIGTERM
ENTRYPOINT ["openbook-entrypoint"]
CMD ["apache2-foreground"]
