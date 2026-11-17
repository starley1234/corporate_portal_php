# =============================================================================
# PHP 8.4 (FPM) для PRX
# Сборка: docker compose build php
# Включает: pgsql, pdo_pgsql, mbstring, intl, zip, gd, bcmath, sockets, pcntl,
#           opcache, composer 2
# =============================================================================

FROM php:8.4-fpm-bookworm AS base

# Системные зависимости для расширений
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libxml2-dev \
        git \
        unzip \
    && rm -rf /var/lib/apt/lists/*

# Расширения PHP
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pgsql \
        pdo_pgsql \
        mbstring \
        intl \
        zip \
        gd \
        bcmath \
        sockets \
        pcntl \
        opcache

# Composer 2 из официального образа
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Настройки PHP (dev-ориентированные, Production-профиль включается KOHANA_ENV=production)
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-prx.ini

# Зависимости composer'а кэшируем отдельным слоем:
# меняется только composer.json -> пересборка зависимостей.
# (vendor монтируется из образа в контейнер, хостовый vendor не перезаписывается)
COPY composer.json /var/www/composer.json
RUN cd /var/www \
    && composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    || composer install --optimize-autoloader --no-interaction --no-progress

# Код проекта монтируется volume'ом в /var/www (см. docker-compose.yml).
# Точка входа — штатный php-fpm.
WORKDIR /var/www
