#!/bin/sh

set -e

echo "Waiting for PostgreSQL at ${DB_HOST}:${DB_PORT:-5432}..."
until PGPASSWORD="${DB_PASSWORD}" pg_isready -h "${DB_HOST}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME}" -d "${DB_DATABASE}"; do
    sleep 2
done

php artisan optimize:clear

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

if [ "${APP_ENV:-local}" = "production" ]; then
    php artisan optimize
fi

exec "$@"
