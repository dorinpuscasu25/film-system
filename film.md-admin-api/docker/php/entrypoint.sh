#!/bin/sh

set -e

backup_database_before_migrations() {
    backup_dir="${DATABASE_BACKUP_DIR:-/var/backups/postgres}"
    retention_days="${DATABASE_BACKUP_RETENTION_DAYS:-14}"
    timestamp="$(date -u '+%Y%m%dT%H%M%SZ')"
    partial_path="${backup_dir}/pre-migration-${timestamp}.dump.partial"
    backup_path="${partial_path%.partial}"

    case "${DB_CONNECTION:-pgsql}" in
        pgsql|postgres|postgresql) ;;
        *)
            echo "Automatic pre-migration backup only supports PostgreSQL." >&2
            return 1
            ;;
    esac

    mkdir -p "$backup_dir"
    umask 077

    echo "Creating required pre-migration PostgreSQL backup..."
    if ! PGPASSWORD="${DB_PASSWORD}" pg_dump \
        --host="${DB_HOST}" \
        --port="${DB_PORT:-5432}" \
        --username="${DB_USERNAME}" \
        --format=custom \
        --no-owner \
        --no-privileges \
        --file="$partial_path" \
        "${DB_DATABASE}"; then
        rm -f "$partial_path"
        echo "Pre-migration backup failed; deployment migrations were aborted." >&2
        return 1
    fi

    if ! pg_restore --list "$partial_path" >/dev/null; then
        rm -f "$partial_path"
        echo "Pre-migration backup verification failed; deployment migrations were aborted." >&2
        return 1
    fi

    mv "$partial_path" "$backup_path"
    echo "Verified pre-migration backup: ${backup_path}"

    case "$retention_days" in
        ''|*[!0-9]*)
            echo "DATABASE_BACKUP_RETENTION_DAYS is invalid; old backups were not removed." >&2
            ;;
        *)
            find "$backup_dir" -type f -name 'pre-migration-*.dump' -mtime "+${retention_days}" -delete
            ;;
    esac
}

echo "Waiting for PostgreSQL at ${DB_HOST}:${DB_PORT:-5432}..."
until PGPASSWORD="${DB_PASSWORD}" pg_isready -h "${DB_HOST}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME}" -d "${DB_DATABASE}"; do
    sleep 2
done

php artisan optimize:clear

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    if [ "${APP_ENV:-local}" = "production" ]; then
        backup_database_before_migrations
    fi

    php artisan migrate --force
fi

if [ "${APP_ENV:-local}" = "production" ]; then
    php artisan optimize
fi

exec "$@"
