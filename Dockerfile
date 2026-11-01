# RecordPlus production image for Dokploy Application deploys.
# Persist uploads via Advanced → Mounts → named volume at:
#   /var/www/html/storage/app/private

FROM node:22-bookworm AS frontend

WORKDIR /app

COPY frontend/package.json frontend/package-lock.json ./frontend/
RUN npm --prefix frontend ci

COPY frontend ./frontend
COPY public ./public

RUN npm --prefix frontend run build


FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-scripts \
    --no-autoloader

COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY routes ./routes
COPY artisan ./

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev


FROM php:8.4-fpm-bookworm AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        git \
        unzip \
        curl \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libonig-dev \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        bcmath \
        exif \
        pcntl \
        intl \
        zip \
        opcache \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/99-recordplus.ini
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && rm -f /etc/nginx/sites-enabled/default \
    && printf 'server_tokens off;\n' > /etc/nginx/conf.d/zz-security.conf \
    && mkdir -p /var/log/supervisor \
    && sed -i 's|^listen = .*|listen = 127.0.0.1:9000|' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's|^;clear_env = no|clear_env = no|' /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

COPY --chown=www-data:www-data artisan composer.json composer.lock ./
COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data bootstrap ./bootstrap
COPY --chown=www-data:www-data config ./config
COPY --chown=www-data:www-data database ./database
COPY --chown=www-data:www-data routes ./routes
COPY --chown=www-data:www-data resources ./resources
COPY --chown=www-data:www-data public ./public
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=frontend --chown=www-data:www-data /app/public ./public

RUN mkdir -p \
      storage/app/private \
      storage/app/public \
      storage/framework/cache/data \
      storage/framework/sessions \
      storage/framework/views \
      storage/logs \
      bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
