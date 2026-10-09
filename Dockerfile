FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev

FROM php:8.3-fpm-alpine AS runtime
RUN apk add --no-cache nginx supervisor \
      sqlite sqlite-dev \
      icu-libs icu-dev \
      oniguruma-dev \
      libzip-dev \
      freetype-dev libjpeg-turbo-dev libpng-dev \
      ghostscript \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" \
      pdo_mysql pdo_sqlite \
      bcmath intl opcache zip gd \
 && apk del sqlite-dev icu-dev oniguruma-dev libzip-dev \
      freetype-dev libjpeg-turbo-dev libpng-dev
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
 && chmod +x /usr/local/bin/entrypoint
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]