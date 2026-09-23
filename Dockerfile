# SINERGIA BOT ML — imagem única (web; futuramente também worker/scheduler com outro comando).
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --optimize-autoloader

FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev unzip \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers \
    && sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php/sinergia.ini $PHP_INI_DIR/conf.d/zz-sinergia.ini
COPY docker/apache/sinergia.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/app
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN mkdir -p storage/logs storage/cache storage/validation \
    && chown -R www-data:www-data storage \
    && rm -f .env

USER www-data
EXPOSE 8080
CMD ["apache2-foreground"]
