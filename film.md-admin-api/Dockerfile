FROM dunglas/frankenphp:1-php8.3-bookworm

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev postgresql-client $PHPIZE_DEPS \
    && docker-php-ext-install bcmath pcntl pdo_pgsql pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint

RUN composer install --no-interaction --prefer-dist --optimize-autoloader \
    && composer require laravel/octane --no-interaction \
    && composer dump-autoload --optimize

RUN chmod +x /usr/local/bin/entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000

ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000", "--workers=4", "--max-requests=500"]
