#!/bin/sh

set -e

ANALYTICS_DB_NAME="${ANALYTICS_DB_DATABASE:-film_md_analytics}"

echo "Ensuring analytics database exists: ${ANALYTICS_DB_NAME}"

psql -v ON_ERROR_STOP=1 --username "${POSTGRES_USER}" --dbname "${POSTGRES_DB}" <<-EOSQL
SELECT 'CREATE DATABASE "${ANALYTICS_DB_NAME}"'
WHERE NOT EXISTS (
    SELECT FROM pg_database WHERE datname = '${ANALYTICS_DB_NAME}'
)\gexec
EOSQL
