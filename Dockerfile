FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev

FROM php:8.3-fpm-alpine AS runtime
# libzip/freetype/jpeg/png -dev are build-time only; their runtime libs
# (libzip, freetype, libjpeg, libpng) are pulled in as dependencies of the
# extension packages themselves and must survive the apk del below.
RUN apk add --no-cache nginx supervisor \
      sqlite \
      icu-libs \
      oniguruma \
      libzip \
      freetype libjpeg-turbo libpng \
      ghostscript \
 && apk add --no-cache --virtual .build-deps \
      sqlite-dev icu-dev oniguruma-dev libzip-dev \
      freetype-dev libjpeg-turbo-dev libpng-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" \
      pdo_mysql pdo_sqlite \
      bcmath intl opcache zip gd \
 && apk del .build-deps \
 && ldconfig \
 && php -r 'foreach (["pdo_mysql","pdo_sqlite","gd","zip","bcmath","intl","opcache","fileinfo"] as $e) { printf("%-12s %s\n", $e, extension_loaded($e) ? "loaded" : "MISSING"); }'
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