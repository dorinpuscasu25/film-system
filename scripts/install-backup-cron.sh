#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="${PROJECT_ROOT:-$(cd "${SCRIPT_DIR}/.." && pwd)}"
BACKUP_CRON_SCHEDULE="${BACKUP_CRON_SCHEDULE:-0 3 * * *}"
BACKUP_ROOT="${BACKUP_ROOT:-${HOME}/film-md-backups}"
BACKUP_RCLONE_DEST="${BACKUP_RCLONE_DEST:-dorin-gdrive:film-md-backups}"
BACKUP_SYNC_MODE="${BACKUP_SYNC_MODE:-copy}"
BACKUP_LOG="${BACKUP_LOG:-${HOME}/film-md-backup.log}"
CRON_BEGIN="# BEGIN film-md nightly backup"
CRON_END="# END film-md nightly backup"

usage() {
  cat <<'EOF'
Usage:
  scripts/install-backup-cron.sh

Installs a nightly cron job for scripts/backup.sh.

Environment variables:
  BACKUP_CRON_SCHEDULE="0 3 * * *"
      Cron schedule. Default: every night at 03:00.

  BACKUP_ROOT="$HOME/film-md-backups"
      Local backup folder.

  BACKUP_RCLONE_DEST="dorin-gdrive:film-md-backups"
      Google Drive rclone destination.

  BACKUP_SYNC_MODE="copy"
      copy keeps remote history; sync mirrors the local backup folder.

  BACKUP_LOG="$HOME/film-md-backup.log"
      Cron log file.

Examples:
  scripts/install-backup-cron.sh
  BACKUP_CRON_SCHEDULE="30 2 * * *" scripts/install-backup-cron.sh
  BACKUP_RCLONE_DEST="mydrive:film-md-backups" scripts/install-backup-cron.sh
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

quote_sh() {
  printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || {
    printf 'Missing required command: %s\n' "$1" >&2
    exit 1
  }
}

require_command crontab
require_command awk
require_command mktemp

backup_script="${PROJECT_ROOT}/scripts/backup.sh"
[[ -x "$backup_script" ]] || chmod +x "$backup_script"

path_value="/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin"
cron_command="cd $(quote_sh "$PROJECT_ROOT") && PATH=$(quote_sh "$path_value") BACKUP_ROOT=$(quote_sh "$BACKUP_ROOT") BACKUP_RCLONE_DEST=$(quote_sh "$BACKUP_RCLONE_DEST") BACKUP_SYNC_MODE=$(quote_sh "$BACKUP_SYNC_MODE") $(quote_sh "$backup_script") >> $(quote_sh "$BACKUP_LOG") 2>&1"
cron_entry="${BACKUP_CRON_SCHEDULE} ${cron_command}"

tmp_file="$(mktemp)"
trap 'rm -f "$tmp_file"' EXIT

existing_cron="$(crontab -l 2>/dev/null || true)"

printf '%s\n' "$existing_cron" \
  | awk -v begin="$CRON_BEGIN" -v end="$CRON_END" '
      $0 == begin { skip = 1; next }
      $0 == end { skip = 0; next }
      skip != 1 && $0 != "" { print }
    ' > "$tmp_file"

{
  printf '%s\n' "$CRON_BEGIN"
  printf '%s\n' "$cron_entry"
  printf '%s\n' "$CRON_END"
} >> "$tmp_file"

crontab "$tmp_file"

printf 'Installed nightly backup cron job:\n%s\n' "$cron_entry"
printf 'Log file: %s\n' "$BACKUP_LOG"
