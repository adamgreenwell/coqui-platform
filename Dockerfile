# Stage 1: Install PHP dependencies
FROM composer:2 AS composer-deps

WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts \
    --ignore-platform-req=ext-pcntl \
    --ignore-platform-req=ext-bcmath \
    --ignore-platform-req=ext-redis \
    --ignore-platform-req=ext-pgsql

# Stage 2: Build frontend assets
FROM node:22-bookworm-slim AS frontend

WORKDIR /app
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi
COPY . ./
RUN npm run build

# Stage 3: Production PHP 8.4-FPM runtime
FROM php:8.4-fpm

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    git \
    libfreetype6-dev \
    libicu-dev \
    libjpeg62-turbo-dev \
    libonig-dev \
    libpng-dev \
    libpq-dev \
    libxml2-dev \
    libzip-dev \
    unzip \
    zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_pgsql pgsql mbstring exif pcntl bcmath gd intl zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . /var/www/html
COPY --from=composer-deps /app/vendor /var/www/html/vendor
COPY --from=frontend /app/public/build /tmp/build-assets

COPY public/ /tmp/public-template/
RUN rm -rf /tmp/public-template/build /tmp/public-template/storage

COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/framework/{sessions,views,cache} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
