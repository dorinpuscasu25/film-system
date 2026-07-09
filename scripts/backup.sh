#!/usr/bin/env bash
set -Eeuo pipefail

IFS=$'\n\t'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="${PROJECT_ROOT:-$(cd "${SCRIPT_DIR}/.." && pwd)}"
API_DIR="${API_DIR:-${PROJECT_ROOT}/film.md-admin-api}"
ENV_FILE="${ENV_FILE:-${API_DIR}/.env}"
BACKUP_ROOT="${BACKUP_ROOT:-${PROJECT_ROOT}/backups}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
BACKUP_RCLONE_DEST="${BACKUP_RCLONE_DEST:-}"
BACKUP_SYNC_MODE="${BACKUP_SYNC_MODE:-copy}"
BACKUP_INCLUDE_ENV="${BACKUP_INCLUDE_ENV:-true}"
BACKUP_INCLUDE_DB="${BACKUP_INCLUDE_DB:-true}"
BACKUP_INCLUDE_STORAGE="${BACKUP_INCLUDE_STORAGE:-true}"
BACKUP_INCLUDE_REDIS="${BACKUP_INCLUDE_REDIS:-true}"
BACKUP_INCLUDE_MEILI="${BACKUP_INCLUDE_MEILI:-true}"
BACKUP_INCLUDE_EXTRA_RCLONE_SOURCE="${BACKUP_INCLUDE_EXTRA_RCLONE_SOURCE:-true}"
BACKUP_EXTRA_RCLONE_SOURCE="${BACKUP_EXTRA_RCLONE_SOURCE:-}"

timestamp="$(date +%Y%m%d-%H%M%S)"
backup_name="film-md-${timestamp}"
backup_dir="${BACKUP_ROOT}/${backup_name}"

usage() {
  cat <<'EOF'
Usage:
  scripts/backup.sh

Environment variables:
  BACKUP_ROOT=/path/to/backups
      Local folder where timestamped backups are saved. Default: ./backups

  BACKUP_RCLONE_DEST=gdrive:film-md-backups
      Optional Google Drive destination configured in rclone. If empty, sync is skipped.

  BACKUP_SYNC_MODE=copy|sync
      copy keeps existing remote backups; sync mirrors the local BACKUP_ROOT. Default: copy

  BACKUP_RETENTION_DAYS=14
      Deletes local backup folders older than this many days after a successful backup.

  BACKUP_EXTRA_RCLONE_SOURCE=remote:path
      Optional rclone source to download into the backup, useful for S3/Bunny-compatible storage.

Examples:
  BACKUP_RCLONE_DEST="gdrive:film-md-backups" scripts/backup.sh
  BACKUP_ROOT="$HOME/film-md-backups" BACKUP_SYNC_MODE=sync BACKUP_RCLONE_DEST="gdrive:film-md-backups" scripts/backup.sh
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

log() {
  printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

warn() {
  printf '[%s] WARNING: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2
}

fail() {
  printf '[%s] ERROR: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2
  exit 1
}

is_true() {
  case "${1:-}" in
    1|true|TRUE|yes|YES|y|Y) return 0 ;;
    *) return 1 ;;
  esac
}

env_value() {
  local key="$1"
  local default="${2:-}"
  local line value

  if [[ ! -f "$ENV_FILE" ]]; then
    printf '%s' "$default"
    return
  fi

  line="$(grep -E "^[[:space:]]*${key}=" "$ENV_FILE" | tail -n 1 || true)"
  if [[ -z "$line" ]]; then
    printf '%s' "$default"
    return
  fi

  value="${line#*=}"
  value="${value%$'\r'}"
  value="${value%%[[:space:]]#*}"

  if [[ "$value" == \"*\" && "$value" == *\" ]]; then
    value="${value:1:${#value}-2}"
  elif [[ "$value" == \'*\' && "$value" == *\' ]]; then
    value="${value:1:${#value}-2}"
  fi

  printf '%s' "$value"
}

nullable_env_value() {
  local value
  value="$(env_value "$1" "${2:-}")"
  if [[ "$value" == "null" || "$value" == "NULL" ]]; then
    value=""
  fi
  printf '%s' "$value"
}

need_command() {
  command -v "$1" >/dev/null 2>&1 || fail "Missing required command: $1"
}

compose() {
  (cd "$API_DIR" && docker compose "$@")
}

compose_service_id() {
  local service="$1"
  if ! command -v docker >/dev/null 2>&1; then
    return 1
  fi
  compose ps -q "$service" 2>/dev/null | head -n 1
}

make_dirs() {
  mkdir -p \
    "$backup_dir/databases" \
    "$backup_dir/files" \
    "$backup_dir/services" \
    "$backup_dir/remote-storage"
}

write_manifest() {
  {
    echo "backup_name=${backup_name}"
    echo "created_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
    echo "project_root=${PROJECT_ROOT}"
    echo "api_dir=${API_DIR}"
    echo "env_file=${ENV_FILE}"
    echo "host=$(hostname)"
    echo "user=$(id -un)"
    echo "git_commit=$(git -C "$PROJECT_ROOT" rev-parse HEAD 2>/dev/null || true)"
  } > "${backup_dir}/MANIFEST.txt"
}

dump_pg_local() {
  local host="$1" port="$2" database="$3" username="$4" password="$5" output="$6"
  command -v pg_dump >/dev/null 2>&1 || return 1

  log "Dump Postgres local: ${database}"
  PGPASSWORD="$password" pg_dump \
    --host="$host" \
    --port="$port" \
    --username="$username" \
    --format=plain \
    --no-owner \
    --no-privileges \
    "$database" \
    | gzip -9 > "$output"
}

dump_pg_docker() {
  local database="$1" username="$2" password="$3" output="$4"
  local container_id
  container_id="$(compose_service_id postgres || true)"
  [[ -n "$container_id" ]] || return 1

  log "Dump Postgres Docker: ${database}"
  docker exec -e PGPASSWORD="$password" "$container_id" pg_dump \
    --host=127.0.0.1 \
    --port=5432 \
    --username="$username" \
    --format=plain \
    --no-owner \
    --no-privileges \
    "$database" \
    | gzip -9 > "$output"
}

dump_mysql_local() {
  local host="$1" port="$2" database="$3" username="$4" password="$5" output="$6"
  command -v mysqldump >/dev/null 2>&1 || return 1

  log "Dump MySQL/MariaDB local: ${database}"
  MYSQL_PWD="$password" mysqldump \
    --host="$host" \
    --port="$port" \
    --user="$username" \
    --single-transaction \
    --routines \
    --triggers \
    "$database" \
    | gzip -9 > "$output"
}

dump_sqlite() {
  local database="$1" output="$2"

  [[ -f "$database" ]] || return 1
  log "Backup SQLite file: ${database}"
  gzip -c "$database" > "$output"
}

dump_database() {
  local label="$1" prefix="$2"
  local connection host port database username password output

  connection="$(env_value "${prefix}_CONNECTION" "$(env_value DB_CONNECTION sqlite)")"
  database="$(env_value "${prefix}_DATABASE" "")"

  if [[ "$prefix" == "DB" ]]; then
    host="$(env_value DB_HOST 127.0.0.1)"
    port="$(env_value DB_PORT 5432)"
    username="$(env_value DB_USERNAME root)"
    password="$(nullable_env_value DB_PASSWORD "")"
  else
    host="$(env_value "${prefix}_HOST" "$(env_value DB_HOST 127.0.0.1)")"
    port="$(env_value "${prefix}_PORT" "$(env_value DB_PORT 5432)")"
    username="$(env_value "${prefix}_USERNAME" "$(env_value DB_USERNAME root)")"
    password="$(nullable_env_value "${prefix}_PASSWORD" "$(nullable_env_value DB_PASSWORD "")")"
  fi

  [[ -n "$database" ]] || {
    warn "Skipping ${label}: database name is not configured."
    return 0
  }

  output="${backup_dir}/databases/${label}-${database}.sql.gz"

  case "$connection" in
    pgsql|postgres|postgresql)
      if dump_pg_local "$host" "$port" "$database" "$username" "$password" "$output"; then
        return 0
      fi
      rm -f "$output"
      if dump_pg_docker "$database" "$username" "$password" "$output"; then
        return 0
      fi
      rm -f "$output"
      fail "Could not dump Postgres database '${database}'. Install pg_dump or run the Docker postgres service."
      ;;
    mysql|mariadb)
      if dump_mysql_local "$host" "$port" "$database" "$username" "$password" "$output"; then
        return 0
      fi
      rm -f "$output"
      fail "Could not dump MySQL/MariaDB database '${database}'. Install mysqldump."
      ;;
    sqlite)
      if [[ "$database" != /* ]]; then
        database="${API_DIR}/${database}"
      fi
      output="${backup_dir}/databases/${label}-sqlite.db.gz"
      if dump_sqlite "$database" "$output"; then
        return 0
      fi
      rm -f "$output"
      fail "Could not backup SQLite database '${database}'."
      ;;
    *)
      fail "Unsupported database connection '${connection}' for ${label}."
      ;;
  esac
}

backup_databases() {
  is_true "$BACKUP_INCLUDE_DB" || return 0

  dump_database "main" "DB"

  local analytics_connection analytics_database main_database
  analytics_connection="$(env_value ANALYTICS_DB_CONNECTION "")"
  analytics_database="$(env_value ANALYTICS_DB_DATABASE "")"
  main_database="$(env_value DB_DATABASE "")"

  if [[ -n "$analytics_connection" && -n "$analytics_database" && "$analytics_database" != "$main_database" ]]; then
    dump_database "analytics" "ANALYTICS_DB"
  fi
}

backup_storage() {
  is_true "$BACKUP_INCLUDE_STORAGE" || return 0

  local paths=()
  [[ -d "${API_DIR}/storage/app" ]] && paths+=("storage/app")
  [[ -d "${API_DIR}/public/storage" ]] && paths+=("public/storage")

  if [[ "${#paths[@]}" -eq 0 ]]; then
    warn "No Laravel storage paths found."
    return 0
  fi

  log "Archive Laravel storage"
  tar -C "$API_DIR" -czf "${backup_dir}/files/laravel-storage.tar.gz" "${paths[@]}"
}

backup_env_files() {
  is_true "$BACKUP_INCLUDE_ENV" || return 0

  local files=()
  local rel file

  while IFS= read -r -d '' file; do
    rel="${file#${PROJECT_ROOT}/}"
    files+=("$rel")
  done < <(find "$PROJECT_ROOT" \
    -path '*/node_modules' -prune -o \
    -path '*/vendor' -prune -o \
    -path "${BACKUP_ROOT}" -prune -o \
    -name '.env' -type f -print0)

  if [[ "${#files[@]}" -eq 0 ]]; then
    warn "No .env files found."
    return 0
  fi

  log "Archive .env files"
  tar -C "$PROJECT_ROOT" -czf "${backup_dir}/files/env-files.tar.gz" "${files[@]}"
}

backup_redis() {
  is_true "$BACKUP_INCLUDE_REDIS" || return 0
  command -v docker >/dev/null 2>&1 || {
    warn "Docker not available; skipping Redis dump."
    return 0
  }

  local container_id password args=()
  container_id="$(compose_service_id redis || true)"
  [[ -n "$container_id" ]] || {
    warn "Redis service is not running; skipping Redis dump."
    return 0
  }

  password="$(nullable_env_value REDIS_PASSWORD "")"
  if [[ -n "$password" ]]; then
    args=(-a "$password")
  fi

  log "Dump Redis RDB"
  docker exec "$container_id" redis-cli "${args[@]}" SAVE >/dev/null
  docker cp "${container_id}:/data/dump.rdb" "${backup_dir}/services/redis-dump.rdb"
  gzip -9 "${backup_dir}/services/redis-dump.rdb"
}

backup_meilisearch() {
  is_true "$BACKUP_INCLUDE_MEILI" || return 0
  command -v docker >/dev/null 2>&1 || {
    warn "Docker not available; skipping Meilisearch data archive."
    return 0
  }

  local container_id
  container_id="$(compose_service_id meilisearch || true)"
  [[ -n "$container_id" ]] || {
    warn "Meilisearch service is not running; skipping Meilisearch archive."
    return 0
  }

  log "Archive Meilisearch data"
  docker exec "$container_id" tar -C /meili_data -czf - . > "${backup_dir}/services/meilisearch-data.tar.gz"
}

backup_extra_rclone_source() {
  is_true "$BACKUP_INCLUDE_EXTRA_RCLONE_SOURCE" || return 0
  [[ -n "$BACKUP_EXTRA_RCLONE_SOURCE" ]] || return 0

  need_command rclone
  log "Copy extra rclone source: ${BACKUP_EXTRA_RCLONE_SOURCE}"
  rclone copy "$BACKUP_EXTRA_RCLONE_SOURCE" "${backup_dir}/remote-storage/extra" --create-empty-src-dirs
}

write_checksums() {
  log "Write checksums"
  (
    cd "$backup_dir"
    find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 shasum -a 256 > SHA256SUMS
  )
}

apply_retention() {
  [[ "$BACKUP_RETENTION_DAYS" =~ ^[0-9]+$ ]] || {
    warn "BACKUP_RETENTION_DAYS is not numeric; skipping retention."
    return 0
  }

  log "Apply local retention: ${BACKUP_RETENTION_DAYS} days"
  find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -name 'film-md-*' -mtime +"$BACKUP_RETENTION_DAYS" -print -exec rm -rf {} +
}

sync_to_google_drive() {
  [[ -n "$BACKUP_RCLONE_DEST" ]] || {
    log "BACKUP_RCLONE_DEST is empty; skipping Google Drive sync."
    return 0
  }

  need_command rclone

  case "$BACKUP_SYNC_MODE" in
    copy)
      log "Copy backups to ${BACKUP_RCLONE_DEST}"
      rclone copy "$BACKUP_ROOT" "$BACKUP_RCLONE_DEST" --create-empty-src-dirs
      ;;
    sync)
      log "Sync backups to ${BACKUP_RCLONE_DEST}"
      rclone sync "$BACKUP_ROOT" "$BACKUP_RCLONE_DEST" --create-empty-src-dirs
      ;;
    *)
      fail "Unsupported BACKUP_SYNC_MODE='${BACKUP_SYNC_MODE}'. Use copy or sync."
      ;;
  esac
}

main() {
  [[ -d "$API_DIR" ]] || fail "API_DIR does not exist: ${API_DIR}"
  [[ -f "$ENV_FILE" ]] || fail "ENV_FILE does not exist: ${ENV_FILE}"

  need_command gzip
  need_command tar
  need_command shasum

  mkdir -p "$BACKUP_ROOT"
  make_dirs
  write_manifest

  backup_databases
  backup_storage
  backup_env_files
  backup_redis
  backup_meilisearch
  backup_extra_rclone_source
  write_checksums
  apply_retention
  sync_to_google_drive

  log "Backup complete: ${backup_dir}"
}

main "$@"
