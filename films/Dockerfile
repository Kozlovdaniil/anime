# syntax=docker/dockerfile:1
FROM composer:2 AS composer

FROM php:8.4-fpm

# Extensions: pdo_pgsql (PostgreSQL), mbstring, opcache
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libonig-dev libzip-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql mbstring zip opcache \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

# OPcache tuning for dev
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=1'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

COPY --from=composer /usr/bin/composer /usr/bin/composer

# php-fpm pool runs as the host user so mounted files in var/ stay writable
ARG APP_USER=daikzlex
ARG APP_UID=1000
ARG APP_GID=1000
RUN groupadd -g ${APP_GID} ${APP_USER} 2>/dev/null || true \
    && useradd -m -u ${APP_UID} -g ${APP_GID} -s /bin/sh ${APP_USER} 2>/dev/null || true \
    && sed -i "s/^user = .*/user = ${APP_USER}/" /usr/local/etc/php-fpm.d/www.conf \
    && sed -i "s/^group = .*/group = ${APP_USER}/" /usr/local/etc/php-fpm.d/www.conf

WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --no-autoloader --ignore-platform-req=ext-xml || true

COPY . .
RUN composer dump-autoload --optimize --no-scripts

EXPOSE 9000
CMD ["php-fpm"]
